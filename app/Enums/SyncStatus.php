<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Übertragungsstatus gegenüber FLOWFACT (Datenvertrag Abschnitt 4.2).
 */
enum SyncStatus: string
{
    case NichtUebertragen = 'nicht_uebertragen';
    case UebertragungLaeuft = 'uebertragung_laeuft';
    case Uebertragen = 'uebertragen';
    case GeaendertSeitUebertragung = 'geaendert_seit_uebertragung';
    case Fehlgeschlagen = 'fehlgeschlagen';

    public function label(): string
    {
        return match ($this) {
            self::NichtUebertragen => 'Nicht übertragen',
            self::UebertragungLaeuft => 'Übertragung läuft',
            self::Uebertragen => 'Übertragen',
            self::GeaendertSeitUebertragung => 'Geändert seit Übertragung',
            self::Fehlgeschlagen => 'Fehlgeschlagen',
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
            self::Fehlgeschlagen => 'badge-error',
            self::UebertragungLaeuft, self::GeaendertSeitUebertragung => 'badge-warning',
            self::Uebertragen => 'badge-success',
            self::NichtUebertragen => 'badge-neutral',
        };
    }
}
