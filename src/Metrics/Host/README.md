[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-metrics-host/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Metrics/Host)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-metrics-host)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-host-metrics/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-host-metrics/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-host-metrics/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-host-metrics/)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Host Metrics for PHP

This package provides automatic collection of Host Metrics which includes metrics for:

- CPU
- Memory
- Network
- Disk
- Process CPU
- Process Memory

Compatible with OpenTelemetry PHP API and SDK `1.0+`.

## Requirements

- PHP 8.1+
- OpenTelemetry SDK

## Installation

```bash
composer require open-telemetry/opentelemetry-host-metrics
```

## Usage

```php
<?php

use OpenTelemetry\Contrib\Metrics\Host\HostMetrics;
use OpenTelemetry\Contrib\Metrics\Host\HostMetricsConfig;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\Contrib\Otlp\MetricExporter;

// Create a meter provider with an exporter
$exporter = new MetricExporter(/* ... */);
$reader = new ExportingReader($exporter);
$meterProvider = MeterProvider::builder()
    ->addReader($reader)
    ->build();

// Create and start host metrics collection
$hostMetrics = new HostMetrics($meterProvider);
$hostMetrics->start();

// Your application code...
// Metrics will be collected and exported periodically
```

### Using SDK Autoloading

If you use [OpenTelemetry SDK autoloading](https://opentelemetry.io/docs/instrumentation/php/sdk/#autoloading), you can retrieve the global meter provider. This simplifies setup and allows configuration via environment variables.

```php
<?php

use OpenTelemetry\API\Globals;
use OpenTelemetry\Contrib\Metrics\Host\HostMetrics;

// Configure via environment variables (or php.ini)
putenv('OTEL_PHP_AUTOLOAD_ENABLED=true');
putenv('OTEL_METRICS_EXPORTER=otlp');
putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf');
putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://collector:4318');

require 'vendor/autoload.php';

// Get the globally configured MeterProvider
$meterProvider = Globals::meterProvider();

// Create and start host metrics collection
$hostMetrics = new HostMetrics($meterProvider);
$hostMetrics->start();

// Your application code...
```

See [autoload example](./examples/autoload.php) for a complete example.

## Configuration

You can configure which metric groups to collect:

```php
<?php

use OpenTelemetry\Contrib\Metrics\Host\HostMetrics;
use OpenTelemetry\Contrib\Metrics\Host\HostMetricsConfig;

// Collect only CPU and memory metrics
$config = new HostMetricsConfig(
    metricGroups: [
        HostMetricsConfig::GROUP_SYSTEM_CPU,
        HostMetricsConfig::GROUP_SYSTEM_MEMORY,
    ],
    name: 'my-app-host-metrics',
);

$hostMetrics = new HostMetrics($meterProvider, $config);
$hostMetrics->start();
```

Available metric groups:
- `system.cpu` - System CPU metrics
- `system.memory` - System memory metrics
- `system.network` - Network I/O metrics
- `system.disk` - Disk I/O metrics
- `process.cpu` - Process CPU metrics
- `process.memory` - Process memory metrics

## Semantic Conventions

This package uses [OpenTelemetry Semantic Conventions](https://opentelemetry.io/docs/specs/semconv/system/system-metrics/).

### Metrics Collected

| Metric                      | Description                                         | Unit    |
|-----------------------------|-----------------------------------------------------|---------|
| **Group `system.cpu`**      |                                                     |         |
| `system.cpu.time`           | Seconds each logical CPU spent on each mode         | s       |
| `system.cpu.utilization`    | CPU usage time (0-1)                                | 1       |
| **Group `system.memory`**   |                                                     |         |
| `system.memory.usage`       | Reports memory in use by state                      | By      |
| `system.memory.utilization` | Memory usage (0-1)                                  | 1       |
| **Group `system.network`**  |                                                     |         |
| `system.network.io`         | Network bytes transmitted/received                  | By      |
| `system.network.errors`     | Count of network errors detected                    | {error} |
| `system.network.dropped`    | Count of packets that are dropped                   | {packet}|
| **Group `system.disk`**     |                                                     |         |
| `system.disk.io`            | Disk bytes transferred                              | By      |
| `system.disk.operations`    | Disk read/write operations                          | {operation}|
| `system.disk.io_time`       | Time disk spent activated                           | ms      |
| **Group `process.cpu`**     |                                                     |         |
| `process.cpu.time`          | Total CPU seconds                                   | s       |
| `process.cpu.utilization`   | Process CPU utilization (0-1)                       | 1       |
| **Group `process.memory`**  |                                                     |         |
| `process.memory.usage`      | The amount of physical memory in use                | By      |

### Attributes

| Attribute                   | Description                                         |
|-----------------------------|-----------------------------------------------------|
| `system.cpu.logical_number` | The logical CPU number                              |
| `system.cpu.state`          | The CPU state (user, system, idle, nice, iowait, interrupt) |
| `system.memory.state`       | The memory state (used, free, buffers, cached)      |
| `system.device`             | The device identifier                               |
| `network.io.direction`      | The network I/O direction (receive, transmit)       |
| `disk.io.direction`         | The disk I/O direction (read, write)                |
| `process.cpu.state`         | The process CPU state (user, system)                |

## Platform Support

This package supports **Linux only**. It reads metrics from `/proc` filesystem:

- `/proc/stat` - CPU statistics
- `/proc/meminfo` - Memory statistics
- `/proc/net/dev` - Network statistics
- `/proc/diskstats` - Disk I/O statistics
- `/proc/self/stat` - Process CPU statistics
- `/proc/self/status` - Process memory statistics

## Example with Prometheus Exporter

```php
<?php

use OpenTelemetry\Contrib\Metrics\Host\HostMetrics;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\Contrib\Prometheus\PrometheusExporter;

// Set up Prometheus exporter
$exporter = new PrometheusExporter();
$reader = new ExportingReader($exporter);

$meterProvider = MeterProvider::builder()
    ->addReader($reader)
    ->build();

$hostMetrics = new HostMetrics($meterProvider);
$hostMetrics->start();

// Metrics will be available at your Prometheus scrape endpoint
```

## Useful Links

- For more information on OpenTelemetry, visit: https://opentelemetry.io/
- For OpenTelemetry PHP: https://opentelemetry.io/docs/languages/php/
- For semantic conventions: https://opentelemetry.io/docs/specs/semconv/system/system-metrics/

## License

Apache 2.0 - See [LICENSE](https://github.com/open-telemetry/opentelemetry-php-contrib/blob/main/LICENSE) for more information.
