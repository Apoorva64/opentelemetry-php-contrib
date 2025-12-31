# Host Metrics Tests

This directory contains unit tests for the OpenTelemetry Host Metrics collectors.

## Running Tests

```bash
# Run all unit tests
./vendor/bin/phpunit --testsuite unit

# Run with verbose output
./vendor/bin/phpunit --testsuite unit --testdox
```

## Debug Output

The collector tests can optionally output the actual metrics data being collected. This is useful for:

- Verifying that collectors are reading real system data
- Debugging collector implementations
- Understanding what metrics are available on your system

### Enabling Debug Output

Set the `HOST_METRICS_DEBUG` environment variable to `true`:

```bash
# Linux/macOS
HOST_METRICS_DEBUG=true ./vendor/bin/phpunit --testsuite unit

# Windows PowerShell
$env:HOST_METRICS_DEBUG="true"; ./vendor/bin/phpunit --testsuite unit

# Windows CMD
set HOST_METRICS_DEBUG=true && ./vendor/bin/phpunit --testsuite unit
```

### Example Debug Output

When enabled, you'll see output like:

```
=== CPU Collector Data (first call) ===
CPU 0: user=134.02s, system=402.35s, idle=46783.54s | utilization: user=0.00%, system=0.00%, idle=100.00%
CPU 1: user=131.25s, system=184.10s, idle=47127.37s | utilization: user=0.00%, system=0.00%, idle=100.00%

=== System Memory Collector Data ===
Total:     16,355,905,536 bytes (15.23 GB)
Used:      3,105,611,776 bytes (2.89 GB) - 18.99%
Free:      12,613,840,896 bytes (11.75 GB) - 77.12%

=== Network Collector Data ===
Interface: eth0
  RX: 72,719,528 bytes, 558282 packets, 0 errors, 0 dropped
  TX: 19,064,969 bytes, 38400 packets, 0 errors, 0 dropped

=== Disk Collector Data ===
Device: sda
  Reads: 1869 ops, 104,031,232 bytes, 1633ms
  Writes: 0 ops, 0 bytes, 0ms

=== Process CPU Collector Data ===
User time: 0.0968s, System time: 0.2233s
Utilization: user=0.2325%, system=0.5382%

=== Process Memory Collector Data ===
Current memory usage: 8,388,608 bytes (8.00 MB)
```

## Test Structure

```
tests/
├── Unit/
│   ├── Collector/
│   │   ├── CpuCollectorTest.php        # System CPU metrics
│   │   ├── MemoryCollectorTest.php     # System memory metrics
│   │   ├── NetworkCollectorTest.php    # Network interface metrics
│   │   ├── DiskCollectorTest.php       # Disk I/O metrics
│   │   ├── ProcessCpuCollectorTest.php # Process CPU usage
│   │   └── ProcessMemoryCollectorTest.php # Process memory usage
│   ├── HostMetricsTest.php             # Main HostMetrics class
│   └── HostMetricsConfigTest.php       # Configuration options
└── Integration/
    └── (future integration tests)
```

## Platform Support

These tests are designed to run on **Linux only**. The collectors read from the `/proc` filesystem which is specific to Linux.

When running on non-Linux platforms:
- Some collectors may return `null` or empty arrays
- Tests will still pass but with limited assertions
