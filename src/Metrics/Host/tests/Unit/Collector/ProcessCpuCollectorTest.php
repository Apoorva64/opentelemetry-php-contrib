<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Metrics\Host\Unit\Collector;

use OpenTelemetry\Contrib\Metrics\Host\Collector\ProcessCpuCollector;
use OpenTelemetry\Contrib\Metrics\Host\ProcessCpuData;
use PHPUnit\Framework\TestCase;

class ProcessCpuCollectorTest extends TestCase
{
    private static function debugOutput(): bool
    {
        return getenv('HOST_METRICS_DEBUG') === 'true';
    }

    public function test_collect_returns_process_cpu_data(): void
    {
        $collector = new ProcessCpuCollector();

        // First call initializes the collector
        $result1 = $collector->collect();

        if (self::debugOutput()) {
            echo "\n=== Process CPU Collector Data (first call) ===\n";
            if ($result1 !== null) {
                echo sprintf(
                    "User time: %.4fs, System time: %.4fs\nUtilization: user=%.4f%%, system=%.4f%%\n",
                    $result1->userTime,
                    $result1->systemTime,
                    $result1->userPercent * 100,
                    $result1->systemPercent * 100
                );
            } else {
                echo "No data returned\n";
            }
        }

        // Wait a bit for delta calculation
        usleep(100000); // 100ms delay for better delta

        // Second call should have proper delta calculations
        $result2 = $collector->collect();

        if (self::debugOutput()) {
            echo "\n=== Process CPU Collector Data (second call - with delta) ===\n";
            if ($result2 !== null) {
                echo sprintf(
                    "User time: %.4fs, System time: %.4fs\nUtilization: user=%.4f%%, system=%.4f%%\n",
                    $result2->userTime,
                    $result2->systemTime,
                    $result2->userPercent * 100,
                    $result2->systemPercent * 100
                );
            } else {
                echo "No data returned\n";
            }
        }

        if ($result2 !== null) {
            $this->assertInstanceOf(ProcessCpuData::class, $result2);

            // Time values should be non-negative
            $this->assertGreaterThanOrEqual(0, $result2->userTime);
            $this->assertGreaterThanOrEqual(0, $result2->systemTime);

            // Percentages should be in 0-1 range
            $this->assertGreaterThanOrEqual(0.0, $result2->userPercent);
            $this->assertLessThanOrEqual(1.0, $result2->userPercent);
            $this->assertGreaterThanOrEqual(0.0, $result2->systemPercent);
            $this->assertLessThanOrEqual(1.0, $result2->systemPercent);
        }
    }
}
