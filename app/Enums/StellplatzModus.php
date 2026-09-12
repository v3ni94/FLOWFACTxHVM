<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stellplatzmodus (Masterprompt-Abgleich B.1 Schritt 4 und B.2).
 *
 * optional und pflicht_zusaetzlich werden getrennt ausgewiesen und gehen nie
 * in die Warmmiete ein. pflicht_enthalten bedeutet: im Preis enthalten, kein
 * eigener Betrag erlaubt.
 */
enum StellplatzModus: string
{
    case Keiner = 'keiner';
    case Optional = 'optional';
    case PflichtEnthalten = 'pflicht_enthalten';
    case PflichtZusaetzlich = 'pflicht_zusaetzlich';

    public function label(): string
    {
        return match ($this) {
            self::Keiner => 'Kein Stellplatz',
            self::Optional => 'Optional hinzubuchbar',
            self::PflichtEnthalten => 'Verpflichtend, im Preis enthalten',
            self::PflichtZusaetzlich => 'Verpflichtend, zusätzlich zu zahlen',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $fall): string => $fall->value, self::cases()),
            array_map(fn (self $fall): string => $fall->label(), self::cases()),
        );
    }

    /**
     * Ob für diesen Modus ein eigener Stellplatzbetrag erfasst und getrennt
     * ausgewiesen wird.
     */
    public function verlangtBetrag(): bool
    {
        return in_array($this, [self::Optional, self::PflichtZusaetzlich], true);
    }
}
