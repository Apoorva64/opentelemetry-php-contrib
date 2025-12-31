<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Metrics\Host\Unit\Collector;

use OpenTelemetry\Contrib\Metrics\Host\Collector\NetworkCollector;
use OpenTelemetry\Contrib\Metrics\Host\NetworkData;
use PHPUnit\Framework\TestCase;

class NetworkCollectorTest extends TestCase
{
    private static function debugOutput(): bool
    {
        return getenv('HOST_METRICS_DEBUG') === 'true';
    }

    public function test_collect_returns_array_of_network_data(): void
    {
        $collector = new NetworkCollector();
        $result = $collector->collect();

        $this->assertIsArray($result);

        if (self::debugOutput()) {
            echo "\n=== Network Collector Data ===\n";
            foreach ($result as $networkData) {
                echo sprintf(
                    "Interface: %s\n  RX: %s bytes, %d packets, %d errors, %d dropped\n  TX: %s bytes, %d packets, %d errors, %d dropped\n",
                    $networkData->interface,
                    number_format($networkData->bytesReceived),
                    $networkData->packetsReceived,
                    $networkData->errorsReceived,
                    $networkData->droppedReceived,
                    number_format($networkData->bytesTransmitted),
                    $networkData->packetsTransmitted,
                    $networkData->errorsTransmitted,
                    $networkData->droppedTransmitted
                );
            }
        }

        foreach ($result as $networkData) {
            $this->assertInstanceOf(NetworkData::class, $networkData);
            $this->assertIsString($networkData->interface);
            $this->assertNotEmpty($networkData->interface);

            // Byte counts should be non-negative
            $this->assertGreaterThanOrEqual(0, $networkData->bytesReceived);
            $this->assertGreaterThanOrEqual(0, $networkData->bytesTransmitted);
            $this->assertGreaterThanOrEqual(0, $networkData->errorsReceived);
            $this->assertGreaterThanOrEqual(0, $networkData->errorsTransmitted);
            $this->assertGreaterThanOrEqual(0, $networkData->droppedReceived);
            $this->assertGreaterThanOrEqual(0, $networkData->droppedTransmitted);
        }
    }
}
