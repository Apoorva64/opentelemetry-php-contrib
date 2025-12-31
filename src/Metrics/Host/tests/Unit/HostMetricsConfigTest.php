<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Contrib\Metrics\Host\Unit;

use OpenTelemetry\Contrib\Metrics\Host\HostMetricsConfig;
use PHPUnit\Framework\TestCase;

class HostMetricsConfigTest extends TestCase
{
    public function test_default_config_enables_all_groups(): void
    {
        $config = new HostMetricsConfig();

        $this->assertNull($config->getMetricGroups());
        $this->assertTrue($config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_CPU));
        $this->assertTrue($config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_MEMORY));
        $this->assertTrue($config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_NETWORK));
        $this->assertTrue($config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_DISK));
        $this->assertTrue($config->isGroupEnabled(HostMetricsConfig::GROUP_PROCESS_CPU));
        $this->assertTrue($config->isGroupEnabled(HostMetricsConfig::GROUP_PROCESS_MEMORY));
    }

    public function test_custom_metric_groups(): void
    {
        $config = new HostMetricsConfig(
            metricGroups: [
                HostMetricsConfig::GROUP_SYSTEM_CPU,
                HostMetricsConfig::GROUP_SYSTEM_MEMORY,
            ],
        );

        $this->assertTrue($config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_CPU));
        $this->assertTrue($config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_MEMORY));
        $this->assertFalse($config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_NETWORK));
        $this->assertFalse($config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_DISK));
        $this->assertFalse($config->isGroupEnabled(HostMetricsConfig::GROUP_PROCESS_CPU));
        $this->assertFalse($config->isGroupEnabled(HostMetricsConfig::GROUP_PROCESS_MEMORY));
    }

    public function test_custom_name(): void
    {
        $config = new HostMetricsConfig(
            name: 'my-custom-meter',
        );

        $this->assertSame('my-custom-meter', $config->getName());
    }

    public function test_default_name(): void
    {
        $config = new HostMetricsConfig();

        $this->assertSame('io.opentelemetry.contrib.php.host_metrics', $config->getName());
    }

    public function test_all_groups_constant(): void
    {
        $this->assertCount(6, HostMetricsConfig::ALL_GROUPS);
        $this->assertContains(HostMetricsConfig::GROUP_SYSTEM_CPU, HostMetricsConfig::ALL_GROUPS);
        $this->assertContains(HostMetricsConfig::GROUP_SYSTEM_MEMORY, HostMetricsConfig::ALL_GROUPS);
        $this->assertContains(HostMetricsConfig::GROUP_SYSTEM_NETWORK, HostMetricsConfig::ALL_GROUPS);
        $this->assertContains(HostMetricsConfig::GROUP_SYSTEM_DISK, HostMetricsConfig::ALL_GROUPS);
        $this->assertContains(HostMetricsConfig::GROUP_PROCESS_CPU, HostMetricsConfig::ALL_GROUPS);
        $this->assertContains(HostMetricsConfig::GROUP_PROCESS_MEMORY, HostMetricsConfig::ALL_GROUPS);
    }
}
