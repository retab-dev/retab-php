<?php

declare(strict_types=1);

// @oagen-ignore-file
//
// Hand-maintained test harness used by every `tests/Service/*Test.php`
// emitted by the generator. The trait wires a Guzzle MockHandler into a
// `Retab\Client`, gives each test a `getLastRequest()` accessor for
// request-shape assertions, and loads JSON fixture files from
// `tests/Fixtures/`. Generator never touches this file.

namespace Retab;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

trait TestHelper
{
    /** @var list<array{request: RequestInterface}> */
    private array $recordedRequests = [];

    /**
     * Build a `Retab\Client` that replies with the supplied canned responses.
     * Each entry is `['status' => int, 'body' => array|string]`; body arrays
     * are JSON-encoded automatically.
     *
     * @param list<array{status?: int, body?: array<string, mixed>|list<mixed>|string}> $responses
     */
    public function createMockClient(array $responses): Client
    {
        $psrResponses = [];
        foreach ($responses as $r) {
            $status = $r['status'] ?? 200;
            $body = $r['body'] ?? null;
            $payload = is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : ($body ?? '');
            $psrResponses[] = new Response($status, ['Content-Type' => 'application/json'], $payload);
        }

        $this->recordedRequests = [];
        $mock = new MockHandler($psrResponses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::tap(function (RequestInterface $req) {
            $this->recordedRequests[] = ['request' => $req];
        }));

        return new Client(
            apiKey: 'test-key',
            clientId: 'test-client',
            baseUrl: 'https://api.test.local',
            timeout: 1,
            maxRetries: 0,
            handler: $stack,
        );
    }

    public function getLastRequest(): RequestInterface
    {
        if ($this->recordedRequests === []) {
            throw new \RuntimeException('No HTTP request was recorded.');
        }
        return $this->recordedRequests[array_key_last($this->recordedRequests)]['request'];
    }

    /**
     * Load a JSON fixture by basename (without extension) from `tests/Fixtures/`.
     *
     * @return array<string, mixed>
     */
    public function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../tests/Fixtures/' . $name . '.json';
        if (!is_file($path)) {
            throw new \RuntimeException("Fixture not found: {$path}");
        }
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Fixture {$name} did not decode to an object.");
        }
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
