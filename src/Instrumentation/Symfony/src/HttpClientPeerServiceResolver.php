<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Symfony;

/**
 * Resolves the peer.service attribute of outgoing requests from a mapping of hosts
 * (or IP addresses) to user-assigned service names, configured as a comma-separated
 * list of <host_or_ip>=<user_assigned_name> pairs in
 * OTEL_INSTRUMENTATION_COMMON_PEER_SERVICE_MAPPING. A mapping key may include a port
 * and a path (host:port/path): a key without a port matches any port, and a key with
 * a path matches request paths starting with it.
 *
 * @see https://opentelemetry.io/docs/zero-code/java/agent/instrumentation/#peer-service-name
 * @internal
 */
final class HttpClientPeerServiceResolver
{
    /** @var list<array{host: string, port: ?int, path: ?string, service: non-empty-string}> */
    private array $mappings;

    /**
     * @param list<array{host: string, port: ?int, path: ?string, service: non-empty-string}> $mappings
     */
    public function __construct(array $mappings)
    {
        $this->mappings = $mappings;
    }

    public static function fromEnvironment(): self
    {
        $mapping = self::environment('OTEL_INSTRUMENTATION_COMMON_PEER_SERVICE_MAPPING');

        return new self($mapping === '' ? [] : self::parse($mapping));
    }

    /**
     * @param non-empty-string $mapping
     *
     * @return list<array{host: string, port: ?int, path: ?string, service: non-empty-string}>
     */
    private static function parse(string $mapping): array
    {
        $mappings = [];
        foreach (explode(',', $mapping) as $pair) {
            $equals = strpos($pair, '=');
            if ($equals === false) {
                continue;
            }

            $service = trim(substr($pair, $equals + 1));
            $key = trim(substr($pair, 0, $equals));
            if ($service === '' || $key === '') {
                continue;
            }

            $parts = parse_url('http://' . $key);
            if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
                continue;
            }

            $path = $parts['path'] ?? '';
            $mappings[] = [
                'host' => trim($parts['host'], '[]'),
                'port' => $parts['port'] ?? null,
                'path' => $path !== '' ? $path : null,
                'service' => $service,
            ];
        }

        return $mappings;
    }

    public function resolve(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $host = trim($parts['host'], '[]');
        $port = $parts['port'] ?? match (strtolower($parts['scheme'] ?? '')) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
        $path = $parts['path'] ?? '';
        $path = $path !== '' ? $path : '/';

        foreach ($this->mappings as $mapping) {
            if ($mapping['host'] === $host
                && ($mapping['port'] === null || $mapping['port'] === $port)
                && ($mapping['path'] === null || str_starts_with($path, $mapping['path']))) {
                return $mapping['service'];
            }
        }

        return null;
    }

    private static function environment(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
