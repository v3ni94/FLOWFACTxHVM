<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status des Energieausweises (Datenvertrag Abschnitt 2.4, Masterprompt-Abgleich B.2 und B.5).
 *
 * Die neuen Werte vorhanden, noch_nicht_vorhanden, beauftragt und
 * ausnahme_zu_pruefen sind maßgeblich. Die älteren Werte liegt_vor,
 * in_erstellung und nicht_erforderlich bleiben lesbar und werden über
 * normalisiert() auf die neuen Werte abgebildet.
 */
enum EnergieausweisStatus: string
{
    case Vorhanden = 'vorhanden';
    case NochNichtVorhanden = 'noch_nicht_vorhanden';
    case Beauftragt = 'beauftragt';
    case AusnahmeZuPruefen = 'ausnahme_zu_pruefen';

    /** Älterer Wert, entspricht vorhanden. */
    case LiegtVor = 'liegt_vor';

    /** Älterer Wert, entspricht ausnahme_zu_pruefen. */
    case NichtErforderlich = 'nicht_erforderlich';

    /** Älterer Wert, entspricht beauftragt. */
    case InErstellung = 'in_erstellung';

    public function label(): string
    {
        return match ($this) {
            self::Vorhanden => 'Vorhanden',
            self::NochNichtVorhanden => 'Noch nicht vorhanden',
            self::Beauftragt => 'Beauftragt',
            self::AusnahmeZuPruefen => 'Ausnahme zu prüfen',
            self::LiegtVor => 'Liegt vor',
            self::NichtErforderlich => 'Nicht erforderlich',
            self::InErstellung => 'In Erstellung',
        };
    }

    /**
     * Auswahlwerte für neue Erfassungen: nur die maßgeblichen Werte.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $fall): string => $fall->value, self::aktuelleWerte()),
            array_map(fn (self $fall): string => $fall->label(), self::aktuelleWerte()),
        );
    }

    /**
     * @return list<self>
     */
    public static function aktuelleWerte(): array
    {
        return [self::Vorhanden, self::NochNichtVorhanden, self::Beauftragt, self::AusnahmeZuPruefen];
    }

    /**
     * Bildet ältere Werte auf die maßgeblichen Werte ab.
     */
    public function normalisiert(): self
    {
        return match ($this) {
            self::LiegtVor => self::Vorhanden,
            self::InErstellung => self::Beauftragt,
            self::NichtErforderlich => self::AusnahmeZuPruefen,
            default => $this,
        };
    }

    public function badgeClass(): string
    {
        return match ($this->normalisiert()) {
            self::Vorhanden => 'badge-success',
            self::NochNichtVorhanden, self::Beauftragt => 'badge-warning',
            self::AusnahmeZuPruefen => 'badge-info',
            default => 'badge-neutral',
        };
    }
}
