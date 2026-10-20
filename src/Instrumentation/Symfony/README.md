[![Releases](https://img.shields.io/badge/releases-purple)](https://github.com/opentelemetry-php/contrib-auto-symfony/releases)
[![Issues](https://img.shields.io/badge/issues-pink)](https://github.com/open-telemetry/opentelemetry-php/issues)
[![Source](https://img.shields.io/badge/source-contrib-green)](https://github.com/open-telemetry/opentelemetry-php-contrib/tree/main/src/Instrumentation/Symfony)
[![Mirror](https://img.shields.io/badge/mirror-opentelemetry--php--contrib-blue)](https://github.com/opentelemetry-php/contrib-auto-symfony)
[![Latest Version](http://poser.pugx.org/open-telemetry/opentelemetry-auto-symfony/v/unstable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-symfony/)
[![Stable](http://poser.pugx.org/open-telemetry/opentelemetry-auto-symfony/v/stable)](https://packagist.org/packages/open-telemetry/opentelemetry-auto-symfony/)

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
* HttpClient client span creation (one span per request, even when the client is decorated)
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

### Trace events

Because `request()` only schedules the transfer and network I/O starts later (when the application
drives the transport), the span also carries two events that mark when the transfer actually
happened, relative to the span start:

* `http.client.request.sent` — the first `on_progress` call, i.e. when the transport started
  processing the request,
* `http.client.response.received` — the last transport activity observed through `on_progress`.

The gap between the span start and `http.client.request.sent` is the time the request sat queued
in PHP before any byte was sent.

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
