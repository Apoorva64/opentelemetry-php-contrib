<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\Symfony\HttpServerMetrics;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\Symfony\HttpServerMetrics
 */
final class HttpServerMetricsTest extends TestCase
{
    public function setUp(): void
    {
        unset(
            $_ENV['OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY'],
            $_SERVER['OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY'],
        );
    }

    public function test_experimental_telemetry_is_disabled_by_default(): void
    {
        $this->assertFalse(HttpServerMetrics::experimentalTelemetryEnabled());
    }

    public function test_experimental_telemetry_can_be_enabled(): void
    {
        foreach (['true', 'TRUE', ' 1 '] as $value) {
            $_ENV['OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY'] = $value;
            $this->assertTrue(HttpServerMetrics::experimentalTelemetryEnabled());
        }

        foreach (['false', '0', 'yes', ''] as $value) {
            $_ENV['OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY'] = $value;
            $this->assertFalse(HttpServerMetrics::experimentalTelemetryEnabled());
        }
    }

    public function test_known_methods_are_recorded_verbatim(): void
    {
        foreach (['GET', 'POST', 'QUERY'] as $method) {
            $this->assertSame($method, HttpServerMetrics::requestMethod($method));
        }
    }

    public function test_unknown_methods_are_recorded_as_other(): void
    {
        $this->assertSame('_OTHER', HttpServerMetrics::requestMethod('PROPFIND'));
        $this->assertSame('_OTHER', HttpServerMetrics::requestMethod(''));
    }

    public function test_http_1_x_versions_are_recorded_by_full_version(): void
    {
        $this->assertSame('1.0', HttpServerMetrics::protocolVersion('1.0'));
        $this->assertSame('1.1', HttpServerMetrics::protocolVersion('1.1'));
    }

    public function test_http_2_and_later_are_recorded_by_major_version(): void
    {
        $this->assertSame('2', HttpServerMetrics::protocolVersion('2.0'));
        $this->assertSame('3', HttpServerMetrics::protocolVersion('3.0'));
        $this->assertSame('2', HttpServerMetrics::protocolVersion('2'));
    }
}
