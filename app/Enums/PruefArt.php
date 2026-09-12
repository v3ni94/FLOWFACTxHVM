<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Art eines Befunds: blockiert die Veröffentlichung oder ist nur ein Hinweis
 * (Masterprompt-Abgleich B.4).
 */
enum PruefArt: string
{
    case Blockierend = 'blockierend';
    case Hinweis = 'hinweis';

    public function label(): string
    {
        return match ($this) {
            self::Blockierend => 'Blockierend',
            self::Hinweis => 'Hinweis',
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
            self::Blockierend => 'badge-error',
            self::Hinweis => 'badge-warning',
        };
    }
}
