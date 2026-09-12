<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Adressfreigabe im Inserat (Masterprompt-Abgleich B.1 Schritt 2, B.2, B.7).
 *
 * Das ältere Flag adresse_im_inserat_anzeigen bleibt erhalten und wird aus
 * diesem Wert abgeleitet: true bei vollstaendig, sonst false.
 */
enum AdressFreigabe: string
{
    case Vollstaendig = 'vollstaendig';
    case NurPlzOrt = 'nur_plz_ort';

    public function label(): string
    {
        return match ($this) {
            self::Vollstaendig => 'Vollständige Adresse',
            self::NurPlzOrt => 'Nur PLZ und Ort',
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

    public function adresseAnzeigen(): bool
    {
        return $this === self::Vollstaendig;
    }

    public static function ausAnzeigen(bool $anzeigen): self
    {
        return $anzeigen ? self::Vollstaendig : self::NurPlzOrt;
    }
}
