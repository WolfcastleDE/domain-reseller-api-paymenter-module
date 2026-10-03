<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

use Exception;

/**
 * Raised for every failed call against the Domain Reseller API.
 *
 * Carries the HTTP status code and the decoded response body so callers can
 * react to specific situations (404 = unknown domain, 409 = already
 * registered, 402 = wallet empty, ...).
 */
class ApiException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly array $response = [],
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function response(): array
    {
        return $this->response;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404 || ($this->status === 200 && preg_match('/not found/i', $this->getMessage()) === 1);
    }

    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    public function isConnectionError(): bool
    {
        return $this->status === 0;
    }
}
