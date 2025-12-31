<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Metrics\Host\Unit\Collector;

use OpenTelemetry\Contrib\Metrics\Host\Collector\CpuCollector;
use OpenTelemetry\Contrib\Metrics\Host\CpuUsageData;
use PHPUnit\Framework\TestCase;

class CpuCollectorTest extends TestCase
{
    private static function debugOutput(): bool
    {
        return getenv('HOST_METRICS_DEBUG') === 'true';
    }

    public function test_collect_returns_array_of_cpu_usage_data(): void
    {
        $collector = new CpuCollector();

        // First call initializes the collector
        $result1 = $collector->collect();
        $this->assertIsArray($result1);

        if (self::debugOutput()) {
            echo "\n=== CPU Collector Data (first call) ===\n";
            foreach ($result1 as $cpu) {
                echo sprintf(
                    "CPU %s: user=%.2fs, system=%.2fs, idle=%.2fs | utilization: user=%.2f%%, system=%.2f%%, idle=%.2f%%\n",
                    $cpu->cpuNumber,
                    $cpu->user,
                    $cpu->system,
                    $cpu->idle,
                    $cpu->userPercent * 100,
                    $cpu->systemPercent * 100,
                    $cpu->idlePercent * 100
                );
            }
        }

        // Wait a bit for delta calculation
        usleep(100000); // 100ms delay for better delta

        // Second call should have proper delta calculations
        $result2 = $collector->collect();
        $this->assertIsArray($result2);

        if (self::debugOutput()) {
            echo "\n=== CPU Collector Data (second call - with delta) ===\n";
            foreach ($result2 as $cpu) {
                echo sprintf(
                    "CPU %s: user=%.2fs, system=%.2fs, idle=%.2fs | utilization: user=%.2f%%, system=%.2f%%, idle=%.2f%%\n",
                    $cpu->cpuNumber,
                    $cpu->user,
                    $cpu->system,
                    $cpu->idle,
                    $cpu->userPercent * 100,
                    $cpu->systemPercent * 100,
                    $cpu->idlePercent * 100
                );
            }
        }

        if (count($result2) > 0) {
            foreach ($result2 as $cpuData) {
                $this->assertInstanceOf(CpuUsageData::class, $cpuData);
                $this->assertIsString($cpuData->cpuNumber);

                // Time values should be non-negative
                $this->assertGreaterThanOrEqual(0, $cpuData->user);
                $this->assertGreaterThanOrEqual(0, $cpuData->system);
                $this->assertGreaterThanOrEqual(0, $cpuData->idle);

                // Percentages should be in 0-1 range
                $this->assertGreaterThanOrEqual(0.0, $cpuData->userPercent);
                $this->assertLessThanOrEqual(1.0, $cpuData->userPercent);
                $this->assertGreaterThanOrEqual(0.0, $cpuData->systemPercent);
                $this->assertLessThanOrEqual(1.0, $cpuData->systemPercent);
                $this->assertGreaterThanOrEqual(0.0, $cpuData->idlePercent);
                $this->assertLessThanOrEqual(1.0, $cpuData->idlePercent);
            }
        }
    }
}
