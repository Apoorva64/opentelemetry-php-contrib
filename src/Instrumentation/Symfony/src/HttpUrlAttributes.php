<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

/**
 * Sanitizes request URLs: userinfo in the authority and the values of sensitive
 * query parameters are replaced with REDACTED before they are recorded as
 * url.full or url.query.
 *
 * @internal
 */
final class HttpUrlAttributes
{
    /**
     * Query parameter names whose values are never recorded, case-insensitively.
     *
     * @var list<non-empty-string>
     */
    private const SENSITIVE_QUERY_KEYS = [
        'access_token',
        'api_key',
        'apikey',
        'auth',
        'authorization',
        'credential',
        'credentials',
        'key',
        'passkey',
        'password',
        'secret',
        'session',
        'session_id',
        'sig',
        'signature',
        'token',
        'x-amz-credential',
        'x-amz-security-token',
        'x-amz-signature',
        'x-goog-signature',
    ];

    /**
     * A sanitized copy of the URL for the url.full attribute.
     */
    public static function sanitizeUrl(string $url): string
    {
        $url = preg_replace_callback(
            '~^([a-z][a-z0-9+.-]*:)?//([^/?#]*)~i',
            static function (array $matches): string {
                $authority = $matches[2];
                $at = strrpos($authority, '@');
                if ($at === false) {
                    return $matches[0];
                }

                $userinfo = substr($authority, 0, $at);
                $replacement = str_contains($userinfo, ':') ? 'REDACTED:REDACTED' : 'REDACTED';

                return $matches[1] . '//' . $replacement . '@' . substr($authority, $at + 1);
            },
            $url,
        ) ?? $url;

        return self::sanitizeQueryPart($url);
    }

    /**
     * A sanitized copy of the query string for the url.query attribute.
     */
    public static function sanitizeQuery(?string $query): ?string
    {
        if ($query === null || $query === '') {
            return $query;
        }

        $sanitized = self::sanitizeQueryPart('?' . $query);

        return substr($sanitized, 1);
    }

    private static function sanitizeQueryPart(string $url): string
    {
        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return $url;
        }

        $fragmentStart = strpos($url, '#', $queryStart);
        $queryEnd = $fragmentStart === false ? strlen($url) : $fragmentStart;
        $query = substr($url, $queryStart + 1, $queryEnd - $queryStart - 1);
        $parts = explode('&', $query);
        $keys = array_merge(
            self::SENSITIVE_QUERY_KEYS,
            self::configuredKeys(),
        );

        foreach ($parts as &$part) {
            $equals = strpos($part, '=');
            if ($equals === false) {
                continue;
            }

            $rawKey = substr($part, 0, $equals);
            $key = strtolower(rawurldecode($rawKey));
            if (in_array($key, $keys, true)) {
                $part = substr($part, 0, $equals + 1) . 'REDACTED';
            }
        }
        unset($part);

        return substr($url, 0, $queryStart + 1) . implode('&', $parts) . substr($url, $queryEnd);
    }

    /**
     * Additional sensitive query parameter names, e.g.
     * OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES=employee_id,birthdate
     *
     * @return list<non-empty-string>
     */
    private static function configuredKeys(): array
    {
        $value = $_SERVER['OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES']
            ?? $_ENV['OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES']
            ?? getenv('OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES');
        if (!is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $name): string => strtolower(trim($name)),
                explode(',', $value),
            ),
            static fn (string $name): bool => $name !== '',
        ));
    }
}
