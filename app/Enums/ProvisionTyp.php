<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Provisionsart (Datenvertrag Abschnitt 2.3).
 */
enum ProvisionTyp: string
{
    case Provisionsfrei = 'provisionsfrei';
    case Provisionspflichtig = 'provisionspflichtig';

    public function label(): string
    {
        return match ($this) {
            self::Provisionsfrei => 'Provisionsfrei',
            self::Provisionspflichtig => 'Provisionspflichtig',
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
