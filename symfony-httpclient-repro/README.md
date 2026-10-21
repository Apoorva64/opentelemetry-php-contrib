# Symfony HttpClient span lifecycle: reproduction scripts

Scripts behind [`issue-symfony-httpclient-span-timing.md`](../issue-symfony-httpclient-span-timing.md). They show how Symfony HttpClient requests actually execute, and compare the CLIENT spans produced by the instrumentation on `main` with those of the checked-out branch (`feat/symfony-httpclient-single-span`).

## Requirements

* PHP ≥ 8.1 with `ext-opentelemetry` and `ext-curl`
* `composer install` done in [`src/Instrumentation/Symfony`](../src/Instrumentation/Symfony). The scripts use its `vendor/` directory.

## Run

```bash
./run-all.sh
```

This starts the test server on `127.0.0.1:8765` (override with `REPRO_HOST_PORT`), runs every script, and writes the outputs to [`results/`](results/). The run takes about a minute because of deliberate `sleep()`s and retry back-offs.

To run a single script, start the server yourself:

```bash
PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8765 server.php &
IMPL=main   php 05-scenarios.php
IMPL=branch php 05-scenarios.php
```

`IMPL` selects the instrumentation under test:

| `IMPL` | Instrumentation |
|---|---|
| `branch` (default) | `src/Instrumentation/Symfony/src/HttpClientInstrumentation.php` of the checked-out branch |
| `main` | [`impl/MainHttpClientInstrumentation.php`](impl/MainHttpClientInstrumentation.php), a verbatim copy of the file on `main` (ba1b280) in another namespace |
| `none` | no HttpClient instrumentation (raw Symfony behaviour) |

## Files

| File | What it shows | Issue section |
|---|---|---|
| [`server.php`](server.php) | Test server. Logs each request's arrival time. Routes: `/fast`, `/redirect` (302 → `/fast`), `/500`, `/slow-body` (half the body, 1 s pause, rest), `/broken-body` (announces 100 bytes, sends 7, closes). | – |
| [`bootstrap.php`](bootstrap.php) | `IMPL` selection, in-memory span exporter, output helpers. | – |
| [`01-lazy-execution.php`](01-lazy-execution.php) | `request()`, `sleep(2)`, `getContent()`: the server receives the request only when `getContent()` runs. | 1.1 |
| [`02-on-progress-and-errors.php`](02-on-progress-and-errors.php) | What `on_progress` reports, and where Symfony throws: concurrency, connection refused, truncated body, 500, and fire-and-forget (the destructor sends the request and throws). | 1.1, 2 |
| [`03-timestamps.php`](03-timestamps.php) | The four timestamps of a request (`request()`, network start, headers, completion) and `getInfo()` timings, for the two-request example. | 1.2 |
| [`04-destruct-hook.php`](04-destruct-hook.php) | `ResponseInterface::__destruct` can be hooked through the interface, and the post hook receives the destructor's exception. | 5 |
| [`05-scenarios.php`](05-scenarios.php) | Exported spans for 18 scenarios on both cURL and native clients, and 3 on Mock/synchronous clients. | 2, 5 |
| [`06-redirect-retry.php`](06-redirect-retry.php) | Redirect status code, and the retry span timeline relative to a root span. | 2 (rows 4, 8), 5 |
| [`07-span-timing.php`](07-span-timing.php) | Span start/end for the two-request example: spans start at `request()`, so they include queue time. | 2 (row 6), 5 |
| [`run-all.sh`](run-all.sh) | Runs 01–04 with `IMPL=none`, and 05–07 with `IMPL=main` and `IMPL=branch`. | – |
| [`results/`](results/) | Outputs captured with PHP 8.3.6, `symfony/http-client` 6.4.47, on a local server. Timings vary slightly between runs. | – |
| [`standalone/`](standalone/) | Self-contained reproduction embedded in the bug report: released `opentelemetry-auto-symfony` 1.6.1, Symfony 8.1, PHP 8.4, with a `Dockerfile` and captured [`output.txt`](standalone/output.txt). Doesn't need this repository. | Bug report |

## Unit and integration tests

The PHPUnit tests for the branch implementation live in the package itself:

* [`tests/Integration/HttpClientInstrumentationTest.php`](../src/Instrumentation/Symfony/tests/Integration/HttpClientInstrumentationTest.php): lifecycle scenarios on cURL and native clients, Traceable, Mock, synchronous client, shutdown.
* [`tests/Unit/HttpClientRequestStateTest.php`](../src/Instrumentation/Symfony/tests/Unit/HttpClientRequestStateTest.php): end-once, timing and error-classification rules, with a test clock.

```bash
cd src/Instrumentation/Symfony
vendor/bin/phpunit
```
