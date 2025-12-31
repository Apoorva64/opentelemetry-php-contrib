<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Metrics\Host\Unit;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObservableCounterInterface;
use OpenTelemetry\API\Metrics\ObservableGaugeInterface;
use OpenTelemetry\Contrib\Metrics\Host\HostMetrics;
use OpenTelemetry\Contrib\Metrics\Host\HostMetricsConfig;
use PHPUnit\Framework\TestCase;

class HostMetricsTest extends TestCase
{
    public function test_start_creates_metrics(): void
    {
        $observableCallback = $this->createMock(ObservableCallbackInterface::class);

        $observableCounter = $this->createMock(ObservableCounterInterface::class);
        $observableCounter->method('observe')->willReturn($observableCallback);

        $observableGauge = $this->createMock(ObservableGaugeInterface::class);
        $observableGauge->method('observe')->willReturn($observableCallback);

        $meter = $this->createMock(MeterInterface::class);
        $meter->expects($this->atLeastOnce())
            ->method('createObservableCounter')
            ->willReturn($observableCounter);
        $meter->expects($this->atLeastOnce())
            ->method('createObservableGauge')
            ->willReturn($observableGauge);

        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->expects($this->once())
            ->method('getMeter')
            ->with('io.opentelemetry.contrib.php.host_metrics', '0.1.0')
            ->willReturn($meter);

        $hostMetrics = new HostMetrics($meterProvider);
        $hostMetrics->start();
    }

    public function test_start_with_custom_config(): void
    {
        $observableCallback = $this->createMock(ObservableCallbackInterface::class);

        $observableCounter = $this->createMock(ObservableCounterInterface::class);
        $observableCounter->method('observe')->willReturn($observableCallback);

        $observableGauge = $this->createMock(ObservableGaugeInterface::class);
        $observableGauge->method('observe')->willReturn($observableCallback);

        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createObservableCounter')->willReturn($observableCounter);
        $meter->method('createObservableGauge')->willReturn($observableGauge);

        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->expects($this->once())
            ->method('getMeter')
            ->with('custom-meter', '0.1.0')
            ->willReturn($meter);

        $config = new HostMetricsConfig(
            metricGroups: [HostMetricsConfig::GROUP_SYSTEM_CPU],
            name: 'custom-meter',
        );

        $hostMetrics = new HostMetrics($meterProvider, $config);
        $hostMetrics->start();
    }

    public function test_start_with_limited_metric_groups(): void
    {
        $observableCallback = $this->createMock(ObservableCallbackInterface::class);

        $observableCounter = $this->createMock(ObservableCounterInterface::class);
        $observableCounter->method('observe')->willReturn($observableCallback);

        $observableGauge = $this->createMock(ObservableGaugeInterface::class);
        $observableGauge->method('observe')->willReturn($observableCallback);

        $meter = $this->createMock(MeterInterface::class);

        // With only system.cpu group enabled, we should only create cpu metrics
        $meter->expects($this->once())
            ->method('createObservableCounter')
            ->with('system.cpu.time', $this->anything(), $this->anything())
            ->willReturn($observableCounter);

        $meter->expects($this->once())
            ->method('createObservableGauge')
            ->with('system.cpu.utilization', $this->anything(), $this->anything())
            ->willReturn($observableGauge);

        $meterProvider = $this->createMock(MeterProviderInterface::class);
        $meterProvider->method('getMeter')->willReturn($meter);

        $config = new HostMetricsConfig(
            metricGroups: [HostMetricsConfig::GROUP_SYSTEM_CPU],
        );

        $hostMetrics = new HostMetrics($meterProvider, $config);
        $hostMetrics->start();
    }
}
