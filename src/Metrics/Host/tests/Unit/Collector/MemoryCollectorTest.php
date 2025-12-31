<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Metrics\Host\Unit\Collector;

use OpenTelemetry\Contrib\Metrics\Host\Collector\MemoryCollector;
use OpenTelemetry\Contrib\Metrics\Host\MemoryData;
use PHPUnit\Framework\TestCase;

class MemoryCollectorTest extends TestCase
{
    private static function debugOutput(): bool
    {
        return getenv('HOST_METRICS_DEBUG') === 'true';
    }

    public function test_collect_returns_memory_data_or_null(): void
    {
        $collector = new MemoryCollector();
        $result = $collector->collect();

        // On supported platforms, we should get data; on unsupported, null
        if ($result !== null) {
            if (self::debugOutput()) {
                echo "\n=== System Memory Collector Data ===\n";
                echo sprintf(
                    "Total:     %s bytes (%.2f GB)\n" .
                    "Used:      %s bytes (%.2f GB) - %.2f%%\n" .
                    "Free:      %s bytes (%.2f GB) - %.2f%%\n" .
                    "Available: %s bytes (%.2f GB)\n" .
                    "Buffers:   %s bytes (%.2f MB)\n" .
                    "Cached:    %s bytes (%.2f GB)\n",
                    number_format($result->total), $result->total / 1024 / 1024 / 1024,
                    number_format($result->used), $result->used / 1024 / 1024 / 1024, $result->usedPercent * 100,
                    number_format($result->free), $result->free / 1024 / 1024 / 1024, $result->freePercent * 100,
                    number_format($result->available), $result->available / 1024 / 1024 / 1024,
                    number_format($result->buffers), $result->buffers / 1024 / 1024,
                    number_format($result->cached), $result->cached / 1024 / 1024 / 1024
                );
            }

            $this->assertInstanceOf(MemoryData::class, $result);
            $this->assertGreaterThan(0, $result->total);
            $this->assertGreaterThanOrEqual(0, $result->free);
            $this->assertGreaterThanOrEqual(0, $result->used);
            $this->assertGreaterThanOrEqual(0.0, $result->usedPercent);
            $this->assertLessThanOrEqual(1.0, $result->usedPercent);
            $this->assertGreaterThanOrEqual(0.0, $result->freePercent);
            $this->assertLessThanOrEqual(1.0, $result->freePercent);
        } else {
            if (self::debugOutput()) {
                echo "\n=== System Memory Collector Data ===\n";
                echo "No data returned (not running on Linux)\n";
            }
            $this->assertNull($result);
        }
    }
}
