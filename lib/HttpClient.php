<?php

declare(strict_types=1);

// @oagen-ignore-file
//
// Hand-maintained HTTP transport. Generated `Retab\Service\*` classes call
// `request()`, `requestPage()`, and `buildUrl()` on this client; the
// generator never edits this file. Implementation kept minimal — uses
// Guzzle for HTTP and a small retry loop for transient failures. Production
// concerns (idempotency replay, structured error decoding, rate-limit
// backoff) live in the downstream SDK runtime and override this scaffold.

namespace Retab;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Query;
use Psr\Http\Message\ResponseInterface;
use Retab\Exception\RetabException;

final class HttpClient
{
    private readonly GuzzleClient $http;
    private readonly string $userAgent;

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $clientId,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
        private readonly int $maxRetries = 3,
        ?HandlerStack $handler = null,
        ?string $userAgent = null,
    ) {
        $this->userAgent = $userAgent ?? 'retab-php/0.0.0';
        $this->http = new GuzzleClient([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'timeout' => $timeout,
            'http_errors' => false,
            ...($handler !== null ? ['handler' => $handler] : []),
        ]);
    }

    /** Used by generated code for endpoints that inject `client_id` into the request. */
    public function requireClientId(): string
    {
        if ($this->clientId === null || $this->clientId === '') {
            throw new RetabException(
                'Client id is required but not configured; pass clientId to the Retab\\Client constructor.',
            );
        }
        return $this->clientId;
    }

    /** Used by generated code for endpoints that inject `client_secret` into the request. */
    public function requireApiKey(): string
    {
        if ($this->apiKey === '') {
            throw new RetabException(
                'API key is required but not configured; pass apiKey to the Retab\\Client constructor or set the RETAB_API_KEY env var.',
            );
        }
        return $this->apiKey;
    }

    /**
     * Execute an HTTP request and return the decoded JSON body.
     *
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>|null $query
     * @return mixed
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        ?array $query = null,
        ?RequestOptions $options = null,
    ): mixed {
        $opts = $options ?? new RequestOptions();
        $url = $this->buildUrl(path: $path, query: $query, options: $opts);
        $effectiveTimeout = $opts->timeout ?? $this->timeout;
        $headers = [
            'Authorization' => 'Bearer ' . ($opts->apiKey ?? $this->apiKey),
            'User-Agent' => $opts->userAgent ?? $this->userAgent,
            'Accept' => 'application/json',
        ];
        if ($opts->idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $opts->idempotencyKey;
        }
        foreach ($opts->headers ?? [] as $name => $value) {
            $headers[$name] = $value;
        }

        $request = ['headers' => $headers, 'timeout' => $effectiveTimeout];
        if ($body !== null) {
            $request['json'] = $body;
            $headers['Content-Type'] = 'application/json';
            $request['headers'] = $headers;
        }

        $attempts = 0;
        $maxRetries = $opts->maxRetries ?? $this->maxRetries;
        while (true) {
            try {
                $response = $this->http->request($method, $url, $request);
            } catch (GuzzleException $e) {
                if (++$attempts > $maxRetries) {
                    throw new RetabException(
                        sprintf('Transport error after %d attempts: %s', $attempts, $e->getMessage()),
                        previous: $e,
                    );
                }
                continue;
            }

            $status = $response->getStatusCode();
            if ($status >= 500 && ++$attempts <= $maxRetries) {
                continue;
            }
            if ($status >= 400) {
                $this->raiseForStatus($response);
            }

            $raw = (string) $response->getBody();
            if ($raw === '') {
                return null;
            }
            return json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        }
    }

    /**
     * Execute a paginated request and wrap the response in `PaginatedResponse`.
     *
     * @template T
     * @param class-string<T>|null $modelClass `T::fromArray($item)` is called on each row.
     * @param array<string, mixed>|null $query
     * @return PaginatedResponse<T>
     */
    public function requestPage(
        string $method,
        string $path,
        ?array $query = null,
        ?string $modelClass = null,
        ?RequestOptions $options = null,
    ): PaginatedResponse {
        /** @var array<string, mixed> $body */
        $body = $this->request(method: $method, path: $path, query: $query, options: $options) ?? [];
        $rawItems = is_array($body['data'] ?? null) ? $body['data'] : [];
        $metadata = is_array($body['list_metadata'] ?? null) ? $body['list_metadata'] : [];

        if ($modelClass !== null && method_exists($modelClass, 'fromArray')) {
            $items = array_map(
                /** @return T */
                static fn (mixed $item) => $modelClass::fromArray($item),
                $rawItems,
            );
        } else {
            /** @var list<T> $items */
            $items = array_values($rawItems);
        }

        $fetchNext = function (string $after) use ($method, $path, $query, $modelClass, $options): PaginatedResponse {
            $nextQuery = array_merge($query ?? [], ['after' => $after]);
            return $this->requestPage(
                method: $method,
                path: $path,
                query: $nextQuery,
                modelClass: $modelClass,
                options: $options,
            );
        };

        return new PaginatedResponse(data: $items, listMetadata: $metadata, fetchNext: $fetchNext);
    }

    /**
     * Build the absolute URL for `$path`, appending `$query` as a query string.
     *
     * @param array<string, mixed>|null $query
     */
    public function buildUrl(string $path, ?array $query = null, ?RequestOptions $options = null): string
    {
        $opts = $options ?? new RequestOptions();
        $base = rtrim($opts->baseUrl ?? $this->baseUrl, '/');
        $url = $base . '/' . ltrim($path, '/');

        $merged = $query ?? [];
        foreach ($opts->query ?? [] as $k => $v) {
            $merged[$k] = $v;
        }
        if ($merged === []) {
            return $url;
        }
        return $url . '?' . Query::build($merged);
    }

    /** Raise a typed exception for a non-2xx response. */
    private function raiseForStatus(ResponseInterface $response): never
    {
        $body = (string) $response->getBody();
        $decoded = $body !== '' ? json_decode($body, true) : null;
        $message = is_array($decoded) && isset($decoded['error']['message'])
            ? (string) $decoded['error']['message']
            : sprintf('HTTP %d: %s', $response->getStatusCode(), $response->getReasonPhrase());

        throw new RetabException(
            $message,
            code: $response->getStatusCode(),
            context: is_array($decoded) ? $decoded : ['raw' => $body],
        );
    }
}
