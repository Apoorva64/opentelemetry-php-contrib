<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\Propagation\ArrayAccessGetterSetter;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\HttpClient\UriTemplateHttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Creates one CLIENT span per logical request. Symfony responses are lazy, so the span
 * is kept open until the response completes: body fully received (getContent(), toArray()),
 * failure, cancel(), or the response being released without its body being read.
 *
 * @phan-file-suppress PhanTypeInvalidCallableArraySize
 * @psalm-suppress UnusedClass
 */
final class HttpClientInstrumentation
{
    /**
     * These clients are not supported by this instrumentation, because
     * they are synchronous and do not support the on_progress option.
     */
    const SYNCHRONOUS_CLIENTS = [
        /** @psalm-suppress UndefinedClass */
        'ApiPlatform\Symfony\Bundle\Test\Client',
        /** @psalm-suppress UndefinedClass */
        'ApiPlatform\Test\Client',
    ];

    /**
     * These decorators send each request they receive to the client they wrap, once, with a URL
     * they may have rewritten (resolved against a base URI, expanded from a template).
     */
    const URL_REWRITING_CLIENTS = [
        ScopingHttpClient::class,
        UriTemplateHttpClient::class,
    ];

    /**
     * Response methods that signal progress in the response lifecycle.
     */
    private const RESPONSE_METHODS = ['getStatusCode', 'getHeaders', 'getContent', 'toArray', 'cancel', '__destruct'];

    /**
     * Explicit buckets of the http.client.request.duration histogram, in seconds.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpclientrequestduration
     */
    private const DURATION_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1.0, 2.5, 5.0, 7.5, 10.0];

    /** @var \WeakMap<ResponseInterface, HttpClientRequestState>|null */
    private static ?\WeakMap $responseStates = null;
    private static ?HttpClientMetrics $metrics = null;

    public static function supportsProgress(string $class): bool
    {
        return false === in_array($class, self::SYNCHRONOUS_CLIENTS);
    }

    /** @return \WeakMap<ResponseInterface, HttpClientRequestState> */
    private static function responseStates(): \WeakMap
    {
        if (null === self::$responseStates) {
            /** @var \WeakMap<ResponseInterface, HttpClientRequestState> $states */
            $states = new \WeakMap();
            self::$responseStates = $states;
        }

        return self::$responseStates;
    }

    private static function requestKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.request');
    }

    private static function forwardedKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.forwarded');
    }

    private static function rewritingKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.rewriting');
    }

    private static function stateKey(): ContextKeyInterface
    {
        static $instance;

        return $instance ??= Context::createKey('symfony-http-client.state');
    }

    /** @psalm-suppress PossiblyUnusedMethod */
    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony_http',
            null,
            Version::VERSION_1_38_0->url(),
        );

        self::$metrics = self::createMetrics($instrumentation);
        self::registerRequestHook($instrumentation, HttpClientPeerServiceResolver::fromEnvironment());
        self::registerResponseHooks();

        // Responses still referenced at the end of the script are only destructed after all
        // shutdown functions ran, i.e. after the SDK flushed and shut down its TracerProvider.
        register_shutdown_function(self::endOpenSpans(...));
    }

    /**
     * Creates the request duration histogram, and the experimental request/response body
     * size histograms when experimental HTTP client telemetry is enabled.
     *
     * @see https://opentelemetry.io/docs/zero-code/java/agent/instrumentation/http/
     */
    private static function createMetrics(CachedInstrumentation $instrumentation): HttpClientMetrics
    {
        $duration = $instrumentation->meter()->createHistogram(
            'http.client.request.duration',
            's',
            'Duration of HTTP client requests.',
            ['ExplicitBucketBoundaries' => self::DURATION_BUCKETS],
        );

        if (!HttpClientMetrics::experimentalTelemetryEnabled()) {
            return new HttpClientMetrics($duration);
        }

        return new HttpClientMetrics(
            $duration,
            $instrumentation->meter()->createHistogram(
                HttpIncubatingMetrics::HTTP_CLIENT_REQUEST_BODY_SIZE,
                'By',
                'Size of HTTP client request bodies.',
            ),
            $instrumentation->meter()->createHistogram(
                HttpIncubatingMetrics::HTTP_CLIENT_RESPONSE_BODY_SIZE,
                'By',
                'Size of HTTP client response bodies.',
            ),
        );
    }

    /**
     * Ends the spans of responses that are still referenced, at their last transport activity.
     *
     * @internal
     */
    public static function endOpenSpans(): void
    {
        foreach (self::responseStates() as $response => $state) {
            $state->finish($response, null, $state->lastActivity(), self::$metrics);
        }
    }

    private static function registerRequestHook(CachedInstrumentation $instrumentation, HttpClientPeerServiceResolver $peerServiceResolver): void
    {
        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpClientInterface::class,
            'request',
            pre: static function (
                HttpClientInterface $client,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation, $peerServiceResolver): array {
                $parent = Context::getCurrent();
                $request = sprintf('%s %s', $params[0], (string) $params[1]);

                if ($parent->get(self::requestKey()) === $request || true === $parent->get(self::rewritingKey())) {
                    $state = $parent->get(self::stateKey());
                    if ($state instanceof HttpClientRequestState) {
                        if ($parent->get(self::requestKey()) !== $request) {
                            // A URL-rewriting decorator resolved the request to a different
                            // target: the span describes the request that is actually sent.
                            $state->updateTarget((string) $params[1], $peerServiceResolver->resolve((string) $params[1]));
                        }
                        $state->onForwardedRequest();
                    }
                    Context::storage()->attach($parent
                        ->with(self::stateKey(), $state)
                        ->with(self::requestKey(), $request)
                        ->with(self::rewritingKey(), in_array($class, self::URL_REWRITING_CLIENTS, true))
                        ->with(self::forwardedKey(), true));

                    return $params;
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder((string) $params[0])
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $params[0])
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);
                foreach (HttpClientRequestAttributes::forUrl((string) $params[1]) as $name => $value) {
                    $builder->setAttribute($name, $value);
                }
                $peerService = $peerServiceResolver->resolve((string) $params[1]);
                if ($peerService !== null) {
                    $builder->setAttribute(TraceAttributes::PEER_SERVICE, $peerService);
                }
                $span = $builder
                    ->setParent($parent)
                    ->startSpan();
                $state = new HttpClientRequestState($span, (string) $params[0], (string) $params[1]);

                $requestOptions = $params[2] ?? [];
                if (!isset($requestOptions['headers'])) {
                    $requestOptions['headers'] = [];
                }

                if (self::supportsProgress($class)) {
                    $previousOnProgress = $requestOptions['on_progress'] ?? null;

                    // Only observes the transfer; the span is ended by the response lifecycle hooks.
                    $requestOptions['on_progress'] = static function (int $dlNow, int $dlSize, array $info) use (
                        $previousOnProgress,
                        $state
                    ): void {
                        $state->onProgress($dlNow, $info);

                        if (null !== $previousOnProgress) {
                            $previousOnProgress($dlNow, $dlSize, $info);
                        }
                    };
                }

                $context = self::ownerContext($span->storeInContext($parent), $request, $class)
                    ->with(self::stateKey(), $state);
                Globals::propagator()->inject($requestOptions['headers'], ArrayAccessGetterSetter::getInstance(), $context);

                Context::storage()->attach($context);
                $params[2] = $requestOptions;

                return $params;
            },
            post: static function (
                HttpClientInterface $client,
                array $params,
                ?ResponseInterface $response,
                ?\Throwable $exception
            ): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $scope->detach();

                $context = $scope->context();
                if (true === $context->get(self::forwardedKey())) {
                    return;
                }

                $state = $context->get(self::stateKey());
                if (!$state instanceof HttpClientRequestState) {
                    return;
                }

                if (null !== $exception || null === $response) {
                    $state->finish(null, $exception, null, self::$metrics);

                    return;
                }

                if (false === self::supportsProgress(get_class($client))) {
                    // Synchronous clients return a completed response.
                    try {
                        $state->setStatusCode($response->getStatusCode());
                    } catch (\Throwable) {
                    }
                    $state->finish($response, null, null, self::$metrics);

                    return;
                }

                self::responseStates()[$response] = $state;
            },
        );
    }

    private static function registerResponseHooks(): void
    {
        foreach (self::RESPONSE_METHODS as $method) {
            /** @psalm-suppress UnusedFunctionCall */
            hook(
                ResponseInterface::class,
                $method,
                pre: static function (ResponseInterface $response): void {
                    self::stateOf($response)?->enterResponseCall();
                },
                post: static function (
                    ResponseInterface $response,
                    array $params,
                    mixed $returnValue,
                    ?\Throwable $exception,
                ) use ($method): void {
                    $state = self::stateOf($response);
                    // Ignore calls nested in another response method, e.g. toArray() -> getContent()
                    if (null === $state || !$state->leaveResponseCall() || $state->isEnded()) {
                        return;
                    }

                    self::onResponseCallEnd($method, $response, $state, $exception);
                },
            );
        }
    }

    private static function onResponseCallEnd(
        string $method,
        ResponseInterface $response,
        HttpClientRequestState $state,
        ?\Throwable $exception,
    ): void {
        switch ($method) {
            case 'getStatusCode':
            case 'getHeaders':
                // Headers were read: keep the span open for the body, unless reading them failed.
                if ($exception instanceof TransportExceptionInterface) {
                    $state->finish($response, $exception, null, self::$metrics);
                }

                return;
            case '__destruct':
                // The body was not (observably) consumed: end at the last transport activity rather
                // than at cleanup time, unless sending the request from the destructor failed.
                $state->finish($response, $exception, null === $exception ? $state->lastActivity() : null, self::$metrics);

                return;
            case 'cancel':
                $state->finish($response, $exception, null, self::$metrics);

                return;
            default:
                // getContent() / toArray(): the body was received (or failed). When another response
                // drove this transfer to completion earlier, end when the last bytes were received.
                $timestamp = $state->completedBeforeCall() && !$exception instanceof TransportExceptionInterface
                    ? $state->lastActivity()
                    : null;
                $state->finish($response, $exception, $timestamp, self::$metrics);
        }
    }

    private static function stateOf(ResponseInterface $response): ?HttpClientRequestState
    {
        return self::$responseStates[$response] ?? null;
    }

    private static function ownerContext(ContextInterface $context, string $request, string $class): ContextInterface
    {
        return $context
            ->with(self::requestKey(), $request)
            ->with(self::rewritingKey(), in_array($class, self::URL_REWRITING_CLIENTS, true))
            ->with(self::forwardedKey(), false);
    }
}
