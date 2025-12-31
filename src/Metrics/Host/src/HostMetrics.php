<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Metrics\Host;

use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\MeterProviderInterface;
use OpenTelemetry\API\Metrics\ObservableCallbackInterface;
use OpenTelemetry\API\Metrics\ObserverInterface;
use OpenTelemetry\Contrib\Metrics\Host\Collector\CpuCollector;
use OpenTelemetry\Contrib\Metrics\Host\Collector\DiskCollector;
use OpenTelemetry\Contrib\Metrics\Host\Collector\MemoryCollector;
use OpenTelemetry\Contrib\Metrics\Host\Collector\NetworkCollector;
use OpenTelemetry\Contrib\Metrics\Host\Collector\ProcessCpuCollector;
use OpenTelemetry\Contrib\Metrics\Host\Collector\ProcessMemoryCollector;

/**
 * HostMetrics collects system and process metrics.
 *
 * Metrics collected:
 * - system.cpu.time: CPU time spent in each state (seconds)
 * - system.cpu.utilization: CPU utilization (0-1)
 * - system.memory.usage: Memory usage in bytes
 * - system.memory.utilization: Memory utilization (0-1)
 * - system.network.io: Network bytes transmitted/received
 * - system.network.errors: Network errors
 * - system.network.dropped: Network packets dropped
 * - system.disk.io: Disk bytes read/written
 * - system.disk.operations: Disk read/write operations
 * - process.cpu.time: Process CPU time (seconds)
 * - process.cpu.utilization: Process CPU utilization (0-1)
 * - process.memory.usage: Process memory usage (bytes)
 *
 * @see https://opentelemetry.io/docs/specs/semconv/system/system-metrics/
 * @see https://opentelemetry.io/docs/specs/semconv/system/process-metrics/
 */
final class HostMetrics
{
    private const PACKAGE_VERSION = '0.1.0';

    private MeterInterface $meter;
    private HostMetricsConfig $config;

    private CpuCollector $cpuCollector;
    private MemoryCollector $memoryCollector;
    private NetworkCollector $networkCollector;
    private DiskCollector $diskCollector;
    private ProcessCpuCollector $processCpuCollector;
    private ProcessMemoryCollector $processMemoryCollector;

    /** @var array<ObservableCallbackInterface> */
    private array $callbacks = [];

    public function __construct(
        MeterProviderInterface $meterProvider,
        ?HostMetricsConfig $config = null,
    ) {
        $this->config = $config ?? new HostMetricsConfig();
        $this->meter = $meterProvider->getMeter(
            $this->config->getName(),
            self::PACKAGE_VERSION,
        );

        $this->cpuCollector = new CpuCollector();
        $this->memoryCollector = new MemoryCollector();
        $this->networkCollector = new NetworkCollector();
        $this->diskCollector = new DiskCollector();
        $this->processCpuCollector = new ProcessCpuCollector();
        $this->processMemoryCollector = new ProcessMemoryCollector();
    }

    /**
     * Start collecting metrics.
     */
    public function start(): void
    {
        $this->createSystemCpuMetrics();
        $this->createSystemMemoryMetrics();
        $this->createSystemNetworkMetrics();
        $this->createSystemDiskMetrics();
        $this->createProcessCpuMetrics();
        $this->createProcessMemoryMetrics();
    }

    private function createSystemCpuMetrics(): void
    {
        if (!$this->config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_CPU)) {
            return;
        }

        // system.cpu.time - Seconds each logical CPU spent on each mode
        $cpuTime = $this->meter->createObservableCounter(
            'system.cpu.time',
            's',
            'Seconds each logical CPU spent on each mode',
        );
        $this->callbacks[] = $cpuTime->observe(function (ObserverInterface $observer): void {
            $cpuUsages = $this->cpuCollector->collect();
            foreach ($cpuUsages as $cpu) {
                $observer->observe($cpu->user, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'user',
                ]);
                $observer->observe($cpu->system, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'system',
                ]);
                $observer->observe($cpu->idle, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'idle',
                ]);
                $observer->observe($cpu->nice, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'nice',
                ]);
                $observer->observe($cpu->iowait, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'iowait',
                ]);
                $observer->observe($cpu->irq, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'interrupt',
                ]);
            }
        });

        // system.cpu.utilization - CPU utilization (0-1)
        $cpuUtilization = $this->meter->createObservableGauge(
            'system.cpu.utilization',
            '1',
            'CPU usage time (0-1)',
        );
        $this->callbacks[] = $cpuUtilization->observe(function (ObserverInterface $observer): void {
            $cpuUsages = $this->cpuCollector->collect();
            foreach ($cpuUsages as $cpu) {
                $observer->observe($cpu->userPercent, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'user',
                ]);
                $observer->observe($cpu->systemPercent, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'system',
                ]);
                $observer->observe($cpu->idlePercent, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'idle',
                ]);
                $observer->observe($cpu->nicePercent, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'nice',
                ]);
                $observer->observe($cpu->iowaitPercent, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'iowait',
                ]);
                $observer->observe($cpu->irqPercent, [
                    'system.cpu.logical_number' => $cpu->cpuNumber,
                    'system.cpu.state' => 'interrupt',
                ]);
            }
        });
    }

    private function createSystemMemoryMetrics(): void
    {
        if (!$this->config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_MEMORY)) {
            return;
        }

        // system.memory.usage - Memory usage in bytes
        $memoryUsage = $this->meter->createObservableGauge(
            'system.memory.usage',
            'By',
            'Reports memory in use by state',
        );
        $this->callbacks[] = $memoryUsage->observe(function (ObserverInterface $observer): void {
            $memory = $this->memoryCollector->collect();
            if ($memory === null) {
                return;
            }

            $observer->observe($memory->used, ['system.memory.state' => 'used']);
            $observer->observe($memory->free, ['system.memory.state' => 'free']);
            $observer->observe($memory->buffers, ['system.memory.state' => 'buffers']);
            $observer->observe($memory->cached, ['system.memory.state' => 'cached']);
        });

        // system.memory.utilization - Memory utilization (0-1)
        $memoryUtilization = $this->meter->createObservableGauge(
            'system.memory.utilization',
            '1',
            'Memory usage (0-1)',
        );
        $this->callbacks[] = $memoryUtilization->observe(function (ObserverInterface $observer): void {
            $memory = $this->memoryCollector->collect();
            if ($memory === null) {
                return;
            }

            $observer->observe($memory->usedPercent, ['system.memory.state' => 'used']);
            $observer->observe($memory->freePercent, ['system.memory.state' => 'free']);
        });
    }

    private function createSystemNetworkMetrics(): void
    {
        if (!$this->config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_NETWORK)) {
            return;
        }

        // system.network.io - Network bytes transmitted/received
        $networkIo = $this->meter->createObservableCounter(
            'system.network.io',
            'By',
            'Network transmit and received bytes',
        );
        $this->callbacks[] = $networkIo->observe(function (ObserverInterface $observer): void {
            $networkData = $this->networkCollector->collect();
            foreach ($networkData as $network) {
                $observer->observe($network->bytesReceived, [
                    'system.device' => $network->interface,
                    'network.io.direction' => 'receive',
                ]);
                $observer->observe($network->bytesTransmitted, [
                    'system.device' => $network->interface,
                    'network.io.direction' => 'transmit',
                ]);
            }
        });

        // system.network.errors - Network errors
        $networkErrors = $this->meter->createObservableCounter(
            'system.network.errors',
            '{error}',
            'Count of network errors detected',
        );
        $this->callbacks[] = $networkErrors->observe(function (ObserverInterface $observer): void {
            $networkData = $this->networkCollector->collect();
            foreach ($networkData as $network) {
                $observer->observe($network->errorsReceived, [
                    'system.device' => $network->interface,
                    'network.io.direction' => 'receive',
                ]);
                $observer->observe($network->errorsTransmitted, [
                    'system.device' => $network->interface,
                    'network.io.direction' => 'transmit',
                ]);
            }
        });

        // system.network.dropped - Network packets dropped
        $networkDropped = $this->meter->createObservableCounter(
            'system.network.dropped',
            '{packet}',
            'Network dropped packets',
        );
        $this->callbacks[] = $networkDropped->observe(function (ObserverInterface $observer): void {
            $networkData = $this->networkCollector->collect();
            foreach ($networkData as $network) {
                $observer->observe($network->droppedReceived, [
                    'system.device' => $network->interface,
                    'network.io.direction' => 'receive',
                ]);
                $observer->observe($network->droppedTransmitted, [
                    'system.device' => $network->interface,
                    'network.io.direction' => 'transmit',
                ]);
            }
        });
    }

    private function createSystemDiskMetrics(): void
    {
        if (!$this->config->isGroupEnabled(HostMetricsConfig::GROUP_SYSTEM_DISK)) {
            return;
        }

        // system.disk.io - Disk bytes read/written
        $diskIo = $this->meter->createObservableCounter(
            'system.disk.io',
            'By',
            'Disk bytes transferred',
        );
        $this->callbacks[] = $diskIo->observe(function (ObserverInterface $observer): void {
            $diskData = $this->diskCollector->collect();
            foreach ($diskData as $disk) {
                $observer->observe($disk->bytesRead, [
                    'system.device' => $disk->device,
                    'disk.io.direction' => 'read',
                ]);
                $observer->observe($disk->bytesWritten, [
                    'system.device' => $disk->device,
                    'disk.io.direction' => 'write',
                ]);
            }
        });

        // system.disk.operations - Disk operations
        $diskOperations = $this->meter->createObservableCounter(
            'system.disk.operations',
            '{operation}',
            'Disk operations',
        );
        $this->callbacks[] = $diskOperations->observe(function (ObserverInterface $observer): void {
            $diskData = $this->diskCollector->collect();
            foreach ($diskData as $disk) {
                $observer->observe($disk->readsCompleted, [
                    'system.device' => $disk->device,
                    'disk.io.direction' => 'read',
                ]);
                $observer->observe($disk->writesCompleted, [
                    'system.device' => $disk->device,
                    'disk.io.direction' => 'write',
                ]);
            }
        });

        // system.disk.io_time - Disk I/O time
        $diskIoTime = $this->meter->createObservableCounter(
            'system.disk.io_time',
            'ms',
            'Time disk spent activated',
        );
        $this->callbacks[] = $diskIoTime->observe(function (ObserverInterface $observer): void {
            $diskData = $this->diskCollector->collect();
            foreach ($diskData as $disk) {
                $observer->observe($disk->readTime, [
                    'system.device' => $disk->device,
                    'disk.io.direction' => 'read',
                ]);
                $observer->observe($disk->writeTime, [
                    'system.device' => $disk->device,
                    'disk.io.direction' => 'write',
                ]);
            }
        });
    }

    private function createProcessCpuMetrics(): void
    {
        if (!$this->config->isGroupEnabled(HostMetricsConfig::GROUP_PROCESS_CPU)) {
            return;
        }

        // process.cpu.time - Total process CPU time
        $processCpuTime = $this->meter->createObservableCounter(
            'process.cpu.time',
            's',
            'Process CPU time in seconds',
        );
        $this->callbacks[] = $processCpuTime->observe(function (ObserverInterface $observer): void {
            $cpuData = $this->processCpuCollector->collect();
            if ($cpuData === null) {
                return;
            }

            $observer->observe($cpuData->userTime, ['process.cpu.state' => 'user']);
            $observer->observe($cpuData->systemTime, ['process.cpu.state' => 'system']);
        });

        // process.cpu.utilization - Process CPU utilization (0-1)
        $processCpuUtilization = $this->meter->createObservableGauge(
            'process.cpu.utilization',
            '1',
            'Process CPU utilization (0-1)',
        );
        $this->callbacks[] = $processCpuUtilization->observe(function (ObserverInterface $observer): void {
            $cpuData = $this->processCpuCollector->collect();
            if ($cpuData === null) {
                return;
            }

            $observer->observe($cpuData->userPercent, ['process.cpu.state' => 'user']);
            $observer->observe($cpuData->systemPercent, ['process.cpu.state' => 'system']);
        });
    }

    private function createProcessMemoryMetrics(): void
    {
        if (!$this->config->isGroupEnabled(HostMetricsConfig::GROUP_PROCESS_MEMORY)) {
            return;
        }

        // process.memory.usage - Process memory usage (RSS)
        $processMemoryUsage = $this->meter->createObservableGauge(
            'process.memory.usage',
            'By',
            'The amount of physical memory in use',
        );
        $this->callbacks[] = $processMemoryUsage->observe(function (ObserverInterface $observer): void {
            $memoryUsage = $this->processMemoryCollector->collect();
            $observer->observe($memoryUsage);
        });
    }
}
