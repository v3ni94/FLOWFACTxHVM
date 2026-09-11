<?php

declare(strict_types=1);

namespace App\Flowfact\Client\Exceptions;

/**
 * 5xx: Fehler auf Seiten von FLOWFACT. Lesende Aufrufe werden im Client
 * einmal wiederholt, schreibende nur auf Job-Ebene (idempotenter Ablauf).
 */
final class ServerException extends FlowfactException {}
