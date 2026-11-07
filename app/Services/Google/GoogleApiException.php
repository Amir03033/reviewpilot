<?php

namespace App\Services\Google;

use RuntimeException;

class GoogleApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message, $status);
    }

    /** 429, 5xx and network errors are worth retrying. */
    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isPermissionDenied(): bool
    {
        return $this->status === 403;
    }

    public function errorStatus(): ?string
    {
        return $this->body['error']['status'] ?? null;
    }
}
