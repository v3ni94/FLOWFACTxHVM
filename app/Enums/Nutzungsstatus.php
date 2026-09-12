<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Nutzungsstatus des Objekts (Masterprompt-Abgleich B.2).
 */
enum Nutzungsstatus: string
{
    case Leerstehend = 'leerstehend';
    case Vermietet = 'vermietet';
    case AnderweitigBelegt = 'anderweitig_belegt';
    case Unbekannt = 'unbekannt';

    public function label(): string
    {
        return match ($this) {
            self::Leerstehend => 'Leerstehend',
            self::Vermietet => 'Vermietet',
            self::AnderweitigBelegt => 'Anderweitig belegt',
            self::Unbekannt => 'Unbekannt',
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

    public function badgeClass(): string
    {
        return match ($this) {
            self::Leerstehend => 'badge-success',
            self::Vermietet, self::AnderweitigBelegt => 'badge-warning',
            self::Unbekannt => 'badge-neutral',
        };
    }
}
