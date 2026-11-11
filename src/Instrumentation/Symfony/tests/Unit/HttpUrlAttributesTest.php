<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\Symfony\HttpUrlAttributes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\Symfony\HttpUrlAttributes
 */
final class HttpUrlAttributesTest extends TestCase
{
    public function setUp(): void
    {
        unset($_ENV['OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES']);
    }

    public function test_urls_without_query_string_are_untouched(): void
    {
        $this->assertSame('http://localhost:8057/', HttpUrlAttributes::sanitizeUrl('http://localhost:8057/'));
        $this->assertSame('http://localhost:8057/path', HttpUrlAttributes::sanitizeUrl('http://localhost:8057/path'));
    }

    public function test_non_sensitive_query_values_are_untouched(): void
    {
        $url = 'http://localhost:8057/path?page=2&sort=asc';

        $this->assertSame($url, HttpUrlAttributes::sanitizeUrl($url));
    }

    public function test_sensitive_query_parameter_values_are_redacted(): void
    {
        $url = 'http://localhost:8057/path?api_key=supersecret&page=2&token=abcd';

        $this->assertSame(
            'http://localhost:8057/path?api_key=REDACTED&page=2&token=REDACTED',
            HttpUrlAttributes::sanitizeUrl($url),
        );
    }

    public function test_sensitive_query_parameter_keys_are_matched_case_insensitively(): void
    {
        $url = 'http://localhost:8057/path?API_KEY=supersecret&Session=abcd';

        $this->assertSame(
            'http://localhost:8057/path?API_KEY=REDACTED&Session=REDACTED',
            HttpUrlAttributes::sanitizeUrl($url),
        );
    }

    public function test_encoded_sensitive_query_parameter_keys_are_redacted(): void
    {
        $url = 'http://localhost:8057/path?api%5Fkey=supersecret';

        $this->assertSame(
            'http://localhost:8057/path?api%5Fkey=REDACTED',
            HttpUrlAttributes::sanitizeUrl($url),
        );
    }

    public function test_userinfo_is_redacted(): void
    {
        $this->assertSame(
            'https://REDACTED:REDACTED@example.com/path',
            HttpUrlAttributes::sanitizeUrl('https://user:pass@example.com/path'),
        );
        $this->assertSame(
            'https://REDACTED@example.com/path',
            HttpUrlAttributes::sanitizeUrl('https://token@example.com/path'),
        );
    }

    public function test_fragment_is_preserved(): void
    {
        $url = 'http://localhost:8057/path?token=abcd&x=1#section';

        $this->assertSame(
            'http://localhost:8057/path?token=REDACTED&x=1#section',
            HttpUrlAttributes::sanitizeUrl($url),
        );
    }

    public function test_query_is_sanitized_without_touching_the_rest_of_the_url(): void
    {
        $this->assertSame(
            'api_key=REDACTED&page=2',
            HttpUrlAttributes::sanitizeQuery('api_key=supersecret&page=2'),
        );
        $this->assertNull(HttpUrlAttributes::sanitizeQuery(null));
        $this->assertSame('', HttpUrlAttributes::sanitizeQuery(''));
    }

    public function test_configured_field_names_are_redacted(): void
    {
        $_ENV['OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES'] = 'Employee_ID, , birthdate';

        $url = 'http://localhost:8057/path?employee%5Fid=42&birthdate=2000-01-01&x=1';

        $this->assertSame(
            'http://localhost:8057/path?employee%5Fid=REDACTED&birthdate=REDACTED&x=1',
            HttpUrlAttributes::sanitizeUrl($url),
        );
    }
}
