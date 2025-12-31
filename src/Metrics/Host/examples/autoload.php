<?php

declare(strict_types=1);

/**
 * This example uses OpenTelemetry SDK autoloading to configure a MeterProvider, which will be used
 * by HostMetrics to export host and process metrics.
 * Metrics are protobuf-encoded and sent to an OpenTelemetry collector.
 */

use OpenTelemetry\API\Globals;
use OpenTelemetry\Contrib\Metrics\Host\HostMetrics;
use OpenTelemetry\Contrib\Metrics\Host\HostMetricsConfig;

// Configure OpenTelemetry via environment variables
putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_METRICS_EXPORTER=otlp');
putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf');
putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');
putenv('OTEL_SERVICE_NAME=my-php-app');

require dirname(__DIR__) . '/vendor/autoload.php';

// Get the globally configured MeterProvider
$meterProvider = Globals::meterProvider();

// Option 1: Start with default configuration (all metric groups)
$hostMetrics = new HostMetrics($meterProvider);
$hostMetrics->start();

// Option 2: Start with custom configuration (only specific metric groups)
// $config = new HostMetricsConfig(
//     metricGroups: [
//         HostMetricsConfig::GROUP_SYSTEM_CPU,
//         HostMetricsConfig::GROUP_SYSTEM_MEMORY,
//         HostMetricsConfig::GROUP_PROCESS_MEMORY,
//     ],
//     name: 'my-app-host-metrics',
// );
// $hostMetrics = new HostMetrics($meterProvider, $config);
// $hostMetrics->start();

echo "Host metrics collection started.\n";
echo "Metrics will be exported to: " . getenv('OTEL_EXPORTER_OTLP_ENDPOINT') . "\n";
echo "Press Ctrl+C to stop.\n";

// Simulate a long-running application
// In a real application, this would be your main application loop
while (true) {
    // Your application work here...
    sleep(1);
}
