<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Lifecycle of the CLIENT span of one logical Symfony HttpClient request.
 *
 * @internal
 */
final class HttpClientRequestState
{
    private bool $ended = false;
    private int $depth = 0;
    private int $callStart = 0;
    private ?int $statusCode = null;
    private int $downloaded = -1;
    /** Number of retries of this request observed as nested request calls, e.g. by RetryableHttpClient. */
    private int $resends = 0;
    /** Epoch nanos of the first on_progress call, i.e. when the transport started the transfer. */
    private ?int $firstActivity = null;
    /** Epoch nanos of the first on_progress call with a status code. */
    private ?int $headersAt = null;
    /** Epoch nanos of the last progress (status or body bytes) reported by the transport. */
    private ?int $lastActivity = null;
    /** Epoch nanos when the span was started. */
    private int $spanStart;
    /** Base attributes of the http.client.request.duration metric, captured at request time. */
    private array $metricAttributes;

    public function __construct(private readonly SpanInterface $span, string $method, string $url)
    {
        $this->spanStart = Clock::getDefault()->now();

        $parts = parse_url($url) ?: [];
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : null;
        $host = isset($parts['host']) ? (string) $parts['host'] : null;
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : null));

        $metricAttributes = [HttpAttributes::HTTP_REQUEST_METHOD => $method !== '' ? $method : '_OTHER'];
        if ($host !== null && $host !== '') {
            $metricAttributes[ServerAttributes::SERVER_ADDRESS] = $host;
        }
        if ($port !== null) {
            $metricAttributes[ServerAttributes::SERVER_PORT] = $port;
        }
        if ($scheme !== null && $scheme !== '') {
            $metricAttributes[UrlAttributes::URL_SCHEME] = $scheme;
        }
        $this->metricAttributes = $metricAttributes;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function lastActivity(): ?int
    {
        return $this->lastActivity;
    }

    public function setStatusCode(int $statusCode): void
    {
        if ($statusCode > 0) {
            $this->statusCode = $statusCode;
        }
    }

    /**
     * Called from the on_progress option. Only observes the transfer, never ends the span.
     */
    public function onProgress(int $downloaded, array $info): void
    {
        $statusCode = $info['http_code'] ?? 0;
        $this->firstActivity ??= Clock::getDefault()->now();

        if (!is_int($statusCode) || $statusCode <= 0) {
            return;
        }

        $this->headersAt ??= Clock::getDefault()->now();

        if ($statusCode !== $this->statusCode || $downloaded !== $this->downloaded) {
            $this->statusCode = $statusCode;
            $this->downloaded = $downloaded;
            $this->lastActivity = Clock::getDefault()->now();
        }
    }

    /**
     * A request call nested inside an already-instrumented request is either a pass-through
     * decorator synchronously calling its wrapped client, or a retry of the same request
     * (e.g. by RetryableHttpClient). Pass-through decorators call the wrapped client before
     * any transport activity, so a nested call observed after the transfer started is counted
     * as a resend.
     */
    public function onForwardedRequest(): void
    {
        if ($this->firstActivity !== null) {
            $this->resends++;
        }
    }

    public function enterResponseCall(): void
    {
        if ($this->depth++ === 0) {
            $this->callStart = Clock::getDefault()->now();
        }
    }

    /**
     * Whether the outermost response method call just returned.
     */
    public function leaveResponseCall(): bool
    {
        return $this->depth > 0 && --$this->depth === 0;
    }

    /**
     * Whether the transport had already finished receiving the response before the
     * current outermost response method call started, e.g. because consuming
     * another response drove this transfer to completion.
     */
    public function completedBeforeCall(): bool
    {
        return $this->lastActivity !== null && $this->lastActivity < $this->callStart;
    }

    /**
     * Ends the span exactly once, then records the http.client.request.duration
     * histogram. The duration comes from the transport's own getInfo('total_time'),
     * so it measures the actual network exchange and excludes the lazy queue time
     * between request() and the transfer starting.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpclientrequestduration
     */
    public function finish(?ResponseInterface $response, ?\Throwable $exception = null, ?int $timestamp = null, ?HistogramInterface $durationHistogram = null): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        $info = self::responseInfo($response);
        $statusCode = $info['http_code'] ?? null;
        $statusCode = is_int($statusCode) && $statusCode > 0 ? $statusCode : $this->statusCode;

        if ($statusCode !== null) {
            $this->span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
        }
        if ($this->resends > 0) {
            $this->span->setAttribute(HttpAttributes::HTTP_REQUEST_RESEND_COUNT, $this->resends);
        }

        if ($this->firstActivity !== null) {
            // When the transport started and finished the transfer, so queue time
            // (request() -> first progress) is visible in the trace.
            $this->span->addEvent('http.getconn', [], $this->firstActivity);
        }

        // Transport timings reported by Symfony getInfo(), anchored to the span start so they
        // show when the network work actually happened relative to request().
        if (isset($info['namelookup_time']) && $info['namelookup_time'] > 0) {
            $this->span->addEvent('http.dns', [], $this->nanoOffset($info['namelookup_time']));
        }
        if (isset($info['connect_time']) && $info['connect_time'] > 0) {
            $this->span->addEvent('http.connect', [], $this->nanoOffset($info['connect_time']));
        }
        if (isset($info['appconnect_time']) && $info['appconnect_time'] > 0) {
            // TLS handshake completed (HTTPS only).
            $this->span->addEvent('http.tls', [], $this->nanoOffset($info['appconnect_time']));
        }
        if (isset($info['pretransfer_time']) && $info['pretransfer_time'] > 0) {
            $this->span->addEvent('http.send', [], $this->nanoOffset($info['pretransfer_time']));
        }
        if ($this->headersAt !== null) {
            $this->span->addEvent('http.headers', [], $this->headersAt);
        }
        if ($this->lastActivity !== null) {
            $this->span->addEvent('http.receive', [], $this->lastActivity);
        }

        // Final transport snapshot as attributes.
        self::setIfPresent($this->span, 'http.response.content_type', $info['content_type'] ?? null);
        self::setIfPresent($this->span, 'http.response.redirect_count', $info['redirect_count'] ?? null);
        self::setIfPresent($this->span, 'http.response.body.size', $info['size_download'] ?? null);
        self::setIfPresent($this->span, NetworkAttributes::NETWORK_PEER_ADDRESS, $info['primary_ip'] ?? null);
        self::setIfPresent($this->span, NetworkAttributes::NETWORK_PEER_PORT, $info['primary_port'] ?? null);
        self::setIfPresent($this->span, UrlAttributes::URL_SCHEME, isset($info['scheme']) ? strtolower((string) $info['scheme']) : null);
        self::setIfPresent($this->span, 'network.protocol.version', isset($info['http_version']) ? $this->httpVersion((int) $info['http_version']) : null);

        // Intentional cancellation is not an error, unless the response was already a failure
        // (RetryableHttpClient cancels failed attempts before retrying them).
        if (($info['canceled'] ?? false) === true && ($statusCode === null || $statusCode < 400)) {
            $this->span->end($timestamp);
            $this->recordDuration($durationHistogram, $statusCode, $info, null);

            return;
        }

        $errorType = null;
        if ($exception instanceof TransportExceptionInterface || ($exception !== null && $response === null)) {
            // The request could not be sent, or the response could not be (fully) received.
            $this->span
                ->recordException($exception)
                ->setAttribute(ErrorAttributes::ERROR_TYPE, $exception::class)
                ->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            $errorType = $exception::class;
        } elseif ($statusCode !== null && $statusCode >= 400) {
            if ($exception instanceof HttpExceptionInterface) {
                $this->span->recordException($exception);
            }
            $this->span
                ->setAttribute(ErrorAttributes::ERROR_TYPE, (string) $statusCode)
                ->setStatus(StatusCode::STATUS_ERROR);
            $errorType = (string) $statusCode;
        } elseif ($exception !== null) {
            // e.g. a decoding error from toArray() or a 3xx RedirectionException: the HTTP exchange succeeded.
            $this->span->recordException($exception);
        }

        $this->span->end($timestamp);
        $this->recordDuration($durationHistogram, $statusCode, $info, $errorType);
    }

    private function recordDuration(?HistogramInterface $histogram, ?int $statusCode, array $info, ?string $errorType): void
    {
        if ($histogram === null || !$histogram->isEnabled()) {
            return;
        }

        // Elapsed transfer time as measured by the transport itself. It starts when the
        // transfer actually begins (after any lazy queueing) and ends when it completes
        // or fails, which is exactly the duration this metric describes.
        $totalTime = $info['total_time'] ?? null;
        if (!is_numeric($totalTime) || (float) $totalTime <= 0.0) {
            return;
        }

        $attributes = $this->metricAttributes;
        if ($statusCode !== null) {
            $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $statusCode;
        }
        if ($errorType !== null) {
            $attributes[ErrorAttributes::ERROR_TYPE] = $errorType;
        }
        if (isset($info['http_version']) && is_numeric($info['http_version'])) {
            $attributes[NetworkAttributes::NETWORK_PROTOCOL_VERSION] = $this->httpVersion((int) $info['http_version']);
        }

        $histogram->record((float) $totalTime, $attributes);
    }

    private static function responseInfo(?ResponseInterface $response): array
    {
        if ($response === null) {
            return [];
        }

        try {
            $info = $response->getInfo();

            return is_array($info) ? $info : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** Epoch nanos of a Symfony getInfo() timing relative to the span start. */
    private function nanoOffset(mixed $relativeSeconds): int
    {
        if (!is_numeric($relativeSeconds)) {
            return $this->spanStart;
        }

        $offset = (float) $relativeSeconds;

        return $this->spanStart + (int) ($offset * 1_000_000_000.0);
    }

    private static function setIfPresent(\OpenTelemetry\API\Trace\SpanInterface $span, string $attribute, mixed $value): void
    {
        if ($value !== null && $value !== '') {
            /** @var non-empty-string $attribute */
            $span->setAttribute($attribute, $value);
        }
    }

    private function httpVersion(int $version): string
    {
        return match ($version) {
            1 => '1.0',
            2 => '1.1',
            3 => '2',
            default => (string) $version,
        };
    }
}
