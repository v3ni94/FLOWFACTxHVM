<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Gemeinsame Mindestschnittstelle von TextGenerator und TextReviser (ADR-009,
 * Masterprompt Abschnitt 16): Auskunft, ob eine Umsetzung tatsächlich einen
 * externen KI-Anbieter aufruft. Grundlage für KiAnbieterResolver, das die
 * gleiche Auflösung ("Anthropic, wenn konfiguriert, sonst Platzhalter") für
 * beide Bindungen ohne Duplikat bereitstellt.
 */
interface KiFaehig
{
    public function isConfigured(): bool;
}
