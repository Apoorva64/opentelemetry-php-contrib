<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use function OpenTelemetry\Instrumentation\hook;
use OpenTelemetry\SemConv\Attributes\ClientAttributes;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Attributes\UserAgentAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Metrics\HttpIncubatingMetrics;
use OpenTelemetry\SemConv\Version;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** @psalm-suppress UnusedClass */
final class SymfonyInstrumentation
{
    public const NAME = 'symfony';

    private const REQUEST_START_TIME = self::class . '::request_start_time';

    public static function register(): void
    {
        $instrumentation = new CachedInstrumentation(
            'io.opentelemetry.contrib.php.symfony',
            null,
            Version::VERSION_1_38_0->url(),
        );
        $metrics = self::createMetrics($instrumentation);

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpKernel::class,
            'handle',
            pre: static function (
                HttpKernel $kernel,
                array $params,
                string $class,
                string $function,
                ?string $filename,
                ?int $lineno,
            ) use ($instrumentation, $metrics): array {
                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $type = $params[1] ?? HttpKernelInterface::MAIN_REQUEST;
                $method = $request?->getMethod() ?? 'unknown';
                $controller = $request?->attributes?->get('_controller');

                if (!is_callable($controller, true, $controllerName)) {
                    $controllerName = 'sub-request';
                }

                $name = ($type === HttpKernelInterface::SUB_REQUEST)
                    ? sprintf('%s %s', $method, $controllerName)
                    : $method;
                /** @psalm-suppress ArgumentTypeCoercion */
                $builder = $instrumentation
                    ->tracer()
                    ->spanBuilder($name)
                    ->setSpanKind(($type === HttpKernelInterface::SUB_REQUEST) ? SpanKind::KIND_INTERNAL : SpanKind::KIND_SERVER)
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno);

                $parent = Context::getCurrent();
                if ($request) {
                    $parent = Globals::propagator()->extract($request, RequestPropagationGetter::instance());
                    $urlQuery = HttpUrlAttributes::sanitizeQuery($request->getQueryString());
                    if (is_string($urlQuery) && $urlQuery !== '') {
                        $builder->setAttribute(UrlAttributes::URL_QUERY, $urlQuery);
                    }
                    if ($metrics->activeRequests !== null && $type === HttpKernelInterface::MAIN_REQUEST) {
                        $metrics->activeRequests->add(1, self::metricAttributes($request));
                    }
                    $span = $builder
                        ->setParent($parent)
                        ->setAttribute(UrlAttributes::URL_FULL, HttpUrlAttributes::sanitizeUrl($request->getUri()))
                        ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $request->getMethod())
                        ->setAttribute(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE, $request->headers->get('Content-Length'))
                        ->setAttribute(UrlAttributes::URL_SCHEME, $request->getScheme())
                        ->setAttribute(UrlAttributes::URL_PATH, $request->getPathInfo())
                        ->setAttribute(UserAgentAttributes::USER_AGENT_ORIGINAL, $request->headers->get('User-Agent'))
                        ->setAttribute(ServerAttributes::SERVER_ADDRESS, $request->getHost())
                        ->setAttribute(ServerAttributes::SERVER_PORT, $request->getPort())
                        ->setAttribute(ClientAttributes::CLIENT_ADDRESS, $request->getClientIp())
                        ->setAttribute(ClientAttributes::CLIENT_PORT, self::socketPort($request->server->get('REMOTE_PORT')))
                        ->setAttribute(NetworkAttributes::NETWORK_PEER_ADDRESS, $request->server->get('REMOTE_ADDR') ?: null)
                        ->setAttribute(NetworkAttributes::NETWORK_PEER_PORT, self::socketPort($request->server->get('REMOTE_PORT')))
                        ->startSpan();
                    $request->attributes->set(SpanInterface::class, $span);
                    if ($type === HttpKernelInterface::MAIN_REQUEST) {
                        $request->attributes->set(self::REQUEST_START_TIME, Clock::getDefault()->now());
                    }
                } else {
                    $span = $builder->startSpan();
                }
                Context::storage()->attach($span->storeInContext($parent));

                return [$request];
            },
            post: static function (
                HttpKernel $kernel,
                array $params,
                ?Response $response,
                ?\Throwable $exception
            ) use ($metrics): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $type = $params[1] ?? HttpKernelInterface::MAIN_REQUEST;
                $isSubRequest = ($type === HttpKernelInterface::SUB_REQUEST);

                // MAIN_REQUEST without exception: let terminate() handle everything
                if (!$isSubRequest && null === $exception) {
                    return;
                }

                $span = Span::fromContext($scope->context());
                $scope->detach();

                // Record exception if present
                if (null !== $exception) {
                    $span->recordException($exception);
                    $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                }

                // Remaining cases are sub-requests and main requests with an
                // exception, which terminate() will not handle: end the span here.
                if ($isSubRequest && null !== $response) {
                    $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                }
                if (!$isSubRequest) {
                    $request = ($params[0] instanceof Request) ? $params[0] : null;
                    if (null !== $request) {
                        self::recordMetrics($metrics, $request, null, $exception, '');
                        $request->attributes->remove(self::REQUEST_START_TIME);
                    }
                }
                $span->end();
            }
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpKernel::class,
            'terminate',
            post: static function (
                HttpKernel $kernel,
                array $params,
                ?\Throwable $exception
            ) use ($metrics): void {
                $scope = Context::storage()->scope();
                if (null === $scope) {
                    return;
                }
                $span = Span::fromContext($scope->context());
                if (!$span->isRecording()) {
                    // Sub-request/exception span was already ended by the handle() hook
                    return;
                }
                $scope->detach();

                $request = ($params[0] instanceof Request) ? $params[0] : null;
                $response = ($params[1] instanceof Response) ? $params[1] : null;
                if (null !== $request) {
                    $routeName = $request->attributes->get('_route', '');

                    if ('' !== $routeName) {
                        /** @psalm-suppress ArgumentTypeCoercion */
                        $span
                            ->updateName(sprintf('%s %s', $request->getMethod(), $routeName))
                            ->setAttribute(HttpAttributes::HTTP_ROUTE, $routeName);
                    }
                }

                if (null !== $exception) {
                    $span->recordException($exception);
                    if (null !== $response && $response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
                        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
                    }
                }

                if (null === $response) {
                    if (null !== $request) {
                        self::recordMetrics($metrics, $request, null, $exception, '');
                        $request->attributes->remove(self::REQUEST_START_TIME);
                    }
                    $span->end();

                    return;
                }

                if ($response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR) {
                    $span->setStatus(StatusCode::STATUS_ERROR);
                }
                $span->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
                $span->setAttribute(
                    NetworkAttributes::NETWORK_PROTOCOL_VERSION,
                    HttpServerMetrics::protocolVersion($response->getProtocolVersion()),
                );
                $contentLength = $response->headers->get('Content-Length');
                /** @psalm-suppress PossiblyFalseArgument */
                if (null === $contentLength && is_string($response->getContent())) {
                    $contentLength = \strlen($response->getContent());
                }

                $span->setAttribute(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE, $contentLength);

                $prop = Globals::responsePropagator();
                $prop->inject($response, ResponsePropagationSetter::instance(), $scope->context());

                if (null !== $request) {
                    $routeName = (string) $request->attributes->get('_route', '');
                    self::recordMetrics($metrics, $request, $response->getStatusCode(), null, $routeName, $response->getProtocolVersion(), $contentLength);
                    $request->attributes->remove(self::REQUEST_START_TIME);
                }
                $span->end();
            }
        );

        /** @psalm-suppress UnusedFunctionCall */
        hook(
            HttpKernel::class,
            'handleThrowable',
            pre: static function (
                HttpKernel $_kernel,
                array $params,
                string $_class,
                string $_function,
                ?string $_filename,
                ?int $_lineno,
            ): array {
                /** @var \Throwable $throwable */
                $throwable = $params[0];

                Span::getCurrent()
                    ->recordException($throwable);

                return $params;
            },
        );
    }

    /**
     * Creates the request duration histogram, and the experimental active requests
     * counter and request/response body size histograms when experimental HTTP server
     * telemetry is enabled.
     *
     * @see https://opentelemetry.io/docs/zero-code/java/agent/instrumentation/http/
     */
    private static function createMetrics(CachedInstrumentation $instrumentation): HttpServerMetrics
    {
        $duration = $instrumentation->meter()->createHistogram(
            HttpIncubatingMetrics::HTTP_SERVER_REQUEST_DURATION,
            's',
            'Duration of HTTP server requests.',
            ['ExplicitBucketBoundaries' => HttpServerMetrics::DURATION_BUCKETS],
        );

        if (!HttpServerMetrics::experimentalTelemetryEnabled()) {
            return new HttpServerMetrics($duration);
        }

        return new HttpServerMetrics(
            $duration,
            $instrumentation->meter()->createUpDownCounter(
                HttpIncubatingMetrics::HTTP_SERVER_ACTIVE_REQUESTS,
                '{request}',
                'Number of active HTTP server requests.',
            ),
            $instrumentation->meter()->createHistogram(
                HttpIncubatingMetrics::HTTP_SERVER_REQUEST_BODY_SIZE,
                'By',
                'Size of HTTP server request bodies.',
            ),
            $instrumentation->meter()->createHistogram(
                HttpIncubatingMetrics::HTTP_SERVER_RESPONSE_BODY_SIZE,
                'By',
                'Size of HTTP server response bodies.',
            ),
        );
    }

    /**
     * Records the metrics of one completed (MAIN_REQUEST) server span: the request
     * duration from the recorded span start, the request body size from its
     * Content-Length, and the response body size when it is known. The active
     * requests counter is decreased once per request.
     */
    private static function recordMetrics(
        HttpServerMetrics $metrics,
        Request $request,
        ?int $statusCode,
        ?\Throwable $exception,
        string $routeName,
        ?string $protocolVersion = null,
        int|string|null $responseBodySize = null,
    ): void {
        $attributes = self::metricAttributes($request);
        if ($routeName !== '') {
            $attributes[HttpAttributes::HTTP_ROUTE] = $routeName;
        }
        if ($statusCode !== null) {
            $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE] = $statusCode;
        }
        if ($protocolVersion !== null) {
            $attributes[NetworkAttributes::NETWORK_PROTOCOL_VERSION] = HttpServerMetrics::protocolVersion($protocolVersion);
        }
        $errorType = null;
        if ($exception !== null) {
            $errorType = $exception::class;
        } elseif ($statusCode !== null && $statusCode >= Response::HTTP_BAD_REQUEST) {
            $errorType = (string) $statusCode;
        }
        if ($errorType !== null) {
            $attributes[ErrorAttributes::ERROR_TYPE] = $errorType;
        }

        /** @var int $startTime */
        $startTime = $request->attributes->get(self::REQUEST_START_TIME, Clock::getDefault()->now());
        if ($metrics->requestDuration !== null) {
            $metrics->requestDuration->record(
                max(0.0, (float) (Clock::getDefault()->now() - $startTime)) * 1e-9,
                $attributes,
            );
        }

        $requestBodySize = self::numeric($request->headers->get('Content-Length'));
        if ($metrics->requestBodySize !== null && $requestBodySize !== null && $requestBodySize > 0) {
            $metrics->requestBodySize->record($requestBodySize, $attributes);
        }

        $responseBody = self::numeric($responseBodySize);
        if ($metrics->responseBodySize !== null && $responseBody !== null) {
            $metrics->responseBodySize->record($responseBody, $attributes);
        }

        if ($metrics->activeRequests !== null) {
            $metrics->activeRequests->add(-1, self::metricAttributes($request));
        }
    }

    /**
     * @return array<non-empty-string, int|string>
     */
    private static function metricAttributes(Request $request): array
    {
        $attributes = [
            HttpAttributes::HTTP_REQUEST_METHOD => HttpServerMetrics::requestMethod($request->getMethod()),
            UrlAttributes::URL_SCHEME => $request->getScheme(),
        ];
        $host = $request->getHost();
        if ($host !== '') {
            $attributes[ServerAttributes::SERVER_ADDRESS] = $host;
        }
        $port = $request->getPort();
        if ($port !== null) {
            $attributes[ServerAttributes::SERVER_PORT] = $port;
        }

        return $attributes;
    }

    private static function socketPort(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
