<?php

declare(strict_types=1);

/*
 * 05 - Exported CLIENT spans for each scenario of the issue (sections 2 and 5).
 *
 *   IMPL=main   php 05-scenarios.php
 *   IMPL=branch php 05-scenarios.php
 */

require __DIR__ . '/bootstrap.php';

use ApiPlatform\Symfony\Bundle\Test\Client as SynchronousClient;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;

// Stand-in for the API Platform test client, listed in HttpClientInstrumentation::SYNCHRONOUS_CLIENTS.
require __DIR__ . '/../src/Instrumentation/Symfony/tests/Integration/Fixtures/ApiPlatform/Symfony/Bundle/Test/Client.php';

header_line('05 exported spans per scenario');

[, $storage] = setup_tracing();

$run = static function (string $name, callable $scenario) use ($storage): void {
    $storage->exchangeArray([]);
    $note = '';

    try {
        $scenario();
    } catch (\Throwable $e) {
        $note = sprintf(' (application saw %s)', short_class($e::class));
        unset($e); // its trace can hold the response, which would delay the span end past this scenario
    }
    gc_collect_cycles();

    printf("%-52s exported=%d%s\n", $name, count($storage), $note);
    foreach ($storage as $span) {
        echo '    ', format_span($span), "\n";
    }
};

$clients = ['curl' => static fn () => new CurlHttpClient(), 'native' => static fn () => new NativeHttpClient()];

foreach ($clients as $name => $create) {
    echo "\n######## $name\n";

    $run('getContent() 200', static fn () => $create()->request('GET', url('/fast'))->getContent());
    $run('request(), sleep(1), getContent()', static function () use ($create): void {
        $response = $create()->request('GET', url('/fast'));
        sleep(1);
        $response->getContent();
    });
    $run('1 s body: getContent()', static fn () => $create()->request('GET', url('/slow-body'))->getContent());
    $run('1 s body: getStatusCode(), then getContent()', static function () use ($create): void {
        $response = $create()->request('GET', url('/slow-body'));
        $response->getStatusCode();
        $response->getContent();
    });
    $run('connection refused: getContent()', static fn () => $create()->request('GET', 'http://127.0.0.1:1/')->getContent());
    $run('200 then truncated body', static function () use ($create): void {
        $response = $create()->request('GET', url('/broken-body'));
        $response->getStatusCode();
        $response->getContent();
    });
    $run('500: getContent()', static fn () => $create()->request('GET', url('/500'))->getContent());
    $run('500: getContent(false)', static fn () => $create()->request('GET', url('/500'))->getContent(false));
    $run('302 -> 200 redirect: getContent()', static fn () => $create()->request('GET', url('/redirect'))->getContent());
    $run('fire-and-forget 200 (destructor)', static function () use ($create): void {
        $create()->request('GET', url('/fast'));
    });
    $run('fire-and-forget 500 (destructor throws)', static function () use ($create): void {
        $create()->request('GET', url('/500'));
    });
    $run('$client->stream() only', static function () use ($create): void {
        $client = $create();
        $response = $client->request('GET', url('/fast'));
        foreach ($client->stream($response) as $chunk) {
            $chunk->getContent();
        }
    });
    $run('concurrent A (1 s body) + B: consume A then B', static function () use ($create): void {
        $client = $create();
        $a = $client->request('GET', url('/slow-body'));
        $b = $client->request('GET', url('/fast'));
        $a->getContent();
        $b->getContent();
    });
    $run('getStatusCode(), keep response 1 s, drop it', static function () use ($create): void {
        $response = $create()->request('GET', url('/fast'));
        $response->getStatusCode();
        sleep(1);
        unset($response);
    });
    $run('cancel() after getStatusCode()', static function () use ($create): void {
        $response = $create()->request('GET', url('/slow-body'));
        $response->getStatusCode();
        $response->cancel();
    });
    $run('toArray() on non-JSON 200', static fn () => $create()->request('GET', url('/fast'))->toArray());
    $run('TraceableHttpClient getContent()', static fn () => (new TraceableHttpClient($create()))->request('GET', url('/fast'))->getContent());
    $run('RetryableHttpClient 500, 2 retries, 200 ms back-off', static fn () => (new RetryableHttpClient(
        $create(),
        new GenericRetryStrategy(delayMs: 200, multiplier: 1),
        2,
    ))->request('GET', url('/500'))->getContent());
}

echo "\n######## other clients\n";
$run('MockHttpClient getContent()', static fn () => (new MockHttpClient(new MockResponse('x')))->request('GET', url('/m'))->getContent());
$run('MockHttpClient fire-and-forget', static function (): void {
    (new MockHttpClient(new MockResponse('x')))->request('GET', url('/m'));
});
$run('synchronous client (API Platform test client)', static function (): void {
    (new SynchronousClient())->request('GET', url('/fast'));
});
