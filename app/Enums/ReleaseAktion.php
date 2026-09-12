<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Aktion einer Freigabeversion (Masterprompt-Abgleich B.6).
 */
enum ReleaseAktion: string
{
    case FlowfactSpeichern = 'flowfact_speichern';
    case Veroeffentlichen = 'veroeffentlichen';
    case Deaktivieren = 'deaktivieren';

    public function label(): string
    {
        return match ($this) {
            self::FlowfactSpeichern => 'In FLOWFACT speichern',
            self::Veroeffentlichen => 'Veröffentlichen',
            self::Deaktivieren => 'Deaktivieren',
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
