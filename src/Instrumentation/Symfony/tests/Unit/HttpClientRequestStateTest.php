<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\TestClock;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientMetrics;
use OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientRequestState;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\NetworkAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\HttpIncubatingAttributes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientRequestState
 */
final class HttpClientRequestStateTest extends TestCase
{
    /** @var ArrayObject<int, ImmutableSpan> */
    private ArrayObject $storage;
    private TestClock $clock;
    private HttpClientRequestState $state;

    public function setUp(): void
    {
        $this->clock = new TestClock();
        Clock::setDefault($this->clock);
        $this->storage = new ArrayObject();
        $tracerProvider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->storage)));
        $this->state = new HttpClientRequestState(
            $tracerProvider->getTracer('test')->spanBuilder('GET')->startSpan(),
            'GET',
            'https://example.com:8443/posts/1',
        );
    }

    public function tearDown(): void
    {
        Clock::reset();
    }

    public function test_progress_records_last_activity_only_on_change(): void
    {
        $this->state->onProgress(0, ['http_code' => 0]);
        $this->assertNull($this->state->lastActivity());

        $this->clock->advance(10);
        $this->state->onProgress(0, ['http_code' => 200]);
        $headersAt = $this->clock->now();
        $this->assertSame($headersAt, $this->state->lastActivity());

        $this->clock->advance(10);
        $this->state->onProgress(0, ['http_code' => 200]);
        $this->assertSame($headersAt, $this->state->lastActivity());

        $this->clock->advance(10);
        $this->state->onProgress(42, ['http_code' => 200]);
        $this->assertSame($this->clock->now(), $this->state->lastActivity());
    }

    public function test_only_outermost_response_call_is_reported(): void
    {
        $this->state->enterResponseCall();
        $this->state->enterResponseCall();

        $this->assertFalse($this->state->leaveResponseCall());
        $this->assertTrue($this->state->leaveResponseCall());
        $this->assertFalse($this->state->leaveResponseCall());
    }

    public function test_completed_before_call(): void
    {
        $this->state->onProgress(10, ['http_code' => 200]);
        $this->clock->advance(1_000);

        $this->state->enterResponseCall();
        $this->assertTrue($this->state->completedBeforeCall());

        $this->clock->advance(1_000);
        $this->state->onProgress(20, ['http_code' => 200]);
        $this->assertFalse($this->state->completedBeforeCall());
    }

    public function test_finish_ends_span_once(): void
    {
        $this->state->finish($this->response(['http_code' => 200]));
        $this->state->finish($this->response(['http_code' => 500]), new TransportException('late'));

        $this->assertTrue($this->state->isEnded());
        $this->assertCount(1, $this->storage);
        $this->assertSame(StatusCode::STATUS_UNSET, $this->storage[0]->getStatus()->getCode());
        $this->assertSame(200, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
    }

    public function test_finish_uses_given_timestamp(): void
    {
        $this->state->onProgress(10, ['http_code' => 200]);
        $endAt = $this->state->lastActivity();
        $this->clock->advanceSeconds(5);

        $this->state->finish($this->response(['http_code' => 200]), null, $endAt);

        $this->assertSame($endAt, $this->storage[0]->getEndEpochNanos());
    }

    public function test_cancel_is_not_an_error(): void
    {
        $this->state->finish($this->response(['http_code' => 200, 'canceled' => true]), new TransportException('Response has been canceled.'));

        $this->assertSame(StatusCode::STATUS_UNSET, $this->storage[0]->getStatus()->getCode());
        $this->assertFalse($this->storage[0]->getAttributes()->has(ErrorAttributes::ERROR_TYPE));
        $this->assertCount(0, $this->storage[0]->getEvents());
    }

    public function test_canceled_error_response_is_an_error(): void
    {
        $this->state->finish($this->response(['http_code' => 503, 'canceled' => true]));

        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
        $this->assertSame('503', $this->storage[0]->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
    }

    public function test_finish_records_first_and_last_activity_as_events(): void
    {
        $this->state->onProgress(0, ['http_code' => 0]);
        $sentAt = $this->clock->now();
        $this->clock->advance(100);
        $this->state->onProgress(0, ['http_code' => 200]);
        $headersAt = $this->clock->now();
        $this->clock->advance(100);
        $this->state->onProgress(42, ['http_code' => 200]);
        $receivedAt = $this->clock->now();
        $this->clock->advance(100);

        $this->state->finish($this->response(['http_code' => 200]));

        $events = $this->storage[0]->getEvents();
        $this->assertSame('http.getconn', $events[0]->getName());
        $this->assertSame($sentAt, $events[0]->getEpochNanos());
        $this->assertSame('http.headers', $events[1]->getName());
        $this->assertSame($headersAt, $events[1]->getEpochNanos());
        $this->assertSame('http.receive', $events[2]->getName());
        $this->assertSame($receivedAt, $events[2]->getEpochNanos());
    }

    public function test_finish_records_transport_timings_as_events(): void
    {
        $this->state->onProgress(0, ['http_code' => 200]);

        $this->state->finish($this->response([
            'http_code' => 200,
            'namelookup_time' => 0.001,
            'connect_time' => 0.002,
            'appconnect_time' => 0.0025,
            'pretransfer_time' => 0.003,
        ]));

        $events = $this->storage[0]->getEvents();
        $names = array_map(static fn ($e) => $e->getName(), $events);
        $this->assertContains('http.dns', $names);
        $this->assertContains('http.connect', $names);
        $this->assertContains('http.tls', $names);
        $this->assertContains('http.send', $names);
    }

    public function test_finish_sets_transport_attributes(): void
    {
        $this->state->finish($this->response([
            'http_code' => 200,
            'content_type' => 'text/html',
            'redirect_count' => 1,
            'size_download' => 42,
            'primary_ip' => '93.184.216.34',
            'primary_port' => 443,
            'scheme' => 'HTTPS',
            'http_version' => 3,
        ]), null, null, new HttpClientMetrics());

        $attributes = $this->storage[0]->getAttributes();
        $this->assertSame('text/html', $attributes->get('http.response.content_type'));
        $this->assertSame(1, $attributes->get('http.response.redirect_count'));
        // Experimental body-size attributes are only recorded with experimental telemetry.
        $this->assertFalse($attributes->has(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE));
        $this->assertFalse($attributes->has(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE));
        $this->assertSame('93.184.216.34', $attributes->get(NetworkAttributes::NETWORK_PEER_ADDRESS));
        $this->assertSame(443, $attributes->get(NetworkAttributes::NETWORK_PEER_PORT));
        $this->assertSame('https', $attributes->get(UrlAttributes::URL_SCHEME));
        $this->assertSame('2', $attributes->get('network.protocol.version'));
    }

    public function test_finish_records_experimental_telemetry(): void
    {
        $durationCaptures = [];
        $requestBodyCaptures = [];
        $responseBodyCaptures = [];
        $histogram = function (array &$captured): HistogramInterface {
            $h = $this->createMock(HistogramInterface::class);
            $h->method('isEnabled')->willReturn(true);
            $h->method('record')->willReturnCallback(static function (float $value, array $attributes) use (&$captured): void {
                $captured[] = [$value, $attributes];
            });

            return $h;
        };

        $this->state->finish($this->response([
            'http_code' => 200,
            'size_download' => 42,
            'size_upload' => 3,
            'total_time' => 0.1,
        ]), null, null, new HttpClientMetrics(
            $histogram($durationCaptures),
            $histogram($requestBodyCaptures),
            $histogram($responseBodyCaptures),
        ));

        $attributes = $this->storage[0]->getAttributes();
        $this->assertSame(42.0, $attributes->get(HttpIncubatingAttributes::HTTP_RESPONSE_BODY_SIZE));
        $this->assertSame(3.0, $attributes->get(HttpIncubatingAttributes::HTTP_REQUEST_BODY_SIZE));

        $this->assertCount(1, $durationCaptures);
        [$duration] = $durationCaptures[0];
        $this->assertEqualsWithDelta(0.1, $duration, 1e-9);
        $this->assertSame([3.0], array_column($requestBodyCaptures, 0));
        $this->assertSame([42.0], array_column($responseBodyCaptures, 0));
    }

    public function test_finish_records_response_body_size_from_progress_without_info(): void
    {
        $captured = [];
        $responseBody = $this->createMock(HistogramInterface::class);
        $responseBody->method('isEnabled')->willReturn(true);
        $responseBody->method('record')->willReturnCallback(static function (float $value, array $attributes) use (&$captured): void {
            $captured[] = [$value, $attributes];
        });

        // A MockHttpClient's response has no size_download in its info: fall back to the
        // bytes observed through on_progress.
        $this->state->onProgress(13, ['http_code' => 200]);
        $this->state->finish($this->response(['http_code' => 200]), null, null, new HttpClientMetrics(null, null, $responseBody));

        $this->assertCount(1, $captured);
        $this->assertSame(13.0, array_column($captured, 0)[0]);
        $this->assertSame(200, array_column($captured, 1)[0][HttpAttributes::HTTP_RESPONSE_STATUS_CODE] ?? null);
    }

    public function test_no_progress_events_without_transfer(): void
    {
        $this->state->finish(null, new \InvalidArgumentException('Invalid option'));

        $this->assertCount(1, $this->storage[0]->getEvents()); // exception only
        $this->assertSame('exception', $this->storage[0]->getEvents()[0]->getName());
    }

    public function test_status_code_falls_back_to_progress(): void
    {
        $this->state->onProgress(0, ['http_code' => 502]);

        $this->state->finish($this->response([]));

        $this->assertSame(502, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_RESPONSE_STATUS_CODE));
        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
    }

    public function test_forwarded_request_before_transfer_is_not_a_resend(): void
    {
        // A pass-through decorator calls its wrapped client synchronously, before any
        // transport activity: not a retry.
        $this->state->onForwardedRequest();

        $this->state->finish($this->response(['http_code' => 200]));

        $this->assertFalse($this->storage[0]->getAttributes()->has(HttpAttributes::HTTP_REQUEST_RESEND_COUNT));
    }

    public function test_forwarded_request_after_transfer_is_a_resend(): void
    {
        $this->state->onProgress(0, ['http_code' => 500]);
        $this->state->onForwardedRequest(); // first retry
        $this->state->onProgress(1024, ['http_code' => 500]);
        $this->state->onForwardedRequest(); // second retry

        $this->state->finish($this->response(['http_code' => 500]));

        $this->assertSame(2, $this->storage[0]->getAttributes()->get(HttpAttributes::HTTP_REQUEST_RESEND_COUNT));
        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
    }

    public function test_other_exception_is_recorded_without_failing_span(): void
    {
        $this->state->finish($this->response(['http_code' => 200]), new \JsonException('Syntax error'));

        $this->assertSame(StatusCode::STATUS_UNSET, $this->storage[0]->getStatus()->getCode());
        $this->assertCount(1, $this->storage[0]->getEvents());
    }

    public function test_exception_without_response_is_an_error(): void
    {
        $this->state->finish(null, new \InvalidArgumentException('Invalid option'));

        $this->assertSame(StatusCode::STATUS_ERROR, $this->storage[0]->getStatus()->getCode());
        $this->assertSame(\InvalidArgumentException::class, $this->storage[0]->getAttributes()->get(ErrorAttributes::ERROR_TYPE));
    }

    public function test_finish_records_duration_histogram(): void
    {
        $captured = [];
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram->method('isEnabled')->willReturn(true);
        $histogram->method('record')->willReturnCallback(static function (float $duration, iterable $attributes) use (&$captured): void {
            $captured[] = [$duration, $attributes];
        });

        // The duration comes from the transport's total_time, not the clock.
        $this->state->finish($this->response([
            'http_code' => 200,
            'http_version' => 3,
            'total_time' => 0.25,
        ]), null, null, new HttpClientMetrics($histogram));

        // Second finish on the ended state records nothing.
        $this->state->finish($this->response(['http_code' => 200, 'total_time' => 9.0]), null, null, new HttpClientMetrics($histogram));

        $this->assertCount(1, $captured);
        [$duration, $attributes] = $captured[0];
        $this->assertEqualsWithDelta(0.25, $duration, 1e-9);
        $this->assertSame(200, $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE]);
    }

    public function test_finish_records_duration_with_expected_attributes(): void
    {
        $captured = [];
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram->method('isEnabled')->willReturn(true);
        $histogram->method('record')->willReturnCallback(static function (float $duration, iterable $attributes) use (&$captured): void {
            $captured = [$duration, $attributes];
        });

        $this->state->finish($this->response([
            'http_code' => 200,
            'http_version' => 3,
            'total_time' => 1.5,
        ]), null, null, new HttpClientMetrics($histogram));

        $this->assertNotEmpty($captured);
        [$duration, $attributes] = $captured;
        $this->assertEqualsWithDelta(1.5, $duration, 1e-9);
        $this->assertSame('GET', $attributes[HttpAttributes::HTTP_REQUEST_METHOD]);
        $this->assertSame('example.com', $attributes[ServerAttributes::SERVER_ADDRESS]);
        $this->assertSame(8443, $attributes[ServerAttributes::SERVER_PORT]);
        $this->assertSame('https', $attributes['url.scheme']);
        $this->assertSame(200, $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE]);
        $this->assertSame('2', $attributes[NetworkAttributes::NETWORK_PROTOCOL_VERSION]);
        $this->assertArrayNotHasKey(ErrorAttributes::ERROR_TYPE, $attributes);
    }

    public function test_finish_records_duration_with_error_type(): void
    {
        $captured = [];
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram->method('isEnabled')->willReturn(true);
        $histogram->method('record')->willReturnCallback(static function (float $duration, iterable $attributes) use (&$captured): void {
            $captured = [$duration, $attributes];
        });

        $this->state->finish(
            $this->response(['http_code' => 503, 'total_time' => 0.1]),
            null,
            null,
            new HttpClientMetrics($histogram),
        );

        $this->assertNotEmpty($captured);
        [$duration, $attributes] = $captured;
        $this->assertEqualsWithDelta(0.1, $duration, 1e-9);
        $this->assertSame('503', $attributes[ErrorAttributes::ERROR_TYPE]);
        $this->assertSame(503, $attributes[HttpAttributes::HTTP_RESPONSE_STATUS_CODE]);
    }

    public function test_finish_records_duration_with_transport_error(): void
    {
        $captured = [];
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram->method('isEnabled')->willReturn(true);
        $histogram->method('record')->willReturnCallback(static function (float $duration, iterable $attributes) use (&$captured): void {
            $captured = [$duration, $attributes];
        });

        $this->state->finish($this->response(['total_time' => 0.05]), new TransportException('connection refused'), null, new HttpClientMetrics($histogram));

        $this->assertNotEmpty($captured);
        [$duration, $attributes] = $captured;
        $this->assertEqualsWithDelta(0.05, $duration, 1e-9);
        $this->assertSame(TransportException::class, $attributes[ErrorAttributes::ERROR_TYPE]);
        $this->assertArrayNotHasKey(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $attributes);
    }

    public function test_finish_records_duration_of_abandoned_response_from_transport_time(): void
    {
        $captured = [];
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram->method('isEnabled')->willReturn(true);
        $histogram->method('record')->willReturnCallback(static function (float $duration, iterable $attributes) use (&$captured): void {
            $captured = [$duration, $attributes];
        });

        $this->state->onProgress(0, ['http_code' => 200]);
        $this->clock->advanceSeconds(1);
        $this->state->onProgress(42, ['http_code' => 200]);
        // Abandoned responses end at their last transport activity, but the duration
        // still comes from the transport's own total_time.
        $this->state->finish($this->response(['http_code' => 200, 'total_time' => 0.75]), null, $this->state->lastActivity(), new HttpClientMetrics($histogram));

        $this->assertNotEmpty($captured);
        [$duration, $attributes] = $captured;
        $this->assertEqualsWithDelta(0.75, $duration, 1e-9);
    }

    public function test_finish_skips_metric_without_transport_time(): void
    {
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram->method('isEnabled')->willReturn(true);
        $histogram->expects($this->never())->method('record');

        // No response (request() itself threw) and no total_time: nothing to measure.
        $this->state->finish(null, new TransportException('connection refused'), null, new HttpClientMetrics($histogram));
    }

    public function test_disabled_histogram_is_not_recorded(): void
    {
        $histogram = $this->createMock(HistogramInterface::class);
        $histogram->method('isEnabled')->willReturn(false);
        $histogram->expects($this->never())->method('record');

        $this->state->finish($this->response(['http_code' => 200, 'total_time' => 0.1]), null, null, new HttpClientMetrics($histogram));
    }

    public function test_no_histogram_is_accepted(): void
    {
        $this->state->finish($this->response(['http_code' => 200, 'total_time' => 0.1]));

        $this->assertCount(1, $this->storage);
    }

    private function response(array $info): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getInfo')->willReturn($info);

        return $response;
    }
}
