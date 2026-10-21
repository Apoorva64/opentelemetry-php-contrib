<?php

declare(strict_types=1);

/*
 * 02 - What on_progress reports for each outcome, and where Symfony throws.
 *
 * - concurrency: consuming A drives B through the same cURL multi handle (native opens B lazily)
 * - connection refused: on_progress never reports a status code
 * - body truncated after 200: on_progress never reports the error, getContent() throws
 * - 500: getContent() throws ServerException
 * - fire-and-forget: the request is sent, and the error thrown, by the response destructor
 *
 *   IMPL=none php 02-on-progress-and-errors.php
 */

require __DIR__ . '/bootstrap.php';

use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;

header_line('02 on_progress signals and exception sites');

$progress = static function (string $label, float &$t0): \Closure {
    $seen = [];

    return static function (int $dlNow, int $dlSize, array $info) use (&$seen, &$t0, $label): void {
        $key = ($info['http_code'] ?? 0) . '|' . $dlNow . '|' . ($info['error'] ?? '');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            printf(
                "  %s [%s] on_progress http_code=%d dlNow=%d/%d error=%s\n",
                since($t0),
                $label,
                $info['http_code'] ?? 0,
                $dlNow,
                $dlSize,
                json_encode($info['error'] ?? null),
            );
        }
    };
};

$attempt = static function (string $label, callable $fn, float $t0): void {
    try {
        $result = $fn();
        printf("  %s %s -> %s\n", since($t0), $label, var_export($result, true));
    } catch (\Throwable $e) {
        printf("  %s %s threw %s: %s\n", since($t0), $label, short_class($e::class), $e->getMessage());
    }
};

$clients = ['curl' => static fn () => new CurlHttpClient(), 'native' => static fn () => new NativeHttpClient()];

foreach ($clients as $name => $create) {
    echo "\n######## $name\n";

    $t0 = microtime(true);
    $client = $create();
    echo "-- concurrent: A=/slow-body, B=/fast; consume A, sleep(1), consume B\n";
    $a = $client->request('GET', url('/slow-body'), ['on_progress' => $progress('A', $t0)]);
    $b = $client->request('GET', url('/fast'), ['on_progress' => $progress('B', $t0)]);
    $a->getContent();
    printf("  %s A consumed\n", since($t0));
    sleep(1);
    $b->getContent();
    printf("  %s B consumed\n", since($t0));

    foreach (['refused' => 'http://127.0.0.1:1/', 'broken-body' => url('/broken-body'), '500' => url('/500')] as $case => $target) {
        $t0 = microtime(true);
        echo "-- $case: getStatusCode() then getContent()\n";
        $response = $create()->request('GET', $target, ['on_progress' => $progress((string) $case, $t0)]);
        $attempt('getStatusCode()', static fn () => $response->getStatusCode(), $t0);
        $attempt('getContent()', static fn () => strlen($response->getContent()) . ' bytes', $t0);
        printf("  getInfo: error=%s http_code=%d\n", json_encode($response->getInfo('error')), $response->getInfo('http_code'));
    }

    $t0 = microtime(true);
    echo "-- fire-and-forget: request(/500) and drop the response\n";
    $attempt('destructor', static function () use ($create, $progress, &$t0) {
        $create()->request('GET', url('/500'), ['on_progress' => $progress('ff', $t0)]);

        return 'no exception';
    }, $t0);
}
