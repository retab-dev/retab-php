<?php

declare(strict_types=1);

// @oagen-ignore-file
//
// Hand-maintained per-call override struct. Generated `Retab\Service\*`
// methods accept a `?RequestOptions $options = null` as their last parameter;
// callers use it to swap the API key, change the timeout, attach extra
// headers, or set an idempotency key for a single call without rebuilding
// the whole `Retab\Client`. Surface stays additive — fields added here are
// optional, never required.

namespace Retab;

final readonly class RequestOptions
{
    /**
     * @param array<string, string>|null $headers Extra HTTP headers to merge into the request.
     * @param array<string, mixed>|null  $query   Extra query-string entries appended after the spec-driven query.
     */
    public function __construct(
        public ?string $apiKey = null,
        public ?string $clientId = null,
        public ?string $baseUrl = null,
        public ?int $timeout = null,
        public ?int $maxRetries = null,
        public ?string $idempotencyKey = null,
        public ?string $userAgent = null,
        public ?array $headers = null,
        public ?array $query = null,
    ) {
    }
}
