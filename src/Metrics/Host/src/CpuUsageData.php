<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host;

/**
 * CPU usage data structure.
 */
final class CpuUsageData
{
    public function __construct(
        public readonly string $cpuNumber,
        public readonly float $user,
        public readonly float $system,
        public readonly float $idle,
        public readonly float $nice,
        public readonly float $iowait,
        public readonly float $irq,
        public readonly float $softirq,
        public readonly float $userPercent,
        public readonly float $systemPercent,
        public readonly float $idlePercent,
        public readonly float $nicePercent,
        public readonly float $iowaitPercent,
        public readonly float $irqPercent,
        public readonly float $softirqPercent,
    ) {
    }
}
