<?php

declare(strict_types=1);

namespace App\Flowfact\Client\Exceptions;

/**
 * 401 oder 403: Token ungültig oder Rechte fehlen. Wird nie automatisch
 * wiederholt, weil eine Wiederholung das Ergebnis nicht ändert.
 */
final class AuthenticationException extends FlowfactException
{
    public const string MELDUNG = 'Token ungültig oder Rechte fehlen';
}
