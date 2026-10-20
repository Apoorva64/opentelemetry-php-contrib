<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Unit;

use ArrayObject;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Common\Time\TestClock;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientRequestState;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
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
        $this->state = new HttpClientRequestState($tracerProvider->getTracer('test')->spanBuilder('GET')->startSpan());
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
        $this->assertSame('http.client.request.sent', $events[0]->getName());
        $this->assertSame($sentAt, $events[0]->getEpochNanos());
        $this->assertSame('http.client.response.headers_received', $events[1]->getName());
        $this->assertSame($headersAt, $events[1]->getEpochNanos());
        $this->assertSame('http.client.response.received', $events[2]->getName());
        $this->assertSame($receivedAt, $events[2]->getEpochNanos());
    }

    public function test_finish_records_transport_timings_as_events(): void
    {
        $this->state->onProgress(0, ['http_code' => 200]);

        $this->state->finish($this->response([
            'http_code' => 200,
            'namelookup_time' => 0.001,
            'connect_time' => 0.002,
            'pretransfer_time' => 0.003,
        ]));

        $events = $this->storage[0]->getEvents();
        $names = array_map(static fn ($e) => $e->getName(), $events);
        $this->assertContains('http.client.dns_resolved', $names);
        $this->assertContains('http.client.connected', $names);
        $this->assertContains('http.client.request.sent_transport', $names);
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
        ]));

        $attributes = $this->storage[0]->getAttributes();
        $this->assertSame('text/html', $attributes->get('http.response.content_type'));
        $this->assertSame(1, $attributes->get('http.response.redirect_count'));
        $this->assertSame(42, $attributes->get('http.response.body.size'));
        $this->assertSame('93.184.216.34', $attributes->get('network.peer.address'));
        $this->assertSame(443, $attributes->get('network.peer.port'));
        $this->assertSame('https', $attributes->get('http.scheme'));
        $this->assertSame('2', $attributes->get('network.protocol.version'));
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

    private function response(array $info): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getInfo')->willReturn($info);

        return $response;
    }
}
