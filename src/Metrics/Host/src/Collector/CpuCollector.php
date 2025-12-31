<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host\Collector;

use OpenTelemetry\Contrib\Metrics\Host\CpuUsageData;

/**
 * Collects CPU statistics from the system.
 * Works on Linux by reading /proc/stat.
 * On Windows, uses wmic command.
 */
final class CpuCollector
{
    private const PROC_STAT_FILE = '/proc/stat';

    /** @var array<string, array<string, int>> Previous CPU times for calculating deltas */
    private array $previousCpuTimes = [];

    public function __construct()
    {
        // Initialize with current data
        $this->collect();
    }

    /**
     * @return array<CpuUsageData>
     */
    public function collect(): array
    {
        if (!$this->isLinux()) {
            return [];
        }

        return $this->collectLinux();
    }

    /**
     * @return array<CpuUsageData>
     */
    private function collectLinux(): array
    {
        if (!file_exists(self::PROC_STAT_FILE)) {
            return [];
        }

        $content = @file_get_contents(self::PROC_STAT_FILE);
        if ($content === false) {
            return [];
        }

        $result = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (!preg_match('/^cpu(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $matches)) {
                continue;
            }

            $cpuNumber = $matches[1];
            $currentTimes = [
                'user' => (int) $matches[2],
                'nice' => (int) $matches[3],
                'system' => (int) $matches[4],
                'idle' => (int) $matches[5],
                'iowait' => (int) $matches[6],
                'irq' => (int) $matches[7],
                'softirq' => (int) $matches[8],
            ];

            // Calculate total and individual times in seconds (USER_HZ is typically 100)
            $userHz = 100;
            $user = $currentTimes['user'] / $userHz;
            $nice = $currentTimes['nice'] / $userHz;
            $system = $currentTimes['system'] / $userHz;
            $idle = $currentTimes['idle'] / $userHz;
            $iowait = $currentTimes['iowait'] / $userHz;
            $irq = $currentTimes['irq'] / $userHz;
            $softirq = $currentTimes['softirq'] / $userHz;

            // Calculate percentages based on delta from previous measurement
            $previousTimes = $this->previousCpuTimes[$cpuNumber] ?? null;
            if ($previousTimes !== null) {
                $totalDelta = array_sum($currentTimes) - array_sum($previousTimes);
                if ($totalDelta > 0) {
                    $userPercent = ($currentTimes['user'] - $previousTimes['user']) / $totalDelta;
                    $nicePercent = ($currentTimes['nice'] - $previousTimes['nice']) / $totalDelta;
                    $systemPercent = ($currentTimes['system'] - $previousTimes['system']) / $totalDelta;
                    $idlePercent = ($currentTimes['idle'] - $previousTimes['idle']) / $totalDelta;
                    $iowaitPercent = ($currentTimes['iowait'] - $previousTimes['iowait']) / $totalDelta;
                    $irqPercent = ($currentTimes['irq'] - $previousTimes['irq']) / $totalDelta;
                    $softirqPercent = ($currentTimes['softirq'] - $previousTimes['softirq']) / $totalDelta;
                } else {
                    $userPercent = $nicePercent = $systemPercent = $idlePercent = 0.0;
                    $iowaitPercent = $irqPercent = $softirqPercent = 0.0;
                }
            } else {
                $userPercent = $nicePercent = $systemPercent = $idlePercent = 0.0;
                $iowaitPercent = $irqPercent = $softirqPercent = 0.0;
            }

            $this->previousCpuTimes[$cpuNumber] = $currentTimes;

            $result[] = new CpuUsageData(
                cpuNumber: $cpuNumber,
                user: $user,
                system: $system,
                idle: $idle,
                nice: $nice,
                iowait: $iowait,
                irq: $irq,
                softirq: $softirq,
                userPercent: $userPercent,
                systemPercent: $systemPercent,
                idlePercent: $idlePercent,
                nicePercent: $nicePercent,
                iowaitPercent: $iowaitPercent,
                irqPercent: $irqPercent,
                softirqPercent: $softirqPercent,
            );
        }

        return $result;
    }

    private function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }
}
