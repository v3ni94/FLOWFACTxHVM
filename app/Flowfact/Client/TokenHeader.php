<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

/**
 * Übertragungsformen des API-Tokens. Standard ist die Kopfzeile
 * x-ff-api-token aus dem offiziellen SDK (flowfact-api.md Abschnitt 3.1).
 * Die übrigen Formen dienen der Diagnose am echten Konto, falls der im
 * Backend erzeugte Zugangsschlüssel anders erwartet wird (offener Punkt 1
 * in flowfact-api.md Abschnitt 9).
 */
final class TokenHeader
{
    public const string X_FF_API_TOKEN = 'x-ff-api-token';

    public const string TOKEN = 'token';

    public const string BEARER = 'bearer';

    public const string X_API_KEY = 'x-api-key';

    public const string STANDARD = self::X_FF_API_TOKEN;

    /** @return list<string> */
    public static function alle(): array
    {
        return [self::X_FF_API_TOKEN, self::TOKEN, self::BEARER, self::X_API_KEY];
    }

    public static function label(string $form): string
    {
        return match ($form) {
            self::X_FF_API_TOKEN => 'Kopfzeile x-ff-api-token (Standard)',
            self::TOKEN => 'Kopfzeile token',
            self::BEARER => 'Kopfzeile Authorization: Bearer',
            self::X_API_KEY => 'Kopfzeile x-api-key',
            default => $form,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $form, string $token): array
    {
        return match ($form) {
            self::TOKEN => ['token' => $token],
            self::BEARER => ['Authorization' => 'Bearer '.$token],
            self::X_API_KEY => ['x-api-key' => $token],
            default => ['x-ff-api-token' => $token],
        };
    }
}
