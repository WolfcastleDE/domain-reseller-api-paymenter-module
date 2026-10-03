<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Records requests and answers them with queued responses keyed by "METHOD /path".
 */
class FakeTransport
{
    /** @var array<int, array{method: string, url: string, path: string, query: array, headers: array, body: ?array}> */
    public array $requests = [];

    /** @var array<string, array<int, array{status: int, body: string}>> */
    private array $responses = [];

    /** @var array<string, bool> keys whose remaining (default) response was already served */
    private array $served = [];

    public function on(string $method, string $path, array|string $body, int $status = 200): static
    {
        $key = strtoupper($method) . ' ' . $path;
        if (!empty($this->served[$key])) {
            // A default that was already used is replaced by newly queued responses.
            $this->responses[$key] = [];
            unset($this->served[$key]);
        }

        $this->responses[$key][] = [
            'status' => $status,
            'body' => is_string($body) ? $body : json_encode($body),
        ];

        return $this;
    }

    public function __invoke(string $method, string $url, array $headers, ?array $body, int $timeout): array
    {
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $path = $parts['path'] ?? '/';

        $this->requests[] = compact('method', 'url', 'path', 'query', 'headers', 'body');

        $key = strtoupper($method) . ' ' . $path;
        if (empty($this->responses[$key])) {
            throw new RuntimeException('Unexpected request: ' . $key);
        }

        // The last queued response stays as default for repeated calls.
        if (count($this->responses[$key]) > 1) {
            return array_shift($this->responses[$key]);
        }
        $this->served[$key] = true;

        return $this->responses[$key][0];
    }

    public function last(): array
    {
        return end($this->requests);
    }

    /**
     * @return array<int, array> requests matching "METHOD /path"
     */
    public function find(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->requests,
            fn ($r) => $r['method'] === strtoupper($method) && $r['path'] === $path,
        ));
    }
}
