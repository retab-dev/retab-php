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
        // Retab uses an `API-Key` header, NOT `Authorization: Bearer`. The
        // spec advertises `apiKeyAuth: { in: header, name: API-Key }` and the
        // backend rejects bearer tokens with 401 Unauthorized.
        $headers = [
            'API-Key' => $opts->apiKey ?? $this->apiKey,
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
            // PHP `json_encode([])` renders as `[]` (array) — FastAPI rejects
            // that with "Input should be a valid dictionary or object" when
            // the endpoint expects an object body. Coerce empty assoc arrays
            // to stdClass so they serialise as `{}`. Non-empty arrays keep
            // their natural list-vs-hash inference.
            $request['json'] = $body === [] ? new \stdClass() : $body;
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
            // Cross-language contract: preserve every filter verbatim,
            // swap `after` to the new cursor, and DROP `before` — the two
            // cursors are mutually exclusive on every Retab list route, and
            // a caller walking forward shouldn't snap backward on the next
            // hop. See .notes/blueprints/sdk-pagination-contract.md.
            $nextQuery = $query ?? [];
            unset($nextQuery['before']);
            $nextQuery['after'] = $after;
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
        $message = is_array($decoded)
            ? self::extractErrorMessage($decoded)
                ?? sprintf('HTTP %d: %s', $response->getStatusCode(), $response->getReasonPhrase())
            : sprintf('HTTP %d: %s', $response->getStatusCode(), $response->getReasonPhrase());

        throw new RetabException(
            $message,
            code: $response->getStatusCode(),
            context: is_array($decoded) ? $decoded : ['raw' => $body],
        );
    }

    /**
     * Dig a human-readable error message out of a decoded JSON body, regardless
     * of how the backend nests it. Retab/FastAPI typically returns errors as:
     *
     *   { "detail": { "details": { "error": { "message": "...", "code": "..." } } } }
     *
     * or sometimes:
     *
     *   { "detail": "..." }              (FastAPI default)
     *   { "error": { "message": "..." } }
     *   { "message": "..." }
     *   { "status_code": 422, "message": "..." }      (validation errors)
     *
     * Walk the common envelopes in order and return the first usable message.
     *
     * @param array<mixed> $decoded
     */
    private static function extractErrorMessage(array $decoded): ?string
    {
        // Retab backend: detail.details.error.{message,code} (rich form)
        // or detail.details.error as a plain string (terser form, used by 404s).
        $detail = $decoded['detail'] ?? null;
        if (is_array($detail)) {
            $details = $detail['details'] ?? null;
            if (is_array($details)) {
                $err = $details['error'] ?? null;
                if (is_array($err)) {
                    $msg = $err['message'] ?? null;
                    if (is_string($msg) && $msg !== '') return $msg;
                }
                if (is_string($err) && $err !== '') return $err;
            }
            // FastAPI nested array of error dicts
            if (isset($detail[0]) && is_array($detail[0]) && isset($detail[0]['msg'])) {
                return (string) $detail[0]['msg'];
            }
            // Some endpoints return detail = { message: '...' } directly.
            $msg = $detail['message'] ?? null;
            if (is_string($msg) && $msg !== '' && $msg !== 'An HTTP exception occurred.') {
                return $msg;
            }
        }
        if (is_string($detail) && $detail !== '') return $detail;

        // Top-level error.message
        $err = $decoded['error'] ?? null;
        if (is_array($err)) {
            $msg = $err['message'] ?? null;
            if (is_string($msg) && $msg !== '') return $msg;
        }

        // Top-level message field (validation errors flatten this way)
        $msg = $decoded['message'] ?? null;
        if (is_string($msg) && $msg !== '') return $msg;

        return null;
    }
}
