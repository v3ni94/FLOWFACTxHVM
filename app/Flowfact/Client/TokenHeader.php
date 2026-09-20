<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

/**
 * Übertragungsformen des FLOWFACT-Zugangs. Standard und einzig vollständig
 * bestätigter Weg ist der Tausch gegen ein Cognito-Token (flowfact-api.md
 * Abschnitt 3.4, developers.flowfact.com/api, bestätigt 21.09.2026): der in
 * den Einstellungen hinterlegte Zugangsschlüssel wird über
 * admin-token-service gegen ein zeitlich begrenztes Sitzungstoken
 * eingetauscht (App\Flowfact\Client\CognitoTokenCache), das als Kopfzeile
 * "cognitoToken" gesendet wird.
 *
 * Die übrigen Formen senden den Zugangsschlüssel unverändert in
 * verschiedenen Kopfzeilen. Sie dienten der Ursachensuche, bevor der
 * Cognito-Weg bekannt war (offener Punkt 1 in flowfact-api.md Abschnitt 9),
 * und bleiben als manuelle Diagnose- und Rückfalloption erhalten.
 */
final class TokenHeader
{
    public const string COGNITO_TOKEN = 'cognito_token';

    public const string X_FF_API_TOKEN = 'x-ff-api-token';

    public const string TOKEN = 'token';

    public const string BEARER = 'bearer';

    public const string X_API_KEY = 'x-api-key';

    public const string STANDARD = self::COGNITO_TOKEN;

    /** @return list<string> */
    public static function alle(): array
    {
        return [self::COGNITO_TOKEN, self::X_FF_API_TOKEN, self::TOKEN, self::BEARER, self::X_API_KEY];
    }

    public static function label(string $form): string
    {
        return match ($form) {
            self::COGNITO_TOKEN => 'Cognito-Token, automatischer Tausch (Standard)',
            self::X_FF_API_TOKEN => 'Kopfzeile x-ff-api-token, ungetauscht (nur Diagnose)',
            self::TOKEN => 'Kopfzeile token, ungetauscht (nur Diagnose)',
            self::BEARER => 'Kopfzeile Authorization: Bearer, ungetauscht (nur Diagnose)',
            self::X_API_KEY => 'Kopfzeile x-api-key, ungetauscht (nur Diagnose)',
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
            self::X_FF_API_TOKEN => ['x-ff-api-token' => $token],
            default => ['cognitoToken' => $token],
        };
    }
}
