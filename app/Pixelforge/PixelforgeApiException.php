<?php

namespace App\Pixelforge;

use RuntimeException;
use Throwable;

/**
 * A failed call to Pixelforge. $status is null when no response arrived at all
 * (timeout, connection reset): the request may or may not have been processed.
 */
final class PixelforgeApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Worth trying again later with the same Idempotency-Key: nothing was
     * created, or we can't tell. A spent daily quota will not clear in minutes,
     * so that one is not retried.
     */
    public function isRetryable(): bool
    {
        if ($this->status === null) {
            return true;
        }

        if ($this->errorCode === 'daily_limit_exceeded') {
            return false;
        }

        return $this->status === 429 || $this->status >= 500;
    }
}
