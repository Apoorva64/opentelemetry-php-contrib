<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host;

/**
 * Disk usage data structure.
 */
final class DiskData
{
    public function __construct(
        public readonly string $device,
        public readonly int $readsCompleted,
        public readonly int $writesCompleted,
        public readonly int $bytesRead,
        public readonly int $bytesWritten,
        public readonly int $readTime,
        public readonly int $writeTime,
    ) {
    }
}
