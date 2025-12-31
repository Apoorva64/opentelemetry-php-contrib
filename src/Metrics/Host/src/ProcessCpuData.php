<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host;

/**
 * Process CPU usage data structure.
 */
final class ProcessCpuData
{
    public function __construct(
        public readonly float $userTime,
        public readonly float $systemTime,
        public readonly float $userPercent,
        public readonly float $systemPercent,
    ) {
    }
}
