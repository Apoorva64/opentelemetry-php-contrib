<?php

declare(strict_types=1);

/*
 * 03 - The four timestamps of one request (issue section 1.2).
 *
 *   r1 = request(/fast); r2 = request(/slow-body); sleep(2); r1->getContent(); sleep(2); r2->getContent();
 *
 * For each request: request() call, first on_progress (network start), headers,
 * last body bytes, getContent() call/return, server arrival, and getInfo() timings.
 *
 *   IMPL=none php 03-timestamps.php
 */

require __DIR__ . '/bootstrap.php';

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;

header_line('03 request / network start / headers / completion timestamps');

foreach (['curl' => new CurlHttpClient(), 'native' => new NativeHttpClient()] as $name => $client) {
    reset_server_log();
    $events = ['r1' => [], 'r2' => []];
    $observe = static function (string $id) use (&$events): \Closure {
        return static function (int $dlNow, int $dlSize, array $info) use (&$events, $id): void {
            $now = microtime(true);
            $events[$id]['first on_progress'] ??= $now;
            if (($info['http_code'] ?? 0) > 0) {
                $events[$id]['headers'] ??= $now;
                if ($dlNow !== ($events[$id]['_bytes'] ?? -1)) {
                    $events[$id]['_bytes'] = $dlNow;
                    $events[$id]['last body bytes'] = $now;
                }
            }
        };
    };

    $t0 = microtime(true);
    $events['r1']['request()'] = microtime(true);
    $r1 = $client->request('GET', url('/fast'), ['on_progress' => $observe('r1')]);
    $events['r2']['request()'] = microtime(true);
    $r2 = $client->request('GET', url('/slow-body'), ['on_progress' => $observe('r2')]);

    sleep(2);
    $events['r1']['getContent() called'] = microtime(true);
    $r1->getContent();
    $events['r1']['getContent() returned'] = microtime(true);

    sleep(2);
    $events['r2']['getContent() called'] = microtime(true);
    $r2->getContent();
    $events['r2']['getContent() returned'] = microtime(true);

    echo "\n-- $name\n";
    foreach (server_arrivals() as [$at, $path]) {
        printf("  SERVER received %-11s at %s\n", $path, since($t0, $at));
    }
    foreach (['r1' => $r1, 'r2' => $r2] as $id => $response) {
        unset($events[$id]['_bytes']);
        asort($events[$id]);
        printf("  %s:\n", $id);
        foreach ($events[$id] as $label => $at) {
            printf("    %-22s %s\n", $label, since($t0, $at));
        }
        $info = $response->getInfo();
        printf(
            "    getInfo: start_time=%s total_time=%.4fs pretransfer_time=%s starttransfer_time=%s\n",
            since($t0, $info['start_time']),
            $info['total_time'],
            isset($info['pretransfer_time']) ? sprintf('%.4fs', $info['pretransfer_time']) : '-',
            isset($info['starttransfer_time']) ? sprintf('%.4fs', $info['starttransfer_time']) : '-',
        );
    }
}
