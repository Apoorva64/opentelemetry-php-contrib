<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host\Collector;

use OpenTelemetry\Contrib\Metrics\Host\DiskData;

/**
 * Collects disk I/O statistics from the system.
 * Works on Linux by reading /proc/diskstats.
 * On Windows, uses wmic command.
 */
final class DiskCollector
{
    private const PROC_DISKSTATS_FILE = '/proc/diskstats';

    /**
     * Collect disk I/O statistics.
     *
     * @return array<DiskData>
     */
    public function collect(): array
    {
        if (!$this->isLinux()) {
            return [];
        }

        return $this->collectLinux();
    }

    /**
     * @return array<DiskData>
     */
    private function collectLinux(): array
    {
        if (!file_exists(self::PROC_DISKSTATS_FILE)) {
            return [];
        }

        $content = @file_get_contents(self::PROC_DISKSTATS_FILE);
        if ($content === false) {
            return [];
        }

        $result = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
            if ($parts === false || count($parts) < 14) {
                continue;
            }

            $device = $parts[2];

            // Only include physical disk devices (sd*, nvme*, vd*, hd*)
            // Skip partitions and loop devices
            if (!preg_match('/^(sd[a-z]+|nvme\d+n\d+|vd[a-z]+|hd[a-z]+)$/', $device)) {
                continue;
            }

            // Fields: https://www.kernel.org/doc/Documentation/ABI/testing/procfs-diskstats
            // Field  1 - major number
            // Field  2 - minor number
            // Field  3 - device name
            // Field  4 - reads completed successfully
            // Field  5 - reads merged
            // Field  6 - sectors read
            // Field  7 - time spent reading (ms)
            // Field  8 - writes completed successfully
            // Field  9 - writes merged
            // Field 10 - sectors written
            // Field 11 - time spent writing (ms)

            $readsCompleted = (int) $parts[3];
            $sectorsRead = (int) $parts[5];
            $readTime = (int) $parts[6];
            $writesCompleted = (int) $parts[7];
            $sectorsWritten = (int) $parts[9];
            $writeTime = (int) $parts[10];

            // Convert sectors to bytes (sector size is typically 512 bytes)
            $bytesRead = $sectorsRead * 512;
            $bytesWritten = $sectorsWritten * 512;

            $result[] = new DiskData(
                device: $device,
                readsCompleted: $readsCompleted,
                writesCompleted: $writesCompleted,
                bytesRead: $bytesRead,
                bytesWritten: $bytesWritten,
                readTime: $readTime,
                writeTime: $writeTime,
            );
        }

        return $result;
    }

    private function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }
}
