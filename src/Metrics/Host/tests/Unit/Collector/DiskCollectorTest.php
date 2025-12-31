<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Metrics\Host\Unit\Collector;

use OpenTelemetry\Contrib\Metrics\Host\Collector\DiskCollector;
use OpenTelemetry\Contrib\Metrics\Host\DiskData;
use PHPUnit\Framework\TestCase;

class DiskCollectorTest extends TestCase
{
    private static function debugOutput(): bool
    {
        return getenv('HOST_METRICS_DEBUG') === 'true';
    }

    public function test_collect_returns_array_of_disk_data(): void
    {
        $collector = new DiskCollector();
        $result = $collector->collect();

        $this->assertIsArray($result);

        if (self::debugOutput()) {
            echo "\n=== Disk Collector Data ===\n";
            if (count($result) === 0) {
                echo "No disk devices found (may not be running on Linux or no physical disks detected)\n";
            }
            foreach ($result as $diskData) {
                echo sprintf(
                    "Device: %s\n  Reads: %d ops, %s bytes, %dms\n  Writes: %d ops, %s bytes, %dms\n",
                    $diskData->device,
                    $diskData->readsCompleted,
                    number_format($diskData->bytesRead),
                    $diskData->readTime,
                    $diskData->writesCompleted,
                    number_format($diskData->bytesWritten),
                    $diskData->writeTime
                );
            }
        }

        foreach ($result as $diskData) {
            $this->assertInstanceOf(DiskData::class, $diskData);
            $this->assertIsString($diskData->device);
            $this->assertNotEmpty($diskData->device);

            // Operation counts and bytes should be non-negative
            $this->assertGreaterThanOrEqual(0, $diskData->readsCompleted);
            $this->assertGreaterThanOrEqual(0, $diskData->writesCompleted);
            $this->assertGreaterThanOrEqual(0, $diskData->bytesRead);
            $this->assertGreaterThanOrEqual(0, $diskData->bytesWritten);
            $this->assertGreaterThanOrEqual(0, $diskData->readTime);
            $this->assertGreaterThanOrEqual(0, $diskData->writeTime);
        }
    }
}
