<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientRequestAttributes;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientRequestAttributes
 */
final class HttpClientRequestAttributesTest extends TestCase
{
    public function setUp(): void
    {
        unset($_ENV['OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES']);
    }

    public function test_url_full_is_recorded_without_server_address_when_there_is_no_host(): void
    {
        $attributes = HttpClientRequestAttributes::forUrl('file:///tmp/data.json');

        $this->assertSame('file:///tmp/data.json', $attributes[UrlAttributes::URL_FULL]);
        $this->assertArrayNotHasKey(ServerAttributes::SERVER_ADDRESS, $attributes);
        $this->assertArrayNotHasKey(ServerAttributes::SERVER_PORT, $attributes);
    }

    public function test_default_port_is_derived_from_the_scheme(): void
    {
        $attributes = HttpClientRequestAttributes::forUrl('https://example.com/posts');

        $this->assertSame('https://example.com/posts', $attributes[UrlAttributes::URL_FULL]);
        $this->assertSame('example.com', $attributes[ServerAttributes::SERVER_ADDRESS]);
        $this->assertSame(443, $attributes[ServerAttributes::SERVER_PORT]);

        $attributes = HttpClientRequestAttributes::forUrl('http://example.com:8080/posts');

        $this->assertSame(8080, $attributes[ServerAttributes::SERVER_PORT]);
    }

    public function test_ipv6_host_is_recorded_without_brackets(): void
    {
        $attributes = HttpClientRequestAttributes::forUrl('http://[::1]:8080/path');

        $this->assertSame('::1', $attributes[ServerAttributes::SERVER_ADDRESS]);
        $this->assertSame(8080, $attributes[ServerAttributes::SERVER_PORT]);
    }

    public function test_userinfo_is_redacted(): void
    {
        $this->assertSame(
            'https://REDACTED:REDACTED@example.com/path',
            HttpClientRequestAttributes::forUrl('https://user:pass@example.com/path')[UrlAttributes::URL_FULL],
        );
        $this->assertSame(
            'https://REDACTED@example.com/path',
            HttpClientRequestAttributes::forUrl('https://token@example.com/path')[UrlAttributes::URL_FULL],
        );
    }

    public function test_sensitive_query_parameter_values_are_redacted(): void
    {
        $url = 'https://example.com/path?api_key=supersecret&page=2&access_token=abcd';

        $this->assertSame(
            'https://example.com/path?api_key=REDACTED&page=2&access_token=REDACTED',
            HttpClientRequestAttributes::forUrl($url)[UrlAttributes::URL_FULL],
        );
    }

    public function test_sensitive_query_parameter_keys_are_matched_case_insensitively(): void
    {
        $url = 'https://example.com/path?API_KEY=supersecret&Session=abcd';

        $this->assertSame(
            'https://example.com/path?API_KEY=REDACTED&Session=REDACTED',
            HttpClientRequestAttributes::forUrl($url)[UrlAttributes::URL_FULL],
        );
    }

    public function test_encoded_sensitive_query_parameter_keys_are_redacted(): void
    {
        $url = 'https://example.com/path?api%5Fkey=supersecret';

        $this->assertSame(
            'https://example.com/path?api%5Fkey=REDACTED',
            HttpClientRequestAttributes::forUrl($url)[UrlAttributes::URL_FULL],
        );
    }

    public function test_query_parameters_without_values_are_untouched(): void
    {
        $url = 'https://example.com/path?flag&token=abcd&other=';

        $this->assertSame(
            'https://example.com/path?flag&token=REDACTED&other=',
            HttpClientRequestAttributes::forUrl($url)[UrlAttributes::URL_FULL],
        );
    }

    public function test_fragment_is_preserved(): void
    {
        $url = 'https://example.com/path?token=abcd&x=1#section';

        $this->assertSame(
            'https://example.com/path?token=REDACTED&x=1#section',
            HttpClientRequestAttributes::forUrl($url)[UrlAttributes::URL_FULL],
        );
    }

    public function test_configured_field_names_are_redacted(): void
    {
        $_ENV['OTEL_PHP_INSTRUMENTATION_URL_SANITIZE_FIELD_NAMES'] = 'Employee_ID, , birthdate';

        $url = 'https://example.com/path?employee%5Fid=42&birthdate=2000-01-01&x=1';

        $this->assertSame(
            'https://example.com/path?employee%5Fid=REDACTED&birthdate=REDACTED&x=1',
            HttpClientRequestAttributes::forUrl($url)[UrlAttributes::URL_FULL],
        );
    }
}
