<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;

/**
 * The meters of the Symfony server instrumentation. The active requests counter and
 * the request/response body size histograms only exist when experimental HTTP server
 * telemetry is enabled (OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY).
 *
 * @see https://opentelemetry.io/docs/specs/semconv/http/http-metrics/
 * @internal
 */
final class HttpServerMetrics
{
    /**
     * Explicit buckets of the http.server.request.duration histogram, in seconds.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpserverrequestduration
     */
    public const DURATION_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0];

    /**
     * Known HTTP request methods per semantic convention. Any other method is
     * recorded as _OTHER.
     */
    private const KNOWN_METHODS = [
        'CONNECT',
        'DELETE',
        'GET',
        'HEAD',
        'OPTIONS',
        'PATCH',
        'POST',
        'PUT',
        'QUERY',
        'TRACE',
    ];

    public function __construct(
        public readonly ?HistogramInterface $requestDuration = null,
        public readonly ?UpDownCounterInterface $activeRequests = null,
        public readonly ?HistogramInterface $requestBodySize = null,
        public readonly ?HistogramInterface $responseBodySize = null,
    ) {
    }

    /**
     * Whether experimental HTTP server telemetry is enabled.
     */
    public static function experimentalTelemetryEnabled(): bool
    {
        $value = $_SERVER['OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY']
            ?? $_ENV['OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY']
            ?? getenv('OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY');

        return is_string($value) && in_array(strtolower(trim($value)), ['true', '1'], true);
    }

    /**
     * The HTTP method normalized per semantic convention: unknown methods are
     * recorded as _OTHER.
     */
    public static function requestMethod(string $method): string
    {
        return in_array(strtoupper($method), self::KNOWN_METHODS, true) ? $method : '_OTHER';
    }

    /**
     * The protocol version per semantic convention: HTTP/2 and later are recorded
     * by their major version only.
     */
    public static function protocolVersion(string $version): string
    {
        if (preg_match('/^(\d+)\.\d+$/', $version, $matches) === 1 && (int) $matches[1] >= 2) {
            return $matches[1];
        }

        return $version;
    }
}
