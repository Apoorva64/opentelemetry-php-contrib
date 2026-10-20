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
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\Version;
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
    ];

    /**
     * Response methods that signal progress in the response lifecycle.
     */
    private const RESPONSE_METHODS = ['getStatusCode', 'getHeaders', 'getContent', 'toArray', 'cancel', '__destruct'];

    /** @var \WeakMap<ResponseInterface, HttpClientRequestState>|null */
    private static ?\WeakMap $responseStates = null;

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

        self::registerRequestHook($instrumentation);
        self::registerResponseHooks();

        // Responses still referenced at the end of the script are only destructed after all
        // shutdown functions ran, i.e. after the SDK flushed and shut down its TracerProvider.
        register_shutdown_function(self::endOpenSpans(...));
    }

    /**
     * Ends the spans of responses that are still referenced, at their last transport activity.
     *
     * @internal
     */
    public static function endOpenSpans(): void
    {
        foreach (self::responseStates() as $response => $state) {
            $state->finish($response, null, $state->lastActivity());
        }
    }

    private static function registerRequestHook(CachedInstrumentation $instrumentation): void
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
            ) use ($instrumentation): array {
                $parent = Context::getCurrent();
                $request = sprintf('%s %s', $params[0], (string) $params[1]);

                if ($parent->get(self::requestKey()) === $request) {
                    Context::storage()->attach($parent->with(self::forwardedKey(), true));

                    return $params;
                }

                /** @psalm-suppress ArgumentTypeCoercion */
                $span = $instrumentation
                    ->tracer()
                    ->spanBuilder(\sprintf('%s', $params[0]))
                    ->setSpanKind(SpanKind::KIND_CLIENT)
                    ->setAttribute(TraceAttributes::PEER_SERVICE, parse_url((string) $params[1])['host'] ?? null)
                    ->setAttribute(UrlAttributes::URL_FULL, (string) $params[1])
                    ->setAttribute(HttpAttributes::HTTP_REQUEST_METHOD, $params[0])
                    ->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, sprintf('%s::%s', $class, $function))
                    ->setAttribute(CodeAttributes::CODE_FILE_PATH, $filename)
                    ->setAttribute(CodeAttributes::CODE_LINE_NUMBER, $lineno)
                    ->setParent($parent)
                    ->startSpan();
                $state = new HttpClientRequestState($span);

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

                $context = self::ownerContext($span->storeInContext($parent), $request)
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
                    $state->finish(null, $exception);

                    return;
                }

                if (false === self::supportsProgress(get_class($client))) {
                    // Synchronous clients return a completed response.
                    try {
                        $state->setStatusCode($response->getStatusCode());
                    } catch (\Throwable) {
                    }
                    $state->finish($response);

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
                    $state->finish($response, $exception);
                }

                return;
            case '__destruct':
                // The body was not (observably) consumed: end at the last transport activity rather
                // than at cleanup time, unless sending the request from the destructor failed.
                $state->finish($response, $exception, null === $exception ? $state->lastActivity() : null);

                return;
            case 'cancel':
                $state->finish($response, $exception);

                return;
            default:
                // getContent() / toArray(): the body was received (or failed). When another response
                // drove this transfer to completion earlier, end when the last bytes were received.
                $timestamp = $state->completedBeforeCall() && !$exception instanceof TransportExceptionInterface
                    ? $state->lastActivity()
                    : null;
                $state->finish($response, $exception, $timestamp);
        }
    }

    private static function stateOf(ResponseInterface $response): ?HttpClientRequestState
    {
        return self::$responseStates[$response] ?? null;
    }

    private static function ownerContext(ContextInterface $context, string $request): ContextInterface
    {
        return $context
            ->with(self::requestKey(), $request)
            ->with(self::forwardedKey(), false);
    }
}
