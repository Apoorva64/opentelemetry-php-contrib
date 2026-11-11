<?php

declare(strict_types=1);

namespace OpenTelemetry\Tests\Instrumentation\Symfony\tests\Unit;

use OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientPeerServiceResolver;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OpenTelemetry\Contrib\Instrumentation\Symfony\HttpClientPeerServiceResolver
 */
final class HttpClientPeerServiceResolverTest extends TestCase
{
    public function setUp(): void
    {
        unset(
            $_ENV['OTEL_INSTRUMENTATION_COMMON_PEER_SERVICE_MAPPING'],
            $_SERVER['OTEL_INSTRUMENTATION_COMMON_PEER_SERVICE_MAPPING'],
        );
    }

    public function test_resolves_matching_host(): void
    {
        $resolver = new HttpClientPeerServiceResolver([
            ['host' => 'cats.internal', 'port' => null, 'path' => null, 'service' => 'cats-service'],
        ]);

        $this->assertSame('cats-service', $resolver->resolve('http://cats.internal:8080/api'));
        $this->assertSame('cats-service', $resolver->resolve('https://cats.internal/'));
        $this->assertNull($resolver->resolve('https://dogs.internal/'));
    }

    public function test_resolves_matching_host_and_port(): void
    {
        $resolver = new HttpClientPeerServiceResolver([
            ['host' => '1.2.3.4', 'port' => 443, 'path' => null, 'service' => 'cats-service'],
        ]);

        $this->assertSame('cats-service', $resolver->resolve('https://1.2.3.4/'));
        $this->assertNull($resolver->resolve('http://1.2.3.4/'), 'a mapped port must match');
        $this->assertNull($resolver->resolve('https://dogs-abcdef123.serverlessapis.com/'));
    }

    public function test_resolves_matching_host_port_and_path_prefix(): void
    {
        $resolver = new HttpClientPeerServiceResolver([
            ['host' => 'dogs-abcdef123.serverlessapis.com', 'port' => 80, 'path' => '/api', 'service' => 'dogs-api'],
        ]);

        $this->assertSame('dogs-api', $resolver->resolve('http://dogs-abcdef123.serverlessapis.com/api/v1'));
        $this->assertSame('dogs-api', $resolver->resolve('http://dogs-abcdef123.serverlessapis.com/api'));
        $this->assertNull($resolver->resolve('http://dogs-abcdef123.serverlessapis.com/v2/api'));
        $this->assertNull($resolver->resolve('https://dogs-abcdef123.serverlessapis.com/api'), 'a mapped port must match');
    }

    public function test_maps_ipv6_host(): void
    {
        $resolver = new HttpClientPeerServiceResolver([
            ['host' => '::1', 'port' => null, 'path' => null, 'service' => 'local'],
        ]);

        $this->assertSame('local', $resolver->resolve('http://[::1]:8080/x'));
    }

    public function test_urls_without_host_are_not_resolved(): void
    {
        $resolver = new HttpClientPeerServiceResolver([
            ['host' => 'cats.internal', 'port' => null, 'path' => null, 'service' => 'cats-service'],
        ]);

        $this->assertNull($resolver->resolve('file:///tmp/data.json'));
        $this->assertNull($resolver->resolve('not a url'));
    }

    public function test_first_matching_mapping_wins(): void
    {
        $resolver = new HttpClientPeerServiceResolver([
            ['host' => 'cats.internal', 'port' => null, 'path' => null, 'service' => 'cats-service'],
            ['host' => 'cats.internal', 'port' => 443, 'path' => null, 'service' => 'cats-service-tls'],
        ]);

        $this->assertSame('cats-service', $resolver->resolve('https://cats.internal/'));
    }

    public function test_loads_mapping_from_environment(): void
    {
        $_SERVER['OTEL_INSTRUMENTATION_COMMON_PEER_SERVICE_MAPPING'] = ' 1.2.3.4=cats-service ,dogs-abcdef123.serverlessapis.com:80/api=dogs-api';

        $resolver = HttpClientPeerServiceResolver::fromEnvironment();

        $this->assertSame('cats-service', $resolver->resolve('https://1.2.3.4/'));
        $this->assertSame('dogs-api', $resolver->resolve('http://dogs-abcdef123.serverlessapis.com:80/api/v1'));
        $this->assertNull($resolver->resolve('https://cats.internal/'));
    }

    public function test_ignores_malformed_mapping_entries(): void
    {
        $_SERVER['OTEL_INSTRUMENTATION_COMMON_PEER_SERVICE_MAPPING'] = '=no-key,=,no-name=, ,no-equals,cats.internal=cats-service';

        $resolver = HttpClientPeerServiceResolver::fromEnvironment();

        $this->assertSame('cats-service', $resolver->resolve('https://cats.internal/'));
        $this->assertNull($resolver->resolve('https://dogs.internal/'));
    }

    public function test_no_environment_mapping_resolves_nothing(): void
    {
        $resolver = HttpClientPeerServiceResolver::fromEnvironment();

        $this->assertNull($resolver->resolve('https://cats.internal/'));
    }
}
