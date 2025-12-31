<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Metrics\Host\Unit\Collector;

use OpenTelemetry\Contrib\Metrics\Host\Collector\ProcessMemoryCollector;
use PHPUnit\Framework\TestCase;

class ProcessMemoryCollectorTest extends TestCase
{
    private static function debugOutput(): bool
    {
        return getenv('HOST_METRICS_DEBUG') === 'true';
    }

    public function test_collect_returns_positive_memory_usage(): void
    {
        $collector = new ProcessMemoryCollector();
        $result = $collector->collect();

        if (self::debugOutput()) {
            echo "\n=== Process Memory Collector Data ===\n";
            echo sprintf("Current memory usage: %s bytes (%.2f MB)\n", number_format($result), $result / 1024 / 1024);
        }

        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    public function test_collect_peak_returns_positive_memory_usage(): void
    {
        $collector = new ProcessMemoryCollector();
        $result = $collector->collectPeak();

        if (self::debugOutput()) {
            echo "\n=== Process Memory Peak Data ===\n";
            echo sprintf("Peak memory usage: %s bytes (%.2f MB)\n", number_format($result), $result / 1024 / 1024);
        }

        $this->assertIsInt($result);
        $this->assertGreaterThan(0, $result);
    }

    public function test_peak_is_greater_or_equal_to_current(): void
    {
        $collector = new ProcessMemoryCollector();

        $current = $collector->collect();
        $peak = $collector->collectPeak();

        if (self::debugOutput()) {
            echo "\n=== Memory Comparison ===\n";
            echo sprintf("Current: %s bytes (%.2f MB)\n", number_format($current), $current / 1024 / 1024);
            echo sprintf("Peak: %s bytes (%.2f MB)\n", number_format($peak), $peak / 1024 / 1024);
        }

        $this->assertGreaterThanOrEqual($current, $peak);
    }
}
