<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration;

use ApiPlatform\Symfony\Bundle\Test\Client as ApiPlatformTestClient;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientInstrumentation;
use OpenTelemetry\SDK\Trace\EventInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\Tests\Instrumentation\Symfony\tests\Integration\Fixtures\ForwardingHttpClient;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\HttpClient\UriTemplateHttpClient;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Test\TestHttpServer;

require_once __DIR__ . '/Fixtures/ApiPlatform/Symfony/Bundle/Test/Client.php';

final class HttpClientInstrumentationTest extends AbstractTest
{
    public static function setUpBeforeClass(): void
    {
        TestHttpServer::start();
    }

    protected function getHttpClient(string $_testCase): HttpClientInterface
    {
        return new CurlHttpClient(['verify_peer' => false, 'verify_host' => false]);
    }

    /**
     * @dataProvider requestProvider
     */
    public function test_send_request(string $method, string $uri, int $statusCode, string $spanStatus): void
    {
        $client = $this->getHttpClient(__FUNCTION__);
        $this->assertCount(0, $this->storage);

        $response = $client->request($method, $uri, ['bindto' => '127.0.0.1:9876']);
        $this->assertSame($statusCode, $response->getStatusCode());
        $this->assertCount(0, $this->storage, 'The span stays open until the response body is read');

        if ($method === 'GET') {
            $requestHeaders = $response->toArray(false);
            $this->assertArrayHasKey('HTTP_TRACEPARENT', $requestHeaders);
            $this->assertNotNull($requestHeaders['HTTP_TRACEPARENT']);
        } else {
            $response->getContent(false);
        }
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];

        $this->assertStringContainsString($method, $span->getName());
        $this->assertTrue($span->getAttributes()->has(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(parse_url($uri)['host'] ?? null, $span->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(parse_url($uri)['port'] ?? null, $span->getAttributes()->get(ServerAttributes::SERVER_PORT));
        $this->assertTrue($span->getAttributes()->has(UrlAttributes::URL_FULL));
        $this->assertSame($uri, $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertTrue($span->getAttributes()->has(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame($method, $span->getAttributes()->get(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertTrue($span->getAttributes()->has(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame($spanStatus, $span->getStatus()->getCode());
        $this->assertSame($statusCode, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_throw_exception(): void
    {
        $client = $this->getHttpClient(__FUNCTION__);
        $this->assertCount(0, $this->storage);

        try {
            $client->request('GET', 'http://localhost:8057', [
                'bindto' => '127.0.0.1:9876',
                'auth_ntlm' => [],
            ]);
        } catch (InvalidArgumentException) {
        }

        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $event = self::exceptionEvents($span)[0];

        $this->assertTrue($span->getAttributes()->has(UrlAttributes::URL_FULL));
        $this->assertTrue($span->getAttributes()->has(HttpAttributes::HTTP_REQUEST_METHOD));
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(InvalidArgumentException::class, $event->getAttributes()->get('exception.type'));
    }

    public function test_decorated_client_creates_a_single_span(): void
    {
        $client = new ForwardingHttpClient(new ForwardingHttpClient($this->getHttpClient(__FUNCTION__)));
        $this->assertCount(0, $this->storage);

        $response = $client->request('GET', 'http://localhost:8057');
        $requestHeaders = $response->toArray(false);
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('http://localhost:8057', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertStringContainsString($span->getSpanId(), $requestHeaders['HTTP_TRACEPARENT']);
    }

    public function test_decorated_client_exception_is_recorded_once(): void
    {
        $client = new ForwardingHttpClient($this->getHttpClient(__FUNCTION__));
        $this->assertCount(0, $this->storage);

        try {
            $client->request('GET', 'http://localhost:8057', ['auth_ntlm' => []]);
        } catch (InvalidArgumentException) {
        }

        $this->assertCount(1, $this->storage);
        $this->assertCount(1, $this->storage[0]->getEvents());
        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
    }

    public function test_decorator_issuing_another_request_creates_its_own_span(): void
    {
        $client = new ForwardingHttpClient(
            $this->getHttpClient(__FUNCTION__),
            static function (HttpClientInterface $inner): void {
                $inner->request('POST', 'http://localhost:8057/json')->getStatusCode();
            },
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', 'http://localhost:8057')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertSame('http://localhost:8057/json', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_client_resolving_a_relative_url_creates_a_single_span(): void
    {
        $client = new ForwardingHttpClient(ScopingHttpClient::forBaseUri(
            new ForwardingHttpClient($this->getHttpClient(__FUNCTION__)),
            'http://localhost:8057/',
        ));
        $this->assertCount(0, $this->storage);

        $response = $client->request('GET', '/?a=a&b=b');
        $requestHeaders = $response->toArray(false);
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('http://localhost:8057/?a=a&b=b', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('localhost', $span->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertStringContainsString($span->getSpanId(), $requestHeaders['HTTP_TRACEPARENT']);
    }

    public function test_client_resolving_a_relative_path_against_a_base_path_creates_a_single_span(): void
    {
        $client = ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/json/');
        $this->assertCount(0, $this->storage);

        $client->request('GET', '1', ['query' => ['a' => 'a']])->getStatusCode();
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('http://localhost:8057/json/1?a=a', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_decorator_issuing_another_absolute_request_for_a_relative_one_creates_its_own_span(): void
    {
        $client = new ForwardingHttpClient(
            ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/'),
            static function (HttpClientInterface $inner): void {
                $inner->request('GET', 'http://localhost:8057/json')->getStatusCode();
            },
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertSame('http://localhost:8057/json', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057/', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_decorator_issuing_a_request_for_the_same_path_on_another_host_creates_its_own_span(): void
    {
        $client = new ForwardingHttpClient(
            ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/'),
            static function (HttpClientInterface $inner): void {
                $inner->request('GET', 'http://127.0.0.1:8057/')->getStatusCode();
            },
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertSame('http://127.0.0.1:8057/', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057/', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('localhost', $this->storage[1]->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_decorator_below_a_rewriting_client_issuing_another_request_creates_its_own_span(): void
    {
        $client = ScopingHttpClient::forBaseUri(
            new ForwardingHttpClient(
                $this->getHttpClient(__FUNCTION__),
                static function (HttpClientInterface $inner): void {
                    $inner->request('POST', 'http://localhost:8057/post')->getStatusCode();
                },
            ),
            'http://localhost:8057/',
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/json')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertSame('http://localhost:8057/post', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057/json', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame($this->storage[1]->getSpanId(), $this->storage[0]->getParentSpanId());
    }

    public function test_client_merging_the_query_option_into_an_absolute_url_creates_a_single_span(): void
    {
        $client = ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/');
        $this->assertCount(0, $this->storage);

        $client->request('GET', 'http://localhost:8057/json', ['query' => ['a' => 'a']])->getStatusCode();
        $this->assertCount(1, $this->storage);
        $this->assertSame('http://localhost:8057/json?a=a', $this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
    }

    public function test_client_expanding_a_uri_template_creates_a_single_span(): void
    {
        $client = new UriTemplateHttpClient(
            ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/'),
            static fn (string $url, array $vars): string => str_replace('{id}', (string) $vars['id'], $url),
        );
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/json/{id}', ['vars' => ['id' => 1]])->getStatusCode();
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame('http://localhost:8057/json/1', $span->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('localhost', $span->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_span_activated_between_decorators_does_not_receive_the_resolved_url(): void
    {
        $client = new ForwardingHttpClient(new class(ScopingHttpClient::forBaseUri($this->getHttpClient(__FUNCTION__), 'http://localhost:8057/')) implements HttpClientInterface {
            use DecoratorTrait;

            public function request(string $method, string $url, array $options = []): ResponseInterface
            {
                $span = Globals::tracerProvider()->getTracer('test')->spanBuilder('decorator')->startSpan();
                $scope = $span->activate();

                try {
                    return $this->client->request($method, $url, $options);
                } finally {
                    $scope->detach();
                    $span->end();
                }
            }
        });
        $this->assertCount(0, $this->storage);

        $client->request('GET', '/json')->getStatusCode();
        $this->assertCount(2, $this->storage);

        $this->assertNull($this->storage[0]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('http://localhost:8057/json', $this->storage[1]->getAttributes()->get(UrlAttributes::URL_FULL));
        $this->assertSame('localhost', $this->storage[1]->getAttributes()->get(ServerAttributes::SERVER_ADDRESS));
    }

    public function test_synchronous_client_does_not_receive_on_progress_option(): void
    {
        $client = new ApiPlatformTestClient();

        $client->request('GET', 'http://localhost:8057');

        $this->assertArrayNotHasKey('on_progress', $client->options);
        $this->assertCount(1, $this->storage);
        $this->assertSame(200, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_span_covers_response_body(HttpClientInterface $client): void
    {
        $response = $client->request('GET', 'http://localhost:8057/timeout-body');
        $response->getStatusCode();
        $this->assertCount(0, $this->storage);

        $response->getContent();
        $this->assertCount(1, $this->storage);

        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        $this->assertGreaterThanOrEqual(400_000_000, $span->getEndEpochNanos() - $span->getStartEpochNanos());
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_transport_error_before_headers_ends_span(HttpClientInterface $client): void
    {
        $response = $client->request('GET', 'http://127.0.0.1:1/');

        try {
            $response->getContent();
            $this->fail('Expected a transport exception');
        } catch (TransportExceptionInterface) {
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertFalse($span->getAttributes()->has(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame(TransportException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame(TransportException::class, self::exceptionEvents($span)[0]->getAttributes()->get('exception.type'));
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_transport_error_after_headers_is_recorded(HttpClientInterface $client): void
    {
        $response = $client->request('GET', 'http://localhost:8057/length-broken');
        $this->assertSame(200, $response->getStatusCode());

        try {
            $response->getContent();
            $this->fail('Expected a transport exception');
        } catch (TransportExceptionInterface) {
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(200, $span->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(TransportException::class, $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertCount(1, self::exceptionEvents($span));
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_http_exception_is_recorded(HttpClientInterface $client): void
    {
        try {
            $client->request('GET', 'http://localhost:8057/404')->getContent();
            $this->fail('Expected a client exception');
        } catch (ClientExceptionInterface) {
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame('404', $span->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
        $this->assertSame(ClientException::class, self::exceptionEvents($span)[0]->getAttributes()->get('exception.type'));
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_decoding_error_does_not_fail_span(HttpClientInterface $client): void
    {
        try {
            $client->request('GET', 'http://localhost:8057/chunked')->toArray();
            $this->fail('Expected a decoding exception');
        } catch (DecodingExceptionInterface) {
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_UNSET, $span->getStatus()->getCode());
        $this->assertFalse($span->getAttributes()->has(ErrorAttributes::ERROR_TYPE));
        $this->assertCount(1, self::exceptionEvents($span));
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_unread_response_ends_span_on_destruct(HttpClientInterface $client): void
    {
        $client->request('GET', 'http://localhost:8057');

        $this->assertCount(1, $this->storage);
        $this->assertSame(200, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame(StatusCode::STATUS_UNSET, $this->storage[0]->getStatus()->getCode());
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_unread_response_error_thrown_on_destruct_is_recorded(HttpClientInterface $client): void
    {
        try {
            $client->request('GET', 'http://localhost:8057/404');
            $this->fail('Expected a client exception');
        } catch (ClientExceptionInterface) {
        }

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        $this->assertSame(ClientException::class, self::exceptionEvents($span)[0]->getAttributes()->get('exception.type'));
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_abandoned_response_ends_at_last_transport_activity(HttpClientInterface $client): void
    {
        $response = $client->request('GET', 'http://localhost:8057');
        $response->getStatusCode();
        usleep(300_000);
        unset($response);

        $this->assertCount(1, $this->storage);
        $span = $this->storage[0];
        $this->assertLessThan(300_000_000, $span->getEndEpochNanos() - $span->getStartEpochNanos());
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_cancel_is_not_an_error(HttpClientInterface $client): void
    {
        $response = $client->request('GET', 'http://localhost:8057/timeout-body');
        $response->getStatusCode();
        $response->cancel();

        $this->assertCount(1, $this->storage);
        $this->assertSame(StatusCode::STATUS_UNSET, $this->storage[0]->getStatus()->getCode());
        $this->assertFalse($this->storage[0]->getAttributes()->has(ErrorAttributes::ERROR_TYPE));
    }

    /**
     * @dataProvider clientProvider
     */
    public function test_streamed_response_creates_a_single_span(HttpClientInterface $client): void
    {
        $response = $client->request('GET', 'http://localhost:8057/chunked');
        $content = '';
        foreach ($client->stream($response) as $chunk) {
            $content .= $chunk->getContent();
        }
        $this->assertSame('Symfony is awesome!', $content);
        unset($response);

        $this->assertCount(1, $this->storage);
        $this->assertSame(200, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_traceable_client_creates_a_single_span(): void
    {
        $client = new TraceableHttpClient($this->getHttpClient(__FUNCTION__));

        $client->request('GET', 'http://localhost:8057')->getContent();

        $this->assertCount(1, $this->storage);
    }

    public function test_mock_client_unread_response_ends_span(): void
    {
        $client = new MockHttpClient(new MockResponse('ok'));

        $client->request('GET', 'http://localhost:8057');

        $this->assertCount(1, $this->storage);
    }

    public function test_open_spans_are_ended_at_shutdown(): void
    {
        $response = $this->getHttpClient(__FUNCTION__)->request('GET', 'http://localhost:8057');
        $response->getStatusCode();
        $this->assertCount(0, $this->storage);

        HttpClientInstrumentation::endOpenSpans();

        $this->assertCount(1, $this->storage);
        $this->assertSame(200, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        unset($response);
        $this->assertCount(1, $this->storage);
    }

    public function clientProvider(): array
    {
        return [
            'curl' => [new CurlHttpClient()],
            'native' => [new NativeHttpClient()],
        ];
    }

    public function requestProvider(): array
    {
        return [
            ['GET', 'http://localhost:8057', Response::HTTP_OK, StatusCode::STATUS_UNSET],
            ['GET','http://localhost:8057/404', Response::HTTP_NOT_FOUND, StatusCode::STATUS_ERROR],
            ['POST','http://localhost:8057/json', Response::HTTP_OK, StatusCode::STATUS_UNSET],
            ['DELETE', 'http://localhost:8057/1', Response::HTTP_OK, StatusCode::STATUS_UNSET],
        ];
    }

    /**
     * Only exception events (the lifecycle events added by the instrumentation are filtered out).
     *
     * @return list<EventInterface>
     */
    private static function exceptionEvents(ImmutableSpan $span): array
    {
        return array_values(array_filter(
            $span->getEvents(),
            static fn ($event) => $event->getName() === 'exception',
        ));
    }
}
