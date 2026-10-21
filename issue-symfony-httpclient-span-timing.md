# [opentelemetry-php-contrib] Symfony HttpClient: CLIENT span lifecycle does not match how lazy responses actually execute

## Summary

The Symfony HttpClient auto-instrumentation creates a CLIENT span in `HttpClientInterface::request()` and ends it from an `on_progress` callback the first time a status code is reported.

This assumes the HTTP request starts when `request()` is called and runs in the background. **It doesn't.** `request()` only *schedules* the transfer. Network I/O happens later, when the application (or a destructor) calls into Symfony and drives the cURL multi-handle or the native stream loop.

As a result, on `main`:

* spans are **lost** when a request fails before headers, and for synchronous clients,
* **errors** raised while reading the response are never recorded,
* **redirects** report the status of the *first* hop (`302`) instead of the final response,
* span **durations** include time the request spent queued in PHP before any byte was sent.

This issue documents how a Symfony request really executes and what fails on `main`. It then lists the design options and the trade-offs of the implementation proposed on branch `feat/symfony-httpclient-single-span`.

---

## 1. How a Symfony HttpClient request executes

### 1.1 `request()` schedules, the event loop executes

`CurlResponse::__construct()` only registers the transfer:

```php
// CurlResponse::__construct()
// Schedule the request in a non-blocking way
$multi->openHandles[$id] = [$ch, $options];
curl_multi_add_handle($multi->handle, $ch);
```

The transfer progresses only when Symfony runs the multi-handle:

```php
// CurlResponse::perform(), called from TransportResponseTrait::stream()
while (\CURLM_CALL_MULTI_PERFORM === ($err = curl_multi_exec($multi->handle, $active))) {
}
```

`stream()` runs only when the application calls a response method (`getStatusCode()`, `getHeaders()`, `getContent()`, `toArray()`), iterates `$client->stream()`, or the response is destructed (`TransportResponseTrait::doDestruct()`). PHP has no background thread. Between those calls, **nothing happens on the network**.

`NativeHttpClient` is lazier still: `NativeResponse` opens its stream only when that response is driven.

The Symfony docs say "the HTTP request starts immediately". That is true from the application's point of view (`request()` doesn't block), but not at the network level.

### 1.2 Measured

Setup: PHP 8.3, `symfony/http-client` 6.4.47, local HTTP server. `/fast` returns `ok`; `/slow-body` sends half of its body, waits 1 s, then sends the rest.

```php
$r1 = $client->request('GET', '/fast');       // t = 0.000
$r2 = $client->request('GET', '/slow-body');  // t = 0.001
sleep(2);
$r1->getContent();
sleep(2);
$r2->getContent();
```

| Event | cURL `r1` | cURL `r2` | Native `r1` | Native `r2` |
|---|---|---|---|---|
| `request()` called | 0.000 s | 0.001 s | 0.000 s | 0.001 s |
| **server received the request** | **2.004 s** | **2.004 s** | **2.002 s** | **4.005 s** |
| first `on_progress` call (network start) | 2.002 s | 2.002 s | 2.001 s | 4.003 s |
| headers (`http_code > 0` in `on_progress`) | 2.004 s | 2.004 s | 2.002 s | 4.005 s |
| last body bytes | 2.004 s | **4.005 s** ¹ | 2.002 s | 5.005 s |
| `getContent()` returned | 2.004 s | 4.005 s | 2.002 s | 5.005 s |
| `getInfo('start_time')` | 0.001 s ² | 0.001 s ² | 2.001 s | 4.002 s |
| `getInfo('total_time')` | 0.0024 s | 2.0026 s | 0.0012 s | 1.0025 s |

¹ The server sent the second half of the body at ~3.0 s. With cURL, `r2` was started by `r1->getContent()` and received its headers and first bytes. The rest of the body was only read when `r2->getContent()` drove the multi-handle again at 4.0 s.
² cURL's `start_time` is the scheduling time. Native's is the time the stream was actually opened, so this field means different things per transport.

The same request has four distinct timestamps:

| Timestamp | Meaning | Observable through |
|---|---|---|
| `request()` entry | logical HTTP operation begins | `request()` pre-hook |
| first processing by the event loop | network execution begins | first `on_progress` call |
| headers received | time to first response headers | first `on_progress` with `http_code > 0` |
| transfer completion | full network operation finishes | last `on_progress` with new bytes; `getContent()`/`toArray()` return; `LastChunk` |

```
request() ─── queued in PHP ───┬─ network ─┬─ body ─┬─ … response not read yet …
                               │           │        │
                         first on_progress │   last bytes
                                    headers received
```

Both timelines in the example:

```
time        0s ───────────── 2s ───────────── 4s ───────────── 5s
cURL   r1   [request()]......[send|hdr|body]
cURL   r2   [request()]......[send|hdr|half]..........[rest]
                             ▲ driven by r1->getContent()  ▲ driven by r2->getContent()
native r1   [request()]......[send|hdr|body]
native r2   [request()]...............................[send|hdr|half……|rest]
                                                      ▲ only opened when r2 is consumed
```

---

## 2. What fails on `main`

Every row below was reproduced on `main` with both `CurlHttpClient` and `NativeHttpClient`, except where noted.

| # | Scenario | `main` behaviour | Impact |
|---|---|---|---|
| 1 | Connection refused / DNS failure (error before headers) | `on_progress` never reports a status code, so **the span is never ended and never exported**. | Failed calls are invisible. |
| 2 | 200 headers, then the body transfer fails (truncated body) | Span already ended at headers with status UNSET. The `TransportException` from `getContent()` is not recorded. | Failures reported as successes. |
| 3 | Any Symfony exception thrown by `getContent()`/`toArray()`/destructor (`ServerException`, `ClientException`, `TransportException`) | Never recorded; `error.type` never set. | No exception details in traces. |
| 4 | Redirect `302 → 200` | Span ends at the **first** status code: `http.response.status_code = 302`, duration covers the first hop only. | Wrong status; followed hops not timed. |
| 5 | Synchronous clients (`SYNCHRONOUS_CLIENTS`, e.g. API Platform's test client) | Status is set in the post-hook, but the span is **never ended**. | Spans lost. |
| 6 | Request queued before being driven (§1.2) | Span starts at `request()`: `r1` = 0.003 → 2.007 s although the network took ~2 ms. | Latency inflated by application time. |
| 7 | Response body not covered | Span ends at headers; a 1 s body download is reported as ~1 ms. | Allowed by semconv, but undocumented. |
| 8 | `RetryableHttpClient` | One span per attempt. Each retry's span starts when the retry is scheduled, so it includes the back-off delay. No `http.request.resend_count`. | Misleading attempt durations. |

Reproduction for rows 1 and 4:

```php
$client->request('GET', 'http://127.0.0.1:1/')->getContent();   // TransportException, no span exported
$client->request('GET', 'http://host/redirect')->getContent();  // span has http.response.status_code=302
```

---

## 3. Constraints

* **Context propagation pins the span start to `request()`.** The `traceparent` header must be injected into `$options['headers']` inside `request()`, because cURL copies the headers into the handle there. The injected span ID must belong to the CLIENT span, so the CLIENT span has to exist at that moment. The OpenTelemetry API cannot change a span's start timestamp after creation, and cannot create a span with a pre-chosen ID.
* **Semantic conventions** ([HTTP client span duration](https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span-duration)):
  * the span SHOULD start sometime before the first request byte is sent (it may include queue/connection time),
  * it SHOULD end after the response headers are fully read or fail to be read (it may include the body),
  * it SHOULD NOT be ended at asynchronous cleanup time of an abandoned response,
  * instrumentations SHOULD document when they end the span,
  * an intentional cancellation SHOULD NOT be an error.
* **No extra I/O.** Instrumentation must not drive the event loop or consume streams itself.

---

## 4. Options

### 4.1 When does the span start?

| Option | Description | Pros | Cons |
|---|---|---|---|
| **S1. `request()` entry** (`main` and the branch) | Start the CLIENT span in the `request()` pre-hook. | Required for propagation (§3); spec compliant; simple. | Duration includes queue time (§1.2, row 6). |
| S2. Network start | Start the span at the first `on_progress` call. | Duration = network time. | **Not feasible:** the span must exist when `traceparent` is injected in `request()`. |
| S3. S1 + network-start marker | Keep S1, and record the first `on_progress` call (and headers) as span events or attributes, e.g. `http.client.transfer_start` / `http.response.headers_received`. | Backends can compute queue time and time-to-headers; cheap; no extra span. | Event/attribute names are not in semconv (instrumentation-specific). |
| S4. INTERNAL span at `request()` + CLIENT span at network start | Logical span plus a network span. | Separates queue time from network time. | Same propagation problem as S2. Injecting the INTERNAL span's context instead breaks the CLIENT→SERVER parent link expected by backends. Doubles span volume. |
| S5. Metric | Record `http.client.request.duration` from network start to headers (as Go `otelhttp` does). | Accurate latency metric, independent of the span. | Metrics are not implemented in this package yet; doesn't change the span. |

### 4.2 When does the span end?

| Option | Description | Pros | Cons |
|---|---|---|---|
| E1. Headers via `on_progress` (`main`) | End on the first status code. | Short spans; no response hooks. | Rows 1–5 of §2. |
| **E2. Response completion** (the branch; same model as Go `otelhttp` and JS `http`/`undici`) | End exactly once on `getContent()`/`toArray()`, a transport error while reading headers, `cancel()`, or the response being destructed or alive at shutdown. | Fixes rows 1–5; all errors on the CLIENT span; matches other SIGs. | Longer spans; see §5. |
| E3. Headers, plus a separate INTERNAL span per consumption call | Earlier proposal. | Keeps header-based duration. | Consumption *is* when the transfer happens (§1.2), so the spans overlap. Doubles volume. Still needs E2's fixes for rows 1, 5. |
| E4. Delayed `end($timestamp)` | End later with an earlier timestamp. | Accurate end time for abandoned responses. | Needs a trigger. Used inside E2 for destructed and shutdown cases. |

---

## 5. Current implementation (branch `feat/symfony-httpclient-single-span`) and its trade-offs

**Design:** S1 + E2 (+ E4 for abandoned responses).

* The span starts in `request()` (unchanged), and propagation is unchanged.
* `on_progress` becomes a passive observer. It records the last status code and the time of the last transport activity, and never ends the span.
* Hooks on `ResponseInterface::{getStatusCode, getHeaders, getContent, toArray, cancel, __destruct}` end the span exactly once. Only the outermost call counts, so nested calls like `toArray()` → `getContent()` are ignored.
* Destructed responses whose body was never read end at the **last transport activity**, not at cleanup time.
* Responses still referenced at shutdown are ended the same way by a shutdown function. That function runs before the SDK's own shutdown, which `ShutdownHandler` pushes to the end of the shutdown queue.
* When another response drove a transfer to completion earlier, the span ends at its last bytes, not when the body is read.
* Errors on the CLIENT span:
  * transport errors before or after headers: status ERROR, `error.type` = exception class;
  * status ≥ 400: status ERROR, `error.type` = status code;
  * `toArray()` decoding errors: recorded as exception events, the span is not failed;
  * `cancel()`: not an error.

### Fixed

| `main` issue (§2) | Branch |
|---|---|
| 1. Refused / DNS failure lost | Span ended, ERROR + `TransportException` |
| 2. Body failure after 200 hidden | ERROR + `TransportException` on the CLIENT span |
| 3. Exceptions not recorded | Recorded; `error.type` set |
| 4. Redirect reports `302` | Final status (`200`), all hops covered |
| 5. Synchronous client spans lost | Ended in the `request()` post-hook |
| 7. Body not covered | Covered when read |
| fire-and-forget / `$client->stream()` / `MockHttpClient` | Ended on destruct (fire-and-forget spans were lost on the earlier WIP branch) |

### Not fixed

| Issue | Detail |
|---|---|
| **6. Queue time included** | Inherent to S1. Same numbers as `main`: `r1` = 0.003 → 2.008 s for ~2 ms of network. S3 would make it visible without changing the span. |
| **8. Retries** | `RetryableHttpClient`'s first attempt is forwarded, so it is merged into the logical span. With 2 retries this produces 3 spans: one covering the whole operation (4 → 406 ms, with the final exception) plus 2 overlapping retry spans. `main` produces 3 consecutive per-attempt spans. Neither follows the semconv [retries](https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-request-retries-and-redirects) model (`http.request.resend_count`). Needs a dedicated fix. |

### New costs

| Trade-off | Detail |
|---|---|
| Longer spans | Spans now include the body when it is read. Dashboards built on `main`'s header-based durations will change. This needs a changelog note; the README documents the new behaviour. |
| The end is bounded by when PHP drives the transfer | With cURL, a partially received body only completes when PHP drives the loop again (`r2` above: last bytes at 4.005 s, although the server finished at ~3 s). The span reflects what PHP observed, not the wire. No design can see bytes PHP hasn't read. |
| Long-lived streams | SSE (`EventSourceHttpClient`) or large `stream()` downloads keep the span open for the whole stream, as in Go and JS. |
| Shutdown ordering | Spans of responses alive at script end rely on our shutdown function running before the SDK's provider shutdown. This holds with `ShutdownHandler`'s re-queueing, but is an ordering dependency. |
| Hook overhead | Six extra hooks on `ResponseInterface`, including `__destruct`. Each call does one `WeakMap` lookup; unrelated responses only pay the lookup. |
| Transport differences | cURL drives all scheduled transfers together; native opens each response lazily. Durations of concurrent requests therefore differ between transports for the same code (§1.2). |

---

## 6. Proposal

1. Adopt **E2** for the span end (branch `feat/symfony-httpclient-single-span`). It fixes data loss and error reporting (rows 1–5) with a single span, consistent with Go and JS.
2. Keep **S1** for the span start (the only option compatible with propagation), and consider **S3**: record the network start (first `on_progress`) and header arrival as span events, so queue time is visible.
3. Track **retries/redirect resends** separately (`http.request.resend_count`, per-attempt spans that start when the attempt is sent rather than scheduled).
4. Document the span-ending behaviour in the package README (done on the branch) and in the changelog.

## 7. Questions for maintainers

1. Is the behaviour change (spans now include the body) acceptable in a minor release, or should it be opt-in via configuration?
2. Should the network-start / headers timestamps (S3) be span events, attributes, or left out?
3. Is a `ResponseInterface::__destruct` hook acceptable, given it runs for every Symfony response?
4. For retries, should the logical request keep a parent span with one child CLIENT span per attempt, or should there be only per-attempt CLIENT spans?

## References

* Instrumentation on `main`: https://github.com/open-telemetry/opentelemetry-php-contrib/blob/main/src/Instrumentation/Symfony/src/HttpClientInstrumentation.php
* Symfony `CurlResponse` / `TransportResponseTrait` (6.4): https://github.com/symfony/http-client/tree/6.4/Response
* Symfony HttpClient docs: https://symfony.com/doc/current/http_client.html
* Semconv, HTTP client span duration: https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span-duration
* Semconv, retries and redirects: https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-request-retries-and-redirects
* Go `otelhttp` transport: https://github.com/open-telemetry/opentelemetry-go-contrib/blob/main/instrumentation/net/http/otelhttp/transport.go
* JS `instrumentation-http`: https://github.com/open-telemetry/opentelemetry-js/blob/main/experimental/packages/opentelemetry-instrumentation-http/src/http.ts
* JS `instrumentation-undici`: https://github.com/open-telemetry/opentelemetry-js-contrib/blob/main/packages/instrumentation-undici/src/undici.ts
