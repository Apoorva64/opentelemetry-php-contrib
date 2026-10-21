<?php

declare(strict_types=1);

/*
 * 01 - request() does not send the request.
 *
 * request(), sleep(2), getContent(): the server only receives the request when
 * getContent() drives the transport, ~2 s after request() returned.
 *
 *   IMPL=none php 01-lazy-execution.php
 */

require __DIR__ . '/bootstrap.php';

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;

header_line('01 request() schedules, the response methods execute');

foreach (['curl' => new CurlHttpClient(), 'native' => new NativeHttpClient()] as $name => $client) {
    reset_server_log();
    $t0 = microtime(true);
    $seen = [];

    echo "\n-- $name: request(/fast), sleep(2), getContent()\n";
    $response = $client->request('GET', url('/fast'), [
        'on_progress' => static function (int $dlNow, int $dlSize, array $info) use (&$seen, $t0): void {
            $key = ($info['http_code'] ?? 0) . '|' . $dlNow;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                printf("  %s on_progress http_code=%d dlNow=%d\n", since($t0), $info['http_code'] ?? 0, $dlNow);
            }
        },
    ]);
    printf("  %s request() returned\n", since($t0));

    sleep(2);
    printf("  %s calling getContent()\n", since($t0));
    $response->getContent();
    printf("  %s getContent() returned (getInfo total_time=%.4fs)\n", since($t0), $response->getInfo('total_time'));

    foreach (server_arrivals() as [$at, $path]) {
        printf("  %s SERVER received %s\n", since($t0, $at), $path);
    }
}
