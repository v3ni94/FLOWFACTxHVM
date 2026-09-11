<?php

declare(strict_types=1);

namespace App\Flowfact\Client\Exceptions;

use RuntimeException;

/**
 * Basisklasse aller Fehler des FLOWFACT-Clients (docs/connector.md Abschnitt 2).
 *
 * Meldungen sind bereits vom Token bereinigt und dürfen in der Oberfläche
 * und im Übertragungsprotokoll angezeigt werden.
 */
class FlowfactException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }
}
