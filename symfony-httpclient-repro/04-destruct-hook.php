<?php

declare(strict_types=1);

/*
 * 04 - ResponseInterface::__destruct can be hooked through the interface, and its
 * post hook receives the exception the destructor throws (fire-and-forget errors).
 *
 *   IMPL=none php 04-destruct-hook.php
 */

require __DIR__ . '/bootstrap.php';

use function OpenTelemetry\Instrumentation\hook;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Contracts\HttpClient\ResponseInterface;

header_line('04 hooking ResponseInterface::__destruct');

hook(
    ResponseInterface::class,
    '__destruct',
    pre: static function (ResponseInterface $response): void {
        printf("  pre  __destruct %s\n", short_class($response::class));
    },
    post: static function (ResponseInterface $response, array $params, mixed $return, ?\Throwable $exception): void {
        printf("  post __destruct %s exception=%s\n", short_class($response::class), short_class($exception ? $exception::class : null));
    },
);

$clients = [
    'curl' => new CurlHttpClient(),
    'native' => new NativeHttpClient(),
    'mock' => new MockHttpClient(),
    'traceable(native)' => new TraceableHttpClient(new NativeHttpClient()),
];

foreach ($clients as $name => $client) {
    echo "-- $name: request(/500) and drop the response\n";

    try {
        $client->request('GET', url('/500'));
        echo "  no exception\n";
    } catch (\Throwable $e) {
        printf("  application caught %s\n", short_class($e::class));
    }
}
