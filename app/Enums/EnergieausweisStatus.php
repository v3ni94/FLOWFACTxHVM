<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status des Energieausweises (Datenvertrag Abschnitt 2.4).
 */
enum EnergieausweisStatus: string
{
    case LiegtVor = 'liegt_vor';
    case NichtErforderlich = 'nicht_erforderlich';
    case InErstellung = 'in_erstellung';

    public function label(): string
    {
        return match ($this) {
            self::LiegtVor => 'Liegt vor',
            self::NichtErforderlich => 'Nicht erforderlich',
            self::InErstellung => 'In Erstellung',
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
