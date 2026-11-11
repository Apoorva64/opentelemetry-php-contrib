<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * The meters of the HTTP client instrumentation. The request/response body size
 * histograms only exist when experimental HTTP client telemetry is enabled
 * (OTEL_INSTRUMENTATION_HTTP_CLIENT_EMIT_EXPERIMENTAL_TELEMETRY).
 *
 * @see https://opentelemetry.io/docs/specs/semconv/http/http-metrics/
 * @internal
 */
final class HttpClientMetrics
{
    public function __construct(
        public readonly ?HistogramInterface $requestDuration = null,
        public readonly ?HistogramInterface $requestBodySize = null,
        public readonly ?HistogramInterface $responseBodySize = null,
    ) {
    }

    /**
     * Whether experimental HTTP client telemetry is enabled.
     */
    public static function experimentalTelemetryEnabled(): bool
    {
        $value = $_SERVER['OTEL_INSTRUMENTATION_HTTP_CLIENT_EMIT_EXPERIMENTAL_TELEMETRY']
            ?? $_ENV['OTEL_INSTRUMENTATION_HTTP_CLIENT_EMIT_EXPERIMENTAL_TELEMETRY']
            ?? getenv('OTEL_INSTRUMENTATION_HTTP_CLIENT_EMIT_EXPERIMENTAL_TELEMETRY');

        return is_string($value) && in_array(strtolower(trim($value)), ['true', '1'], true);
    }
}
