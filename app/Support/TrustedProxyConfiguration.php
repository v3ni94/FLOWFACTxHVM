<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Übersetzt TRUSTED_PROXIES aus der Umgebung in die Laravel-Konfiguration der
 * vertrauenswürdigen Proxys (config/deploy.php).
 *
 * Die Zuordnung liegt in einer eigenen Klasse, damit bootstrap/app.php und die
 * Tests dieselbe Regel verwenden. Sie ist absichtlich frei von Zustand.
 * Architektur Abschnitt 5: "Falsche Client-IP hinter IONOS-Proxy".
 */
final class TrustedProxyConfiguration
{
    /**
     * Header, aus denen Schema, Host, Port und Client-IP gelesen werden.
     */
    public const int HEADERS = Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO;

    /**
     * Zerlegt den Konfigurationswert.
     *
     * @return '*'|list<string>|null null bedeutet: keinem Proxy vertrauen
     */
    public static function parse(mixed $value): string|array|null
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($value === '*' || $value === '**') {
            return '*';
        }

        $entries = array_values(array_filter(
            array_map(static fn (string $entry): string => trim($entry), explode(',', $value)),
            static fn (string $entry): bool => $entry !== '',
        ));

        return $entries === [] ? null : $entries;
    }

    /**
     * Wendet die Konfiguration auf die globale TrustProxies-Middleware an.
     */
    public static function apply(mixed $value): void
    {
        TrustProxies::flushState();

        $proxies = self::parse($value);

        if ($proxies !== null) {
            TrustProxies::at($proxies);
        }

        TrustProxies::withHeaders(self::HEADERS);
    }

    public static function isConfigured(mixed $value): bool
    {
        return self::parse($value) !== null;
    }
}
