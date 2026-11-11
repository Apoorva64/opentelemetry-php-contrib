[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-symfony/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Symfony)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-symfony)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-symfony/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-symfony/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-symfony/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-symfony/)
[![CI](https://github.com/open-telemetry/opentelemetry-php-contrib/actions/workflows/project-instrumentation-symfony.yml/badge.svg?branch=main)](https://github.com/open-telemetry/opentelemetry-php-contrib/actions/workflows/project-instrumentation-symfony.yml?query=branch%3Amain)

This is a read-only subtree split of https://github.com/open-telemetry/opentelemetry-php-contrib.

# OpenTelemetry Symfony auto-instrumentation

This is an OpenTelemetry auto-instrumentation package for Symfony framework applications.

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Requirements

* [OpenTelemetry extension](https://opentelemetry.io/docs/instrumentation/php/automatic/#installation)
* OpenTelemetry SDK and exporters (required to actually export traces)

## Overview
The following features are supported:
* root span creation (`Symfony\Component\HttpKernel\HttpKernel::handle` hook)
* context propagation
* HttpClient client span creation (one span per request, even when the client is decorated or a decorator rewrites the request URL: `ScopingHttpClient`, `UriTemplateHttpClient`)
* HTTPClient context propagation
* Message Bus span creation
* Message Transport span creation

## HttpClient span lifecycle

Symfony HttpClient responses are lazy: `request()` returns immediately and the transfer only
progresses while the application calls a response method (or the response is destructed).
The instrumentation therefore creates **one CLIENT span per request** and keeps it open until
the response completes. The span ends at the first of:

* `getContent()` / `toArray()` returning or throwing (the response body was received or failed),
* `getStatusCode()` / `getHeaders()` throwing a transport exception (the headers could not be read),
* `cancel()` (an intentional cancellation is not reported as an error),
* the response being destructed or still referenced at script shutdown without its body being read;
  the span then ends at the last transport activity observed through `on_progress`, not at cleanup time.

When another response drove the transfer to completion earlier, the span ends when the last bytes
were received rather than when the body is read. Transport errors (before or after the headers)
and HTTP error statuses (`>= 400`) are recorded on the CLIENT span. Decoding errors from
`toArray()` are recorded as exception events without marking the span as failed.

### Span attributes

The span records `url.full`, `server.address`, `server.port` (the port from the URL, defaulting
to 80/443 for http/https), `http.request.method`, and code attributes. Retried requests
(e.g. by `RetryableHttpClient`) are a single span with `http.request.resend_count` recording the
number of retries.

`url.full` is redacted before it is recorded: userinfo in the authority is replaced with
`REDACTED`, and the values of well-known sensitive query parameters (`token`, `api_key`,
`access_token`, `password`, `secret`, …) are replaced with `REDACTED`. Additional parameter
names can be redacted via the `OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES` environment
variable (comma-separated, case-insensitive).

### Peer service name

`peer.service` can be assigned to outgoing requests from a mapping of host names or IP
addresses to user-assigned service names, configured via
`OTEL_INSTRUMENTATION_COMMON_PEER_SERVICE_MAPPING` as a comma-separated list of
`<host_or_ip>=<user_assigned_name>` pairs. A mapping key may include a port and a path
(`host:port/path`): a key without a port matches any port, and a key with a path matches
request paths starting with it.

```text
OTEL_INSTRUMENTATION_COMMON_PEER_SERVICE_MAPPING=1.2.3.4=cats-service,dogs-abcdef123.serverlessapis.com:80/api=dogs-api
```

Requests to `1.2.3.4` then have a `peer.service` attribute of `cats-service`, and requests to
`dogs-abcdef123.serverlessapis.com:80/api/v1` have `dogs-api`.

### Experimental HTTP client metrics

When `OTEL_INSTRUMENTATION_HTTP_CLIENT_EMIT_EXPERIMENTAL_TELEMETRY=true`, the instrumentation
additionally records the experimental `http.client.request.body.size` and
`http.client.response.body.size` histograms (unit `By`, using the same semantic convention
attributes as the duration metric), and adds the `http.request.body.size` and
`http.response.body.size` attributes to the CLIENT span. The response body size is the number
of bytes received by the transport, falling back to the bytes observed through `on_progress`
when the transport does not report it.

### Trace events

Because `request()` only schedules the transfer and network I/O starts later (when the application
drives the transport), the span carries events that mark when the transfer actually happened,
relative to the span start:

* `http.getconn` — the first `on_progress` call, i.e. when the transport started
  processing the request,
* `http.dns` — DNS resolution completed (from `getInfo()`),
* `http.connect` — TCP connection established (from `getInfo()`),
* `http.tls` — TLS handshake completed (from `getInfo()`, HTTPS only),
* `http.send` — request fully sent (from `getInfo()`),
* `http.headers` — the first `on_progress` call with a status code,
* `http.receive` — the last transport activity observed through `on_progress`.

The gap between the span start and `http.getconn` is the time the request sat queued
in PHP before any byte was sent. The transport timings (`http.dns`, `http.connect`, `http.tls`,
`http.send`) are measured from the span start, not from Symfony's internal
`start_time`, so they reflect when the network work actually happened.

## HttpClient metrics

Each completed request records the
[`http.client.request.duration`](https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpclientrequestduration)
histogram with the semantic convention attributes (`http.request.method`,
`server.address`, `server.port`, `url.scheme`, and on completion
`http.response.status_code`, `network.protocol.version`, `error.type`).

Because Symfony's responses are lazy, the measured duration is the transport's own
`getInfo('total_time')`: the elapsed time from when the transfer actually started
(after any queue delay between `request()` and the first byte) until it completed,
failed, or was canceled. Requests that never reached the transport (e.g. an invalid
option thrown by `request()` itself) record no measurement.

## Installation via composer

```bash
$ composer require open-telemetry/opentelemetry-auto-symfony
```

## Installing dependencies and executing tests

From Symfony subdirectory:

```bash
$ composer install
$ ./vendor/bin/phpunit tests
```

## Configuration

The extension can be disabled via [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=symfony
```

### Request URL sanitization

`url.full` and `url.query` are redacted before they are recorded: userinfo in the authority is
replaced with `REDACTED`, and the values of well-known sensitive query parameters (`token`,
`api_key`, `access_token`, `password`, `secret`, …) are replaced with `REDACTED`. Additional
parameter names can be redacted via the `OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES`
environment variable (comma-separated, case-insensitive).

### HTTP server metrics

Each completed main request records the
[`http.server.request.duration`](https://opentelemetry.io/docs/specs/semconv/http/http-metrics/#metric-httpserverrequestduration)
histogram (unit `s`), with the semantic convention attributes (`http.request.method`,
`http.route`, `server.address`, `server.port`, `url.scheme`, `http.response.status_code`,
`network.protocol.version`, and `error.type` when the request errored). Unknown request
methods are recorded as `_OTHER`.

When `OTEL_INSTRUMENTATION_HTTP_SERVER_EMIT_EXPERIMENTAL_TELEMETRY=true`, the instrumentation
additionally records the experimental `http.server.active_requests` counter (unit
`{request}`, incremented while a request is being handled), and the experimental
`http.server.request.body.size` and `http.server.response.body.size` histograms (unit `By`).
The body sizes are taken from the `Content-Length` headers, or from the response content
when it is buffered.
