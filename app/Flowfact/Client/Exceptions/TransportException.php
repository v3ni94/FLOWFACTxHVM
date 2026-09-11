<?php

declare(strict_types=1);

namespace App\Flowfact\Client\Exceptions;

/**
 * Verbindungsfehler oder Zeitüberschreitung. Die Antwort ist unbekannt, eine
 * angelegte Entität kann existieren; der Ablauf in docs/connector.md
 * Abschnitt 3 findet sie beim nächsten Lauf über die Suche wieder.
 */
final class TransportException extends FlowfactException {}
