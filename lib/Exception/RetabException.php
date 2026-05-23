<?php

declare(strict_types=1);

// @oagen-ignore-file
//
// Hand-maintained base exception for the Retab PHP SDK. Every typed
// exception emitted by the generator under \Retab\Exception\* should
// extend this class. Subclass surface kept minimal here — downstream
// runtime work can add HTTP status accessors, request-id capture, etc.

namespace Retab\Exception;

class RetabException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context Additional structured context (request id, HTTP status, etc.).
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message, $code, $previous);
    }
}
