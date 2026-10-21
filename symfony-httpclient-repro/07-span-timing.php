<?php

declare(strict_types=1);

/*
 * 07 - Span start/end for the two-request example (issue sections 1.2 and 5):
 * spans start at request(), so they include the time the request sat queued in PHP.
 *
 *   r1 = request(/fast); r2 = request(/slow-body); sleep(2); r1->getContent(); sleep(2); r2->getContent();
 *
 *   IMPL=main   php 07-span-timing.php
 *   IMPL=branch php 07-span-timing.php
 */

require __DIR__ . '/bootstrap.php';

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;

header_line('07 span timing of the two-request example');

[, $storage] = setup_tracing();

foreach (['curl' => new CurlHttpClient(), 'native' => new NativeHttpClient()] as $name => $client) {
    $storage->exchangeArray([]);
    reset_server_log();
    $t0 = microtime(true);

    $r1 = $client->request('GET', url('/fast'));
    $r2 = $client->request('GET', url('/slow-body'));
    sleep(2);
    $r1->getContent();
    sleep(2);
    $r2->getContent();

    echo "\n-- $name\n";
    foreach (server_arrivals() as [$at, $path]) {
        printf("  SERVER received %-11s at %s\n", $path, since($t0, $at));
    }
    foreach ($storage as $span) {
        printf("  %-11s %s\n", parse_url((string) $span->getAttributes()->get('url.full'), PHP_URL_PATH), format_span($span, (int) ($t0 * 1e9)));
    }
}
