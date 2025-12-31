<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host\Collector;

use OpenTelemetry\Contrib\Metrics\Host\MemoryData;

/**
 * Collects memory statistics from the system.
 * Works on Linux by reading /proc/meminfo.
 * On Windows, uses wmic command.
 */
final class MemoryCollector
{
    private const PROC_MEMINFO_FILE = '/proc/meminfo';

    /**
     * Collect memory statistics.
     */
    public function collect(): ?MemoryData
    {
        if (!$this->isLinux()) {
            return null;
        }

        return $this->collectLinux();
    }

    private function collectLinux(): ?MemoryData
    {
        if (!file_exists(self::PROC_MEMINFO_FILE)) {
            return null;
        }

        $content = @file_get_contents(self::PROC_MEMINFO_FILE);
        if ($content === false) {
            return null;
        }

        $memInfo = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
                // Values in /proc/meminfo are in kB
                $memInfo[$matches[1]] = (int) $matches[2] * 1024;
            }
        }

        $total = $memInfo['MemTotal'] ?? 0;
        $free = $memInfo['MemFree'] ?? 0;
        $available = $memInfo['MemAvailable'] ?? $free;
        $buffers = $memInfo['Buffers'] ?? 0;
        $cached = $memInfo['Cached'] ?? 0;

        $used = $total - $free - $buffers - $cached;
        if ($used < 0) {
            $used = $total - $free;
        }

        $usedPercent = $total > 0 ? $used / $total : 0.0;
        $freePercent = $total > 0 ? $free / $total : 0.0;

        return new MemoryData(
            used: $used,
            free: $free,
            total: $total,
            available: $available,
            buffers: $buffers,
            cached: $cached,
            usedPercent: $usedPercent,
            freePercent: $freePercent,
        );
    }

    private function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }
}
