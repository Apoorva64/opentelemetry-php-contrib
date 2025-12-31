<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host;

/**
 * Configuration for HostMetrics collector.
 */
final class HostMetricsConfig
{
    public const GROUP_SYSTEM_CPU = 'system.cpu';
    public const GROUP_SYSTEM_MEMORY = 'system.memory';
    public const GROUP_SYSTEM_NETWORK = 'system.network';
    public const GROUP_SYSTEM_DISK = 'system.disk';
    public const GROUP_PROCESS_CPU = 'process.cpu';
    public const GROUP_PROCESS_MEMORY = 'process.memory';

    public const ALL_GROUPS = [
        self::GROUP_SYSTEM_CPU,
        self::GROUP_SYSTEM_MEMORY,
        self::GROUP_SYSTEM_NETWORK,
        self::GROUP_SYSTEM_DISK,
        self::GROUP_PROCESS_CPU,
        self::GROUP_PROCESS_MEMORY,
    ];

    /** @var array<string>|null */
    private ?array $metricGroups;

    /** @var string */
    private string $name;

    /**
     * @param array<string>|null $metricGroups List of metric groups to collect. If null, all groups are collected.
     * @param string $name Name for the meter.
     */
    public function __construct(
        ?array $metricGroups = null,
        string $name = 'io.opentelemetry.contrib.php.host_metrics',
    ) {
        $this->metricGroups = $metricGroups;
        $this->name = $name;
    }

    /**
     * @return array<string>|null
     */
    public function getMetricGroups(): ?array
    {
        return $this->metricGroups;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isGroupEnabled(string $group): bool
    {
        if ($this->metricGroups === null) {
            return true;
        }

        return in_array($group, $this->metricGroups, true);
    }
}
