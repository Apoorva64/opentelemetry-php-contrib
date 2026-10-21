<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Component\HttpClient\CurlHttpClient;
use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

$base = getenv('BASE_URL') ?: 'http://127.0.0.1:8765';
$serverLog = sys_get_temp_dir() . '/symfony-httpclient-repro-server.log';

$spans = new ArrayObject();
$timeline = new ArrayObject(); // application/transport events recorded by a scenario, printed after its spans
$scope = Configurator::create()
    ->withTracerProvider(new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($spans))))
    ->activate();

$scenarios = [
    '1. connection refused, getContent()' => static function (HttpClientInterface $client): void {
        $client->request('GET', 'http://127.0.0.1:1/')->getContent();
    },
    '2. 200 then truncated body, getContent()' => static function (HttpClientInterface $client) use ($base): void {
        $client->request('GET', "$base/broken-body")->getContent();
    },
    '3. HTTP 500, getContent()' => static function (HttpClientInterface $client) use ($base): void {
        $client->request('GET', "$base/500")->getContent();
    },
    '4. 302 -> 200 redirect, getContent()' => static function (HttpClientInterface $client) use ($base): void {
        $client->request('GET', "$base/redirect")->getContent();
    },
    '5. 1 s response body, getContent()' => static function (HttpClientInterface $client) use ($base): void {
        $client->request('GET', "$base/slow-body")->getContent();
    },
    '6. request(), sleep(2), getContent()' => static function (HttpClientInterface $client) use ($base): void {
        $response = $client->request('GET', "$base/fast");
        sleep(2);
        $response->getContent();
    },
    // cURL drives every scheduled transfer of the client through one curl_multi handle: consuming r1 also
    // sends r2 and reads its headers. NativeHttpClient only opens r2 when r2 itself is consumed.
    '7. r1 + r2 scheduled, sleep(2), r1->getContent(), sleep(2), r2->getContent()' => static function (HttpClientInterface $client) use ($base, $timeline): void {
        $r2Progress = static function (int $dlNow, int $dlSize, array $info) use ($timeline): void {
            if (($info['http_code'] ?? 0) > 0) {
                $timeline['r2 headers received'] ??= microtime(true);
            }
            if ($dlSize > 0 && $dlNow === $dlSize) {
                $timeline['r2 body fully received'] ??= microtime(true);
            }
        };

        $r1 = $client->request('GET', "$base/fast");
        $r2 = $client->request('GET', "$base/slow-body", ['on_progress' => $r2Progress]);
        sleep(2);
        $timeline['r1->getContent() called'] = microtime(true);
        $r1->getContent();
        sleep(2);
        $timeline['r2->getContent() called'] = microtime(true);
        $r2->getContent();
    },
];

foreach (['curl' => CurlHttpClient::class, 'native' => NativeHttpClient::class] as $clientName => $clientClass) {
    echo "\n## $clientName\n";

    foreach ($scenarios as $title => $scenario) {
        $spans->exchangeArray([]);
        $timeline->exchangeArray([]);
        @unlink($serverLog);
        $start = microtime(true);
        $thrown = '-';

        try {
            $scenario(new $clientClass());
        } catch (\Throwable $e) {
            $thrown = (new \ReflectionClass($e))->getShortName();
            unset($e);
        }
        gc_collect_cycles();

        printf("%s\n    app saw: %s, exported spans: %d\n", $title, $thrown, count($spans));
        foreach ($spans as $span) {
            $events = array_map(
                static fn ($event) => $event->getAttributes()->get('exception.type') ?? $event->getName(),
                $span->getEvents(),
            );
            printf(
                "    span %-11s status=%s http.response.status_code=%s error.type=%s exception events=[%s] start=+%.3fs duration=%.3fs\n",
                parse_url((string) $span->getAttributes()->get('url.full'), PHP_URL_PATH) ?: '/',
                $span->getStatus()->getCode(),
                $span->getAttributes()->get('http.response.status_code') ?? '-',
                $span->getAttributes()->get('error.type') ?? '-',
                implode(', ', array_map(static fn ($c) => substr((string) strrchr('\\' . $c, '\\'), 1), $events)),
                $span->getStartEpochNanos() / 1e9 - $start,
                ($span->getEndEpochNanos() - $span->getStartEpochNanos()) / 1e9,
            );
        }
        $events = $timeline->getArrayCopy();
        foreach (array_filter(explode("\n", (string) @file_get_contents($serverLog))) as $line) {
            [$at, , $path] = explode(' ', $line);
            $events["server received $path"] = (float) $at;
        }
        asort($events);
        foreach ($events as $label => $at) {
            printf("    %-34s at +%.3fs\n", $label, $at - $start);
        }
    }
}

$scope->detach();
