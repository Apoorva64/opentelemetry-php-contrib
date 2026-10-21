# [opentelemetry-php-contrib] Symfony HttpClient: one CLIENT span that ends when the response completes, recording lazy-response errors

## TL;DR

The Symfony HttpClient instrumentation ends its CLIENT span inside the `on_progress` callback as soon as a status code is seen. This loses spans (a request that fails before headers never ends its span), misses errors (body/transport failures after headers) and never covers the response body.

An earlier draft of this issue proposed keeping that span and adding an optional **second INTERNAL span per response-consumption call**. Measuring how Symfony actually executes requests shows that the premise of that proposal does not hold in PHP:

> Symfony transports only make network progress while the application is inside a Symfony call (`getContent()`, `getStatusCode()`, `stream()`, the response destructor…). A lone request is **not even sent** until the response is first consumed.

So there is no "network finished at 100 ms, consumed at 5 s" gap to model. A consumption span would duplicate the CLIENT span's duration rather than separate two concerns. It would also double span volume, and none of the reference OpenTelemetry implementations (Go, JS, Java) do this.

**Proposal:** do what Go `otelhttp` and JS `http`/`undici` do. Emit **one CLIENT span per logical request**: start it in `request()`, keep it open until the response completes (body received, failure, cancel or abandonment), and record every error on that same span. A prototype passes all 17 scenarios below on both `CurlHttpClient` and `NativeHttpClient` and needs no client decorator.

---

## 1. How Symfony HttpClient really executes a request (measured)

Environment: PHP 8.3, `ext-opentelemetry`, `symfony/http-client` 6.4.47, local HTTP server.

### 1.1 Requests are lazy *and* only progress while the app is inside Symfony

```php
$r = $client->request('GET', 'http://127.0.0.1:8765/fast');   // t = 0
sleep(2);
$r->getContent();
```

| | `CurlHttpClient` | `NativeHttpClient` |
|---|---|---|
| `request()` returned | 0.005 s | 0.001 s |
| **server received the request** | **2.006 s** | **2.002 s** |
| first `on_progress` with `http_code=200` | 2.006 s | 2.003 s |
| `getContent()` returned | 2.007 s | 2.003 s |
| `getInfo('total_time')` | 0.0015 s | 0.0016 s |

`CurlResponse::__construct()` only calls `curl_multi_add_handle()` ("Schedule the request in a non-blocking way"). `NativeResponse` opens its stream lazily. All I/O happens in `TransportResponseTrait::stream()`, which runs only when the application calls a response method, iterates `$client->stream()`, or the response is destructed. PHP has no background thread to move the transfer forward.

Consequences:

* "Delayed consumption" (Scenario E of the earlier draft) produces a ~2 s CLIENT span **with every possible design**, because the request is really sent at 2 s. Ending the span at headers does not make it 100 ms.
* The only case where network time and consumption time diverge is **concurrency**: consuming response A drives B's transfer through the same `curl_multi` handle. This is handled in §4 with a timestamp, not with an extra span. `NativeHttpClient` does not even open B's connection until B is consumed.

### 1.2 `on_progress` never reports transport failures

| Case | `on_progress` calls observed | What the app sees |
|---|---|---|
| connection refused | `http_code=0`, `error=null` only | `TransportException` from `getStatusCode()`/`getContent()` |
| body truncated after `200` | `http_code=200`, `dlNow=7/100`, `error=null` | `TransportException` from `getContent()` |
| `500` | `http_code=500` | `ServerException` from `getContent()` |

An `on_progress`-only design cannot finish the span for a pre-header failure, and cannot see body failures at all.

### 1.3 The destructor is part of the request lifecycle

`$client->request('POST', …)` without reading the response is a common "fire-and-forget" pattern. The request is sent by `TransportResponseTrait::doDestruct()`, which also **throws** `ServerException`/`TransportException` for errors. None of the public `ResponseInterface` methods are called on that path.

`hook(ResponseInterface::class, '__destruct', …)` works: verified for `CurlResponse`, `NativeResponse`, `MockResponse`, `TraceableResponse` and `AsyncResponse`. The post-hook receives the exception the destructor throws.

### 1.4 Internal re-entrancy to account for

* `toArray()` calls `getContent()`.
* `stream()` calls `$response->getHeaders(true)` when the first chunk arrives. This can happen for response B while the app is consuming response A.
* `TraceableResponse` and `AsyncResponse` delegate to an inner response. The inner request is "forwarded" and has no span of its own.
* `RetryableHttpClient` cancels a failed attempt (`cancel()`) before retrying, and does **not** forward the original `on_progress` to retries.

---

## 2. How other OpenTelemetry HTTP-client instrumentations do it

| Implementation | Spans per request | Span ends when… | Error after headers (body read) | Cancel / abandon |
|---|---|---|---|---|
| **Go** `otelhttp.Transport` ([transport.go](https://github.com/open-telemetry/opentelemetry-go-contrib/blob/main/instrumentation/net/http/otelhttp/transport.go)) | 1 | `RoundTrip` error, **or body `Read` returns `io.EOF`, or `Body.Close()`** (wraps `res.Body`) | set on the same span (`error.type`, status ERROR) | ends on `Close()`; a body never closed leaks the span (Go requires closing it) |
| **JS** `@opentelemetry/instrumentation-http` ([http.ts](https://github.com/open-telemetry/opentelemetry-js/blob/main/experimental/packages/opentelemetry-instrumentation-http/src/http.ts) `_traceClientRequest`) | 1 | response `'end'` (body fully read), response/request `'error'`, or request `'close'` | same span (`recordException`, ERROR) | request `'close'` ends it; it calls `response.resume()` when nobody listens, so `'end'` fires |
| **JS** `@opentelemetry/instrumentation-undici` / `fetch` ([undici.ts](https://github.com/open-telemetry/opentelemetry-js-contrib/blob/main/packages/instrumentation-undici/src/undici.ts)) | 1 | `undici:request:trailers` (**body fully received**) or `undici:request:error` | same span (`recordException`, ERROR) | `AbortError` ends the span **without** error |
| **Java** OkHttp ([TracingInterceptor.java](https://github.com/open-telemetry/opentelemetry-java-instrumentation/blob/main/instrumentation/okhttp/okhttp-3.0/library/src/main/java/io/opentelemetry/instrumentation/okhttp/v3_0/internal/TracingInterceptor.java)) | 1 | `chain.proceed()` returns (**headers**) | not visible | n/a |
| **PHP Symfony (main)** | 1 | `on_progress` sees a status code (**headers**) | not visible; pre-header failures leave the span open | n/a |

Take-aways:

* **No implementation creates a second span for response consumption.** The duration debate is resolved by choosing *one* end boundary.
* Instrumentations whose response is *lazy*, like Symfony's, are Go and JS. Both keep the span open until the body is done and put body errors on the CLIENT span.
* Go records the `http.client.request.duration` **metric** at headers while the **span** covers the body. This shows the two boundaries can coexist without a second span.

### What the semantic conventions require

From [HTTP client span duration](https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span-duration):

* The span SHOULD start before the first request byte is sent, and SHOULD end after the response headers are fully read or fail to be read. It "may or may not include reading the response body".
* If the application may not fully read the response and the library cleans it up asynchronously, the span **SHOULD NOT be ended in this cleanup phase**. It should end at some point after the headers were read.
* Instrumentations SHOULD document their span-ending behaviour.

From the HTTP client section: an intentional cancellation SHOULD NOT be treated as an error (status unset, no `error.type`).

---

## 3. Assessment of the candidate approaches

| Approach | Verdict | Why |
|---|---|---|
| **A. Current `main`**: end in `on_progress` at first status code | ❌ | Pre-header failures leave the span open, so nothing is exported (§5, "refused"). Body errors are invisible. The span never includes the body. |
| **B. End the span on the first outermost response-method call** (current WIP branch) | ❌ | Fire-and-forget / destructor-only responses are **never exported** (a regression vs `main`). The span ends at `getStatusCode()`, so later body errors are lost. `toArray()` JSON errors mark the HTTP span ERROR. |
| **C. Keep A + add an INTERNAL span per consumption call** (earlier draft) | ❌ | The premise (§1.1) is false in PHP: consumption *is* when the transfer happens, so the two spans overlap. It doubles span volume and needs parent-vs-link and dedup rules. It is not done by any other SIG. It still needs B's fixes for pre-header failures and the destructor path. |
| **D. One span, delayed `end($timestamp)`** | ⚠️ partial | Only needed for abandoned responses (spec: don't end at cleanup time). Used inside E for that case. |
| **E. One span until response completion (Go/JS model)** | ✅ | Ends exactly once on the first terminal signal, puts every error on the CLIENT span, and needs no decorator. See §4. |

---

## 4. Proposed design: one CLIENT span, ended exactly once

### 4.1 Lifecycle

The span is created in the `request()` pre-hook, exactly as today (outermost request only, context propagation unchanged). A small per-request state object is linked to the returned response through a `WeakMap<ResponseInterface, State>`.

| Signal | Action | End timestamp |
|---|---|---|
| `request()` throws | record exception, ERROR, **end** | now |
| `on_progress` (wrapped, passive) | remember `http_code` and the **time of last transport activity** (status or `dlNow` changed). **Never ends the span.** | – |
| `getStatusCode()` / `getHeaders()` returns | set `http.response.status_code`; keep the span open, because the body may follow | – |
| `getStatusCode()` / `getHeaders()` throws `TransportExceptionInterface` | headers failed to be read: record exception, ERROR, **end** | now |
| `getContent()` / `toArray()` returns or throws | body done (or failed): classify (§4.2), **end** | last activity if the body was already fully received **before** the call started (another response drove the event loop), otherwise now |
| `cancel()` | **end**; not an error unless status ≥ 400 (Retryable cancels failed attempts) | now |
| `__destruct()` (fire-and-forget, headers-only, `$client->stream()` consumers) | if the destructor threw, record it and end now; otherwise **end** at the last transport activity, not at cleanup time | last activity (fallback: now) |

Only the **outermost** call on a given response acts. A depth counter on the state absorbs `toArray()`→`getContent()`, `stream()`→`getHeaders(true)` and destructor-internal calls. The `ended` flag guarantees a single `end()`.

### 4.2 Error classification (all on the CLIENT span)

| Exception / condition | Span status | `error.type` | Exception event |
|---|---|---|---|
| `TransportExceptionInterface` (DNS, refused, timeout, truncated body…) | ERROR | exception class | yes |
| status ≥ 400 (with or without `$throw`) | ERROR | status code, e.g. `"500"` | yes, if Symfony threw `HttpExceptionInterface` |
| `DecodingExceptionInterface` from `toArray()` (HTTP exchange succeeded) | unchanged | – | yes (visible, but not an HTTP failure) |
| `cancel()` by the caller | unset | – | no |

### 4.3 Sketch

```php
// request() pre-hook (in addition to today's span creation + propagation)
$state = new State($span);
$previous = $options['on_progress'] ?? null;
$options['on_progress'] = static function (int $dlNow, int $dlSize, array $info) use ($previous, $state): void {
    $state->onProgress($dlNow, $info);   // records status + last-activity timestamp only
    $previous && $previous($dlNow, $dlSize, $info);
};

// request() post-hook
$exception !== null ? $state->finish(null, $exception) : self::$states[$response] = $state;

// ResponseInterface::{getStatusCode,getHeaders,getContent,toArray,cancel,__destruct}
pre:  if ($state && $state->depth++ === 0) { $state->callStart = $clock->now(); }
post: if (!$state || --$state->depth > 0 || $state->ended) { return; }
      match ($method) {
          'getStatusCode', 'getHeaders' => $e instanceof TransportExceptionInterface ? $state->finish($response, $e) : null,
          '__destruct' => $state->finish($response, $e, $e ? null : $state->lastActivity),
          default => $state->finish($response, $e, /* lastActivity if body completed before callStart, else now */),
      };
```

---

## 5. Validation

The same 17 scenarios were run against `main`, the WIP branch and the prototype, each on both transports. Results show span status, recorded exception events and duration. Status and events are identical for both transports; durations are from curl unless noted.

| Scenario | `main` | WIP branch | **Proposed** |
|---|---|---|---|
| `getContent()` 200 | OK, 3 ms | OK, 4 ms | OK, 3 ms |
| `request()`, `sleep(1)`, `getContent()` | OK, 1002 ms | OK, 1002 ms | OK, 1001 ms *(request really sent at 1 s, §1.1)* |
| body takes 1 s; `getContent()` | OK, **1 ms** (headers only) | OK, 1002 ms | OK, 1001 ms |
| `getStatusCode()` then 1 s body | OK, 1 ms | OK, 1 ms | OK, 1002 ms |
| connection refused | **no span** | ERROR + TransportException | ERROR + TransportException |
| 200 then truncated body | OK *(error lost)* | OK *(error lost)* | **ERROR + TransportException** |
| 500, `getContent()` | ERROR | ERROR + ServerException | ERROR + ServerException |
| 500, `getContent(false)` | ERROR | ERROR | ERROR |
| fire-and-forget 200 (destructor) | OK | **no span** | OK |
| fire-and-forget 500 (destructor throws) | ERROR | **no span** | ERROR + ServerException |
| `$client->stream()` only | OK | OK | OK |
| A (1 s body) and B in parallel, consume A then B | curl: 1 ms / 1 ms | 1002 ms / 1003 ms | curl: 1002 ms / **1 ms**; native: 1001 / 1002 ms *(native opens B lazily)* |
| `TraceableHttpClient` | OK | OK | OK |
| `getStatusCode()`, keep response 1 s, drop it | OK, 1 ms | OK, 1 ms | OK, 1 ms *(ended at last activity, not at cleanup)* |
| `cancel()` after `getStatusCode()` | OK | OK | OK *(not an error)* |
| `toArray()` on non-JSON 200 | OK | **ERROR** + JsonException | OK + JsonException event |
| `MockHttpClient` getContent / fire-and-forget | OK / OK | OK / **no span** | OK / OK |

---

## 6. Known limitations and follow-ups

1. **Span start vs first byte.** The span starts in `request()`, which can be well before the request is actually sent (§1.1). This is allowed ("SHOULD start sometime before the first request byte is sent") and is the same for every design.
2. **Responses kept alive until shutdown.** Examples are a response stored in a service property, or the last `RetryableHttpClient` attempt held by `AsyncResponse`. The destructor then runs *after* `register_shutdown_function` handlers, so after the SDK's `TracerProvider` shutdown, and the span would be dropped. The end *timestamp* is still correct (last activity). Mitigation: track open states and end them at their last-activity timestamp from a shutdown function registered by the instrumentation.
3. **Retries.** Each resend from `RetryableHttpClient` is a sibling span that starts when the retry is *scheduled*, so it includes the back-off delay. `http.request.resend_count` is not set. This is unchanged by this proposal and should be a separate issue following [HTTP request retries and redirects](https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-request-retries-and-redirects).
4. **Behaviour change.** The CLIENT span now covers the response body when the app reads it, matching Go and JS. Release notes and the package README should document this, because semconv asks instrumentations to document their span-ending behaviour.
5. **Metrics (optional).** If `http.client.request.duration` is added later, it can be recorded at headers like Go does, independently of the span.

---

## 7. Acceptance criteria

- [ ] Exactly one CLIENT span per logical request (decorated clients and forwarded requests still deduplicated), ended exactly once.
- [ ] No unfinished span for: pre-header transport failures, fire-and-forget responses, headers-only reads, `$client->stream()` consumers, `cancel()`.
- [ ] Transport errors before *or after* headers are recorded on the CLIENT span (`recordException`, `error.type` = exception class, status ERROR).
- [ ] Status ≥ 400 sets `error.type` = status code and ERROR, whether or not Symfony throws.
- [ ] `toArray()` decoding errors do not mark the HTTP span as failed.
- [ ] Caller cancellation is not an error.
- [ ] Abandoned responses end at the last transport activity, never at cleanup time.
- [ ] No extra network I/O, no eager `stream()`, return values and exceptions unchanged.
- [ ] Context propagation unchanged.
- [ ] Integration tests cover `CurlHttpClient`, `NativeHttpClient`, `MockHttpClient`, `TraceableHttpClient` and `RetryableHttpClient`, including every row of §5.
- [ ] README documents when the CLIENT span ends.

---

## 8. Answers to the earlier open questions

1. **Separate INTERNAL consumption spans?** No. In PHP they would overlap the CLIENT span (§1.1), and no other SIG does it (§2).
2. **Parent or link?** Not applicable with a single span.
3. **One span per consumption method?** No. Consumption methods are *signals* that end the CLIENT span; they don't create spans.
4. **Body failure after the CLIENT span ended?** Cannot happen any more: the span stays open until the body completes or fails, as in Go and JS.
5. **Reliable completion signal without decorators?** Use the `getContent()`/`toArray()` post-hook, `cancel()` and `__destruct()` hooks, plus the `on_progress` last-activity timestamp for responses completed while another response drove the event loop. No `getInfo()` completion flag is needed.
6. **Opt-in?** Not needed: there are no additional spans, only a corrected lifecycle. The duration change is called out in release notes.

---

## References

- Current instrumentation: https://github.com/open-telemetry/opentelemetry-php-contrib/blob/main/src/Instrumentation/Symfony/src/HttpClientInstrumentation.php
- Symfony `TransportResponseTrait::stream()` / `doDestruct()` and `CurlResponse::__construct()`: https://github.com/symfony/http-client/tree/6.4/Response
- Symfony `ResponseInterface`: https://github.com/symfony/http-client-contracts/blob/main/ResponseInterface.php
- Semconv, HTTP client span duration: https://opentelemetry.io/docs/specs/semconv/http/http-spans/#http-client-span-duration
- Go `otelhttp` transport: https://github.com/open-telemetry/opentelemetry-go-contrib/blob/main/instrumentation/net/http/otelhttp/transport.go
- JS `instrumentation-http`: https://github.com/open-telemetry/opentelemetry-js/blob/main/experimental/packages/opentelemetry-instrumentation-http/src/http.ts
- JS `instrumentation-undici`: https://github.com/open-telemetry/opentelemetry-js-contrib/blob/main/packages/instrumentation-undici/src/undici.ts
- Java OkHttp `TracingInterceptor`: https://github.com/open-telemetry/opentelemetry-java-instrumentation/blob/main/instrumentation/okhttp/okhttp-3.0/library/src/main/java/io/opentelemetry/instrumentation/okhttp/v3_0/internal/TracingInterceptor.java
