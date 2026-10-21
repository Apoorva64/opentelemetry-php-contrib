<?php

declare(strict_types=1);

/*
 * 06 - Redirects and retries: status code recorded and span timeline relative to a root span.
 *
 *   IMPL=main   php 06-redirect-retry.php
 *   IMPL=branch php 06-redirect-retry.php
 */

require __DIR__ . '/bootstrap.php';

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;

header_line('06 redirects and retries');

[$tracerProvider, $storage] = setup_tracing();

$clients = ['curl' => static fn () => new CurlHttpClient(), 'native' => static fn () => new NativeHttpClient()];

foreach ($clients as $name => $create) {
    echo "\n-- $name\n";

    $storage->exchangeArray([]);
    $create()->request('GET', url('/redirect'))->getContent();
    echo "  302 -> 200 redirect:\n";
    foreach ($storage as $span) {
        echo '    ', format_span($span), "\n";
    }

    $storage->exchangeArray([]);
    $root = $tracerProvider->getTracer('repro')->spanBuilder('root')->startSpan();
    $rootScope = $root->activate();
    $client = new RetryableHttpClient($create(), new GenericRetryStrategy(delayMs: 200, multiplier: 1), 2);

    try {
        $client->request('GET', url('/500'))->getContent();
    } catch (\Throwable $e) {
        unset($e); // the exception trace holds the last attempt's response
    }
    unset($client);
    gc_collect_cycles();
    $rootScope->detach();
    $root->end();

    $t0 = $root->toSpanData()->getStartEpochNanos();
    $names = [];
    foreach ($storage as $span) {
        $names[$span->getSpanId()] = $span->getName() === 'root' ? 'root' : 'GET#' . count($names);
    }
    echo "  RetryableHttpClient 500, 2 retries, 200 ms back-off (times relative to the root span):\n";
    foreach ($storage as $span) {
        if ($span->getName() === 'root') {
            continue;
        }
        printf(
            "    %-5s parent=%-5s %s\n",
            $names[$span->getSpanId()],
            $names[$span->getParentSpanId()] ?? '?',
            format_span($span, $t0),
        );
    }
}
