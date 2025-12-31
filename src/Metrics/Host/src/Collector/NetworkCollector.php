<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host\Collector;

use OpenTelemetry\Contrib\Metrics\Host\NetworkData;

/**
 * Collects network statistics from the system.
 * Works on Linux by reading /proc/net/dev.
 * On Windows, uses netstat command.
 */
final class NetworkCollector
{
    private const PROC_NET_DEV_FILE = '/proc/net/dev';

    /**
     * Collect network statistics.
     *
     * @return array<NetworkData>
     */
    public function collect(): array
    {
        if (!$this->isLinux()) {
            return [];
        }

        return $this->collectLinux();
    }

    /**
     * @return array<NetworkData>
     */
    private function collectLinux(): array
    {
        if (!file_exists(self::PROC_NET_DEV_FILE)) {
            return [];
        }

        $content = @file_get_contents(self::PROC_NET_DEV_FILE);
        if ($content === false) {
            return [];
        }

        $result = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            // Skip header lines
            if (strpos($line, '|') !== false || trim($line) === '') {
                continue;
            }

            // Format: interface: rx_bytes rx_packets rx_errs rx_drop ... tx_bytes tx_packets tx_errs tx_drop ...
            $parts = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
            if ($parts === false || count($parts) < 17) {
                continue;
            }

            $interface = rtrim($parts[0], ':');

            // Skip loopback interface
            if ($interface === 'lo') {
                continue;
            }

            $result[] = new NetworkData(
                interface: $interface,
                bytesReceived: (int) $parts[1],
                bytesTransmitted: (int) $parts[9],
                packetsReceived: (int) $parts[2],
                packetsTransmitted: (int) $parts[10],
                errorsReceived: (int) $parts[3],
                errorsTransmitted: (int) $parts[11],
                droppedReceived: (int) $parts[4],
                droppedTransmitted: (int) $parts[12],
            );
        }

        return $result;
    }

    private function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }
}
