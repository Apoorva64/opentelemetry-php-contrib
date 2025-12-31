<?php

/**
 * Example: Basic usage of OpenTelemetry Host Metrics
 *
 * This example demonstrates how to collect and export host metrics using
 * OpenTelemetry PHP SDK.
 *
 * Run this script with:
 *   php examples/basic_usage.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenTelemetry\Contrib\Metrics\Host\HostMetrics;
use OpenTelemetry\Contrib\Metrics\Host\HostMetricsConfig;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Resource\ResourceInfo;

// For this example, we'll use a simple console exporter
// In production, you would use OTLP, Prometheus, or another exporter

/**
 * Simple metric exporter that prints metrics to console
 */
class ConsoleMetricExporter implements \OpenTelemetry\SDK\Metrics\MetricExporterInterface
{
    public function temporality(\OpenTelemetry\SDK\Metrics\Data\Metric $metric): Temporality
    {
        return Temporality::CUMULATIVE;
    }

    public function export(iterable $batch): bool
    {
        foreach ($batch as $metric) {
            echo sprintf(
                "[%s] %s (%s): %s\n",
                date('Y-m-d H:i:s'),
                $metric->name,
                $metric->unit ?? 'unit',
                $metric->description
            );

            foreach ($metric->data->dataPoints as $dataPoint) {
                $attributes = [];
                foreach ($dataPoint->attributes as $key => $value) {
                    $attributes[] = "$key=$value";
                }
                $attrStr = implode(', ', $attributes);
                $value = $dataPoint->value ?? $dataPoint->sum ?? 0;
                echo sprintf("  - [%s] = %s\n", $attrStr, $value);
            }
        }
        return true;
    }

    public function shutdown(): bool
    {
        return true;
    }

    public function forceFlush(): bool
    {
        return true;
    }
}

echo "OpenTelemetry Host Metrics Example\n";
echo "==================================\n\n";

// Create the meter provider with console exporter
$exporter = new ConsoleMetricExporter();
$reader = new ExportingReader($exporter);

$meterProvider = MeterProvider::builder()
    ->setResource(ResourceInfo::create(Attributes::create([
        'service.name' => 'host-metrics-example',
    ])))
    ->addReader($reader)
    ->build();

// Option 1: Collect all metrics (default)
echo "Starting host metrics collection (all groups)...\n\n";
$hostMetrics = new HostMetrics($meterProvider);
$hostMetrics->start();

// Trigger a collection
$reader->collect();

echo "\n--- Waiting 2 seconds for next collection ---\n\n";
sleep(2);

// Trigger another collection to see utilization metrics
$reader->collect();

// Option 2: Collect only specific metric groups
echo "\n\n=== Using specific metric groups ===\n\n";

$config = new HostMetricsConfig(
    metricGroups: [
        HostMetricsConfig::GROUP_SYSTEM_CPU,
        HostMetricsConfig::GROUP_PROCESS_MEMORY,
    ],
    name: 'filtered-host-metrics',
);

$hostMetrics2 = new HostMetrics($meterProvider, $config);
$hostMetrics2->start();

$reader->collect();

echo "\nDone!\n";

// Shutdown
$meterProvider->shutdown();
