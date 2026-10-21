
Describe your environment:

- PHP 8.4.26 (CLI), ext-opentelemetry 1.4.2, ext-curl
- open-telemetry/opentelemetry-auto-symfony 1.6.1 (its `HttpClientInstrumentation.php` is identical to `main`)
- open-telemetry/sdk 1.15.0, open-telemetry/api 1.10.1
- symfony/http-client 8.1.9, symfony/http-kernel 8.1.9, with both `CurlHttpClient` and `NativeHttpClient`

Steps to reproduce

1. Create `composer.json`, `server.php` and `reproduce.php` (below) in an empty directory.
2. Install the dependencies with `composer install`, but make sure PHP ≥ 8.4.1 (required by Symfony 8.1), `ext-opentelemetry` and `ext-curl` are available.
3. Start the test server: `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8765 server.php &`
4. Run `php reproduce.php`.

The script registers an in-memory exporter, runs seven scenarios against both clients, and prints every exported span. It also prints when the test server actually received each request, and for scenario 7 it prints when the application consumed each response and when the transport received `r2`'s headers and body.

<details>
<summary><code>composer.json</code></summary>

```json
{
    "require": {
        "open-telemetry/opentelemetry-auto-symfony": "1.6.1",
        "open-telemetry/sdk": "^1.8",
        "symfony/http-client": "^8.1"
    },
    "config": {
        "allow-plugins": {
            "php-http/discovery": false,
            "tbachert/spi": false
        }
    }
}
```

</details>

<details>
<summary><code>server.php</code>: test server that logs when each request arrives</summary>

```php
<?php

declare(strict_types=1);

/**
 * Test HTTP server used by the reproduction scripts.
 *
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8765 server.php
 *
 * Every request is logged with its arrival time, so scripts can tell when bytes
 * actually reached the server (as opposed to when request() was called).
 */


$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents(
    sys_get_temp_dir() . '/symfony-httpclient-repro-server.log',
    sprintf("%.6f arrived %s\n", microtime(true), $path),
    FILE_APPEND | LOCK_EX,
);

switch ($path) {
    case '/fast':
        echo 'ok';

        break;
    case '/redirect':
        header('Location: /fast', true, 302);

        break;
    case '/500':
        http_response_code(500);
        echo 'boom';

        break;
    case '/slow-body':
        // Headers + first half immediately, second half after 1 s.
        header('Content-Length: 10');
        echo 'hello';
        flush();
        sleep(1);
        echo 'world';

        break;
    case '/broken-body':
        // Announces 100 bytes, sends 7, then closes the connection.
        header('Content-Length: 100');
        echo 'partial';
        flush();

        exit;
    default:
        http_response_code(404);
        echo 'not found';
}
```

</details>

<details>
<summary><code>reproduce.php</code></summary>

```php
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
```

</details>

What is the expected behavior?

One CLIENT span per request, ended exactly once, following the [HTTP client semantic conventions](https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client):

1. Connection refused: a CLIENT span is exported with status `ERROR`, `error.type` = the exception class, and the `TransportException` recorded as an exception event.
2. Body truncated after `200`: the transport failure is visible on the CLIENT span (`ERROR`, `error.type`, exception event), as in Go `otelhttp` and JS `http`/`undici`.
3. HTTP 500: `error.type` = `500`, and the `ServerException` thrown by `getContent()` is recorded.
4. 302 → 200 redirect: `http.response.status_code` is the status of the response the application received (`200`), and the span is not ended at the redirect hop.
5. 1 s body: the span-ending behaviour is documented, as the [semantic conventions require](https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span-duration) ("HTTP client library instrumentations SHOULD document their behavior around ending HTTP client spans").
6. `request()`, `sleep(2)`, `getContent()`: the span reflects the HTTP exchange, or at least makes it visible that the request only went out 2 s after `request()` returned.
7. `r1` + `r2` scheduled, then consumed 2 s apart: each span reflects when its own request was actually on the network, and this differs per transport: cURL multiplexes every transfer scheduled on the client in one `curl_multi` handle, so consuming `r1` also sends `r2`, but `NativeHttpClient` only sends `r2` when `r2` is consumed.

What is the actual behavior?

The `CurlHttpClient` output is below. `NativeHttpClient` gives the same results for scenarios 1–6, but its scenario 7 output differs, so it is shown separately.

```text
1. connection refused, getContent()
    app saw: TransportException, exported spans: 0
2. 200 then truncated body, getContent()
    app saw: TransportException, exported spans: 1
    span /broken-body status=Unset http.response.status_code=200 error.type=- exception events=[] start=+0.000s duration=0.002s
    server received /broken-body       at +0.001s
3. HTTP 500, getContent()
    app saw: ServerException, exported spans: 1
    span /500        status=Error http.response.status_code=500 error.type=- exception events=[] start=+0.000s duration=0.001s
    server received /500               at +0.001s
4. 302 -> 200 redirect, getContent()
    app saw: -, exported spans: 1
    span /redirect   status=Unset http.response.status_code=302 error.type=- exception events=[] start=+0.000s duration=0.001s
    server received /redirect          at +0.001s
    server received /fast              at +0.002s
5. 1 s response body, getContent()
    app saw: -, exported spans: 1
    span /slow-body  status=Unset http.response.status_code=200 error.type=- exception events=[] start=+0.000s duration=0.001s
    server received /slow-body         at +0.001s
6. request(), sleep(2), getContent()
    app saw: -, exported spans: 1
    span /fast       status=Unset http.response.status_code=200 error.type=- exception events=[] start=+0.000s duration=2.002s
    server received /fast              at +2.002s
7. r1 + r2 scheduled, sleep(2), r1->getContent(), sleep(2), r2->getContent()
    app saw: -, exported spans: 2
    span /fast       status=Unset http.response.status_code=200 error.type=- exception events=[] start=+0.000s duration=2.002s
    span /slow-body  status=Unset http.response.status_code=200 error.type=- exception events=[] start=+0.000s duration=2.002s
    r1->getContent() called            at +2.001s
    server received /fast              at +2.002s
    server received /slow-body         at +2.002s
    r2 headers received                at +2.002s
    r2->getContent() called            at +4.003s
    r2 body fully received             at +4.003s
```

`NativeHttpClient`, scenario 7:

```text
7. r1 + r2 scheduled, sleep(2), r1->getContent(), sleep(2), r2->getContent()
    app saw: -, exported spans: 2
    span /fast       status=Unset http.response.status_code=200 error.type=- exception events=[] start=+0.000s duration=2.002s
    span /slow-body  status=Unset http.response.status_code=200 error.type=- exception events=[] start=+0.000s duration=4.003s
    r1->getContent() called            at +2.001s
    server received /fast              at +2.001s
    r2->getContent() called            at +4.002s
    server received /slow-body         at +4.003s
    r2 headers received                at +4.003s
    r2 body fully received             at +5.003s
```

1. Connection refused: the span is started but never ended, so no span is exported.
2. Body truncated after `200`: status `UNSET`, and the `TransportException` the application received is not recorded.
3. HTTP 500: status `ERROR`, but no `error.type` and no exception event.
4. 302 → 200 redirect: `http.response.status_code=302`, although the redirect was followed and the application received `200`.
5. 1 s body: duration 1 ms. The span ends at the first status code and does not cover the body, yet this behaviour is not documented.
6. `request()`, `sleep(2)`, `getContent()`: the span lasts 2.0 s, but the server only received the request 2.0 s after `request()` returned.
7. `r1` + `r2`:
   - cURL multiplexes the transfers in the shared `curl_multi` handle, so calling `r1->getContent()` at +2.0 s sends both requests and also receives `r2`'s headers. But `r2`'s body is only read when `r2->getContent()` drives the handle again at +4.0 s. Both spans still start at +0, and `r2`'s span ends at its headers (+2.0 s), so it includes the 2 s `r2` sat queued and none of its body.
   - Native does not multiplex, so `r2` is only sent when `r2->getContent()` is called at +4.0 s. Its span lasts 4.0 s for an exchange that started at +4.0 s, and it ends before the 1 s body.

Additional context

Symfony responses are lazy, so `request()` only schedules the transfer (with `curl_multi_add_handle()` for curl). Network I/O happens only when the application, or a response destructor, calls into the response(getContent()/ getHeaders(),etc...), which runs `curl_multi_exec()` in `CurlResponse::perform()`. `NativeResponse` opens its stream lazily in the same way.

The two transports differ for concurrent requests (scenario 7). cURL multiplexes every transfer scheduled on the client in a single `curl_multi` handle, here over separate HTTP/1.1 connections, so consuming any response also sends the other scheduled requests, but it only advances them as far as that call needs. `NativeHttpClient` opens each response's stream only when that response is consumed, so the network start of a request depends on the transport and on which response the application reads first, not on `request()`.

The current instrumentation ([`HttpClientInstrumentation.php`](https://github.com/open-telemetry/opentelemetry-php-contrib/blob/main/src/Instrumentation/Symfony/src/HttpClientInstrumentation.php)) starts the span in `request()` and ends it from an `on_progress` callback the first time `http_code` is non-zero:

- Before headers, `on_progress` only ever reports `http_code=0`, so on a transport failure (scenario 1) the span is never ended.
- The callback runs on the first hop of a redirect, so the span ends with `302` instead of `200` (scenario 4).
- Exceptions are only thrown later, by `getStatusCode()`/`getHeaders()`/`getContent()`/`toArray()` or the destructor, after the span has ended, so we can't set the exception class on the span (scenarios 2 and 3).

The Go [`otelhttp.Transport`](https://github.com/open-telemetry/opentelemetry-go-contrib/blob/main/instrumentation/net/http/otelhttp/transport.go) instruments similarly lazy HTTP responses, and it keeps one CLIENT span open until the response completes (body fully read, error, close or abort). It records every error on that span, but it does not treat intentional aborts as errors.

Proposed fix:

- Keep starting the span in `request()`. The `traceparent` header must be injected there, so the span must already exist.
- Make `on_progress` a passive observer that records the status code and the time of the last transport activity.
- Hook `ResponseInterface::{getStatusCode, getHeaders, getContent, toArray, cancel, __destruct}` and end the span exactly once:
  - when the body is received or fails (`getContent()`/`toArray()`),
  - on a transport error while reading headers,
  - on `cancel()` (not an error),
  - when an unread response is destructed or still alive at shutdown; the span then ends at the last transport activity, not at cleanup time, per semconv.
- Record transport errors (`error.type` = exception class) and HTTP errors (`error.type` = status code) on the CLIENT span, but record `toArray()` decoding errors without failing the span.

With this change, scenarios 1–5 behave as expected on both clients, but scenarios 6 and 7 are inherent to starting the span in `request()`. They could be made visible by recording the first `on_progress` call (network start) as a span event, or they could be kept as documented behaviour, so the choice is open for discussion.

Known trade-offs of the proposed fix:

- Spans now include body download time.
- Long-lived streams (SSE) keep the span open for the duration of the stream.
- `RetryableHttpClient` retry spans still need a separate fix (`http.request.resend_count`).

<sub>Tip: [React](https://github.blog/news-insights/product-news/add-reactions-to-pull-requests-issues-and-comments/) with 👍 to help prioritize this issue. Please use comments to provide useful context, avoiding `+1` or `me too`, to help us triage it. Learn more [here](https://opentelemetry.io/community/end-user/issue-participation/).</sub>
