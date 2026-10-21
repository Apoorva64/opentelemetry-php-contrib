<?php

declare(strict_types=1);

/*
 * Shared setup for the reproduction scripts.
 *
 * IMPL selects the HttpClient instrumentation under test:
 *   branch (default) - the implementation in src/ of the checked-out branch, registered by _register.php
 *   main             - impl/MainHttpClientInstrumentation.php, a copy of the implementation on `main`
 *   none             - no HttpClient instrumentation (raw Symfony behaviour)
 *
 * REPRO_BASE_URL overrides the test server address (default http://127.0.0.1:8765).
 */

use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

const SERVER_LOG = '/symfony-httpclient-repro-server.log';

$impl = getenv('IMPL') ?: 'branch';
if (!in_array($impl, ['branch', 'main', 'none'], true)) {
    fwrite(STDERR, "IMPL must be one of: branch, main, none\n");
    exit(1);
}

if ($impl !== 'branch') {
    // Disable the package's own instrumentation (registered from _register.php by the autoloader).
    putenv('OTEL_PHP_DISABLED_INSTRUMENTATIONS=symfony');
    $_SERVER['OTEL_PHP_DISABLED_INSTRUMENTATIONS'] = 'symfony';
}

if (!extension_loaded('opentelemetry')) {
    fwrite(STDERR, "The opentelemetry extension is required.\n");
    exit(1);
}

require __DIR__ . '/../src/Instrumentation/Symfony/vendor/autoload.php';

if ($impl === 'main') {
    require __DIR__ . '/impl/MainHttpClientInstrumentation.php';
    \Repro\Main\HttpClientInstrumentation::register();
}

define('IMPL', $impl);
define('BASE_URL', rtrim(getenv('REPRO_BASE_URL') ?: 'http://127.0.0.1:8765', '/'));

function url(string $path): string
{
    return BASE_URL . $path;
}

/** Seconds elapsed since $t0 (microtime(true)), formatted. */
function since(float $t0, ?float $at = null): string
{
    return sprintf('%6.3fs', ($at ?? microtime(true)) - $t0);
}

function reset_server_log(): void
{
    @unlink(sys_get_temp_dir() . SERVER_LOG);
}

/** @return list<array{float, string}> arrival time and path of each request received by the server */
function server_arrivals(): array
{
    $log = @file_get_contents(sys_get_temp_dir() . SERVER_LOG) ?: '';
    $arrivals = [];
    foreach (array_filter(explode("\n", $log)) as $line) {
        [$ts, , $path] = explode(' ', $line);
        $arrivals[] = [(float) $ts, $path];
    }

    return $arrivals;
}

/**
 * Installs a tracer provider exporting to memory and returns the span storage.
 *
 * @return array{TracerProvider, ArrayObject<int, ImmutableSpan>}
 */
function setup_tracing(): array
{
    $storage = new ArrayObject();
    $tracerProvider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($storage)));
    $GLOBALS['__repro_scope'] = Configurator::create()->withTracerProvider($tracerProvider)->activate();

    return [$tracerProvider, $storage];
}

function short_class(?string $class): string
{
    return $class === null ? '-' : substr($class, (int) strrpos('\\' . $class, '\\'));
}

/** One line per exported CLIENT span: duration, status, HTTP status, error.type, exception events. */
function format_span(ImmutableSpan $span, ?int $t0Nanos = null): string
{
    $events = array_map(
        static fn ($e) => $e->getAttributes()->has('exception.type')
            ? short_class($e->getAttributes()->get('exception.type'))
            : $e->getName(),
        $span->getEvents(),
    );
    $timing = $t0Nanos === null
        ? sprintf('dur=%5.0fms', ($span->getEndEpochNanos() - $span->getStartEpochNanos()) / 1e6)
        : sprintf(
            'start=%.3fs end=%.3fs',
            ($span->getStartEpochNanos() - $t0Nanos) / 1e9,
            ($span->getEndEpochNanos() - $t0Nanos) / 1e9,
        );

    return sprintf(
        '%s %-6s %s status=%-5s http=%-4s error.type=%-20s events=[%s]',
        $span->getKind() === SpanKind::KIND_CLIENT ? 'CLIENT' : 'OTHER ',
        $span->getName(),
        $timing,
        $span->getStatus()->getCode(),
        $span->getAttributes()->get('http.response.status_code') ?? '-',
        short_class($span->getAttributes()->get('error.type')),
        implode(', ', $events),
    );
}

function header_line(string $title): void
{
    printf("\n=== %s (IMPL=%s, PHP %s)\n", $title, IMPL, PHP_VERSION);
}
