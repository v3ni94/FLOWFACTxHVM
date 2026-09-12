<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Prüfebene eines Befunds vor der Veröffentlichung (Masterprompt-Abgleich B.4).
 */
enum PruefEbene: string
{
    case Intern = 'intern';
    case Flowfact = 'flowfact';
    case Portal = 'portal';
    case Gesetzlich = 'gesetzlich';

    public function label(): string
    {
        return match ($this) {
            self::Intern => 'Intern',
            self::Flowfact => 'FLOWFACT',
            self::Portal => 'Portal',
            self::Gesetzlich => 'Gesetzlich',
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
            self::Intern => 'badge-neutral',
            self::Flowfact, self::Portal => 'badge-info',
            self::Gesetzlich => 'badge-error',
        };
    }
}
