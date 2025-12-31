<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host\Collector;

/**
 * Collects process memory statistics.
 */
final class ProcessMemoryCollector
{
    private const PROC_SELF_STATUS_FILE = '/proc/self/status';

    /**
     * Collect process memory usage (RSS - Resident Set Size).
     *
     * @return int Memory usage in bytes
     */
    public function collect(): int
    {
        // Use memory_get_usage for consistency with collectPeak()
        return memory_get_usage(true);
    }

    /**
     * Collect process memory usage from /proc filesystem.
     * Returns VmRSS (Resident Set Size) which may differ from memory_get_usage().
     *
     * @return int Memory usage in bytes, or 0 if unavailable
     */
    public function collectFromProc(): int
    {
        if (!file_exists(self::PROC_SELF_STATUS_FILE)) {
            return 0;
        }

        $content = @file_get_contents(self::PROC_SELF_STATUS_FILE);
        if ($content === false) {
            return 0;
        }

        // Look for VmRSS line (Resident Set Size)
        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $content, $matches)) {
            return (int) $matches[1] * 1024;
        }

        return 0;
    }

    /**
     * Get peak memory usage.
     *
     * @return int Peak memory usage in bytes
     */
    public function collectPeak(): int
    {
        return memory_get_peak_usage(true);
    }
}
