<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host;

/**
 * Network usage data structure.
 */
final class NetworkData
{
    public function __construct(
        public readonly string $interface,
        public readonly int $bytesReceived,
        public readonly int $bytesTransmitted,
        public readonly int $packetsReceived,
        public readonly int $packetsTransmitted,
        public readonly int $errorsReceived,
        public readonly int $errorsTransmitted,
        public readonly int $droppedReceived,
        public readonly int $droppedTransmitted,
    ) {
    }
}
