<?php

declare(strict_types=1);

namespace App\Flowfact\Client\Exceptions;

/**
 * 429: Ratenbegrenzung. Jobs reihen sich mit dem Retry-After-Wert neu ein.
 */
final class RateLimitException extends FlowfactException
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds = 60,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $previous);
    }
}
