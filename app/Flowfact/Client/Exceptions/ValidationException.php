<?php

declare(strict_types=1);

namespace App\Flowfact\Client\Exceptions;

/**
 * 400 oder 422: FLOWFACT hat die Nutzdaten abgelehnt. Der Antwortkörper
 * wird gekürzt mitgeführt, damit die Oberfläche den Grund anzeigen kann.
 *
 * @property array<string, mixed>|null $body
 */
final class ValidationException extends FlowfactException
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        string $message,
        ?int $httpStatus = null,
        public readonly ?array $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
