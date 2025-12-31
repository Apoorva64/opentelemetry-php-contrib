<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host\Collector;

use OpenTelemetry\Contrib\Metrics\Host\ProcessCpuData;

/**
 * Collects process CPU statistics.
 * Uses getrusage() which is available on most Unix-like systems.
 */
final class ProcessCpuCollector
{
    private const PROC_SELF_STAT_FILE = '/proc/self/stat';

    /** @var array{user: float, system: float, time: float}|null */
    private ?array $previousData = null;

    public function __construct()
    {
        // Initialize with current data
        $this->collect();
    }

    /**
     * Collect process CPU statistics.
     */
    public function collect(): ?ProcessCpuData
    {
        /** @var array<string, int>|null $rusage */
        $rusage = @getrusage();
        if (!is_array($rusage) || empty($rusage)) {
            return $this->collectFromProc();
        }

        $currentTime = microtime(true);

        // Get user and system time in seconds
        $userTime = $rusage['ru_utime.tv_sec'] + ($rusage['ru_utime.tv_usec'] / 1000000);
        $systemTime = $rusage['ru_stime.tv_sec'] + ($rusage['ru_stime.tv_usec'] / 1000000);

        // Calculate percentages based on time elapsed
        $userPercent = 0.0;
        $systemPercent = 0.0;

        if ($this->previousData !== null) {
            $elapsedTime = $currentTime - $this->previousData['time'];
            if ($elapsedTime > 0) {
                $userDelta = $userTime - $this->previousData['user'];
                $systemDelta = $systemTime - $this->previousData['system'];

                // Calculate as fraction of elapsed wall clock time
                // Divide by number of CPUs for normalized percentage
                $numCpus = $this->getNumberOfCpus();
                $userPercent = $userDelta / ($elapsedTime * $numCpus);
                $systemPercent = $systemDelta / ($elapsedTime * $numCpus);

                // Clamp to 0-1 range
                $userPercent = max(0.0, min(1.0, $userPercent));
                $systemPercent = max(0.0, min(1.0, $systemPercent));
            }
        }

        $this->previousData = [
            'user' => $userTime,
            'system' => $systemTime,
            'time' => $currentTime,
        ];

        return new ProcessCpuData(
            userTime: $userTime,
            systemTime: $systemTime,
            userPercent: $userPercent,
            systemPercent: $systemPercent,
        );
    }

    /**
     * Fallback to reading from /proc/self/stat on Linux.
     */
    private function collectFromProc(): ?ProcessCpuData
    {
        if (!file_exists(self::PROC_SELF_STAT_FILE)) {
            return null;
        }

        $content = @file_get_contents(self::PROC_SELF_STAT_FILE);
        if ($content === false) {
            return null;
        }

        // Format: pid (comm) state ppid pgrp session tty_nr tpgid flags
        //         minflt cminflt majflt cmajflt utime stime cutime cstime ...
        // utime is field 14, stime is field 15 (0-indexed: 13, 14)

        // Handle process name with spaces by finding the last )
        $closeParen = strrpos($content, ')');
        if ($closeParen === false) {
            return null;
        }

        $afterComm = substr($content, $closeParen + 2);
        $parts = preg_split('/\s+/', $afterComm, -1, PREG_SPLIT_NO_EMPTY);
        if ($parts === false || count($parts) < 13) {
            return null;
        }

        // After (comm), utime is at index 11, stime at index 12
        $utime = (int) $parts[11];
        $stime = (int) $parts[12];

        // Convert from clock ticks to seconds (typically 100 Hz)
        $clockTicksPerSecond = 100; // sysconf(_SC_CLK_TCK)
        $userTime = $utime / $clockTicksPerSecond;
        $systemTime = $stime / $clockTicksPerSecond;

        $currentTime = microtime(true);
        $userPercent = 0.0;
        $systemPercent = 0.0;

        if ($this->previousData !== null) {
            $elapsedTime = $currentTime - $this->previousData['time'];
            if ($elapsedTime > 0) {
                $userDelta = $userTime - $this->previousData['user'];
                $systemDelta = $systemTime - $this->previousData['system'];

                $numCpus = $this->getNumberOfCpus();
                $userPercent = $userDelta / ($elapsedTime * $numCpus);
                $systemPercent = $systemDelta / ($elapsedTime * $numCpus);

                $userPercent = max(0.0, min(1.0, $userPercent));
                $systemPercent = max(0.0, min(1.0, $systemPercent));
            }
        }

        $this->previousData = [
            'user' => $userTime,
            'system' => $systemTime,
            'time' => $currentTime,
        ];

        return new ProcessCpuData(
            userTime: $userTime,
            systemTime: $systemTime,
            userPercent: $userPercent,
            systemPercent: $systemPercent,
        );
    }

    private function getNumberOfCpus(): int
    {
        if (function_exists('swoole_cpu_num')) {
            return swoole_cpu_num();
        }

        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                $count = substr_count($cpuinfo, 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
        }

        return 1;
    }
}
