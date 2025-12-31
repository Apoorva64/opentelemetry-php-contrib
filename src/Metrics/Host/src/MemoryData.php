<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host;

/**
 * Memory usage data structure.
 */
final class MemoryData
{
    public function __construct(
        public readonly int $used,
        public readonly int $free,
        public readonly int $total,
        public readonly int $available,
        public readonly int $buffers,
        public readonly int $cached,
        public readonly float $usedPercent,
        public readonly float $freePercent,
    ) {
    }
}
