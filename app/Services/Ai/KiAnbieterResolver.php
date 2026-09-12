<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Einheitliche Auflösung des KI-Anbieters für TextGenerator und TextReviser
 * (ADR-009, Masterprompt Abschnitt 16, Masterprompt-Abgleich B.7): die
 * Anthropic-Umsetzung wird verwendet, sobald sie konfiguriert ist (Anbieter
 * "anthropic" und hinterlegter Schlüssel), sonst die regelbasierte
 * Platzhalterumsetzung (Vorlagenmodus). Beide Bindungen in
 * AppServiceProvider nutzen dieselbe Regel, hier an einer Stelle.
 */
final class KiAnbieterResolver
{
    public static function resolve(KiFaehig $anthropic, KiFaehig $platzhalter): KiFaehig
    {
        return $anthropic->isConfigured() ? $anthropic : $platzhalter;
    }
}
