<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Richtung eines Übertragungsprotokolleintrags (Datenvertrag Abschnitt 2.10).
 */
enum TransferRichtung: string
{
    case Ausgehend = 'ausgehend';
    case Eingehend = 'eingehend';

    public function label(): string
    {
        return match ($this) {
            self::Ausgehend => 'Ausgehend',
            self::Eingehend => 'Eingehend',
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
