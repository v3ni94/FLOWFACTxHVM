<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kostenstruktur der Heizkosten bei Miete (Masterprompt-Abgleich B.1 Schritt 4 und B.2).
 *
 * Ersetzt fachlich das Flag heizkosten_in_nebenkosten_enthalten plus
 * HeizkostenVersorgung. Beide bleiben als Ableitung erhalten
 * (App\Domain\Listing\PriceStructure), damit RentCalculator unverändert bleibt.
 */
enum HeizkostenStruktur: string
{
    case Enthalten = 'enthalten';
    case Zusaetzlich = 'zusaetzlich';
    case EigenerVertrag = 'eigener_vertrag';

    public function label(): string
    {
        return match ($this) {
            self::Enthalten => 'In den Nebenkosten enthalten',
            self::Zusaetzlich => 'Zusätzlich an den Vermieter',
            self::EigenerVertrag => 'Eigener Versorgungsvertrag des Mieters',
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
}
