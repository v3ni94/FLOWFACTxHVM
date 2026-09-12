<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\EnergieausweisStatus;
use App\Enums\PruefEbene;
use App\Models\Listing;

/**
 * Gesetzliche Energieangaben in Immobilienanzeigen (Masterprompt-Abgleich B.5).
 *
 * Einschätzung nach § 87 GEG; die Quelle war aus der Entwicklungsumgebung
 * nicht abrufbar und ist vor Livegang durch Rechtsanwalt oder Betreiber zu
 * bestätigen. Regel und Stand sind für den Adminbereich über regeln(), STAND
 * und QUELLE abrufbar.
 *
 * - Grundstücke und Stellplätze: keine Prüfung.
 * - Status vorhanden: Ausweisart, Kennwert, wesentlicher Energieträger der
 *   Heizung; bei Wohnobjekten zusätzlich Baujahr und Effizienzklasse.
 * - Status noch_nicht_vorhanden und beauftragt: blockierend, "wird
 *   nachgereicht" ist kein Ersatz.
 * - Status ausnahme_zu_pruefen: blockierend, bis ein Admin die Ausnahme mit
 *   Begründung bestätigt hat.
 * - Gewerbe (Nichtwohngebäude): Baujahr und Klasse sind keine Pflicht,
 *   getrennte Werte für Wärme und Strom sind optional.
 */
final class EnergyRequirements
{
    public const string STAND = '12.09.2026';

    public const string QUELLE = '§ 87 GEG, Einschätzung, Quelle aus der Entwicklungsumgebung nicht abrufbar, vor Livegang zu bestätigen';

    public const string MELDUNG_NOCH_NICHT_VORHANDEN = 'Der Energieausweis liegt noch nicht vor. Er muss spätestens bei der Besichtigung vorliegen, und die Anzeige muss die Pflichtangaben enthalten. Der Hinweis "wird nachgereicht" ist kein Ersatz. Die Veröffentlichung ist erst mit dem Status "vorhanden" oder mit einer bestätigten Ausnahme möglich.';

    public const string MELDUNG_AUSNAHME_OFFEN = 'Die Ausnahme von der Ausweispflicht muss ein Administrator mit Begründung bestätigen (z. B. Baudenkmal, kleines Gebäude, Abbruch). Bis dahin ist die Veröffentlichung blockiert.';

    private const int SCHRITT_FLAECHEN = 3;

    private const int SCHRITT_HEIZUNG = 4;

    private const int SCHRITT_ENERGIEAUSWEIS = 5;

    /**
     * Regeltext für den Adminbereich.
     *
     * @return list<string>
     */
    public static function regeln(): array
    {
        return [
            'Liegt ein Energieausweis vor, sind in der Anzeige anzugeben: Art des Ausweises (Bedarf oder Verbrauch), Endenergiebedarf oder Endenergieverbrauch, wesentlicher Energieträger der Heizung, bei Wohngebäuden das Baujahr und die Effizienzklasse (sofern der Ausweis eine Klasse enthält).',
            'Status "noch nicht vorhanden" oder "beauftragt" blockiert die Veröffentlichung. Der Ausweis muss spätestens bei der Besichtigung vorliegen; "wird nachgereicht" ist kein Ersatz.',
            'Status "Ausnahme zu prüfen" blockiert, bis ein Administrator die Ausnahme mit Begründung bestätigt hat (z. B. Baudenkmal, kleine Gebäude, Abbruch).',
            'Bei Grundstücken und Stellplätzen entfällt die Prüfung. Bei Gewerbe (Nichtwohngebäude) entfallen Baujahr und Klasse als Pflicht; getrennte Werte für Wärme und Strom sind optional.',
            'Stand '.self::STAND.'. '.self::QUELLE.'.',
        ];
    }

    /**
     * @return list<Befund>
     */
    public function pruefe(Listing $listing): array
    {
        if (! $listing->objektart->benoetigt('energieausweis')) {
            return [];
        }

        $energy = $listing->energy;
        $status = $energy?->status?->normalisiert();

        if ($energy === null || $status === null) {
            return [Befund::blockierend('energie.status', 'Energieausweisstatus', self::SCHRITT_ENERGIEAUSWEIS, 'Der Status des Energieausweises fehlt.')];
        }

        return match ($status) {
            EnergieausweisStatus::Vorhanden => $this->pruefeVorhanden($listing),
            EnergieausweisStatus::NochNichtVorhanden, EnergieausweisStatus::Beauftragt => [
                Befund::blockierend('energie.status', 'Energieausweis', self::SCHRITT_ENERGIEAUSWEIS, self::MELDUNG_NOCH_NICHT_VORHANDEN, PruefEbene::Gesetzlich),
            ],
            EnergieausweisStatus::AusnahmeZuPruefen => $energy->ausnahmeBestaetigt()
                ? []
                : [Befund::blockierend('energie.ausnahme', 'Ausnahme vom Energieausweis', self::SCHRITT_ENERGIEAUSWEIS, self::MELDUNG_AUSNAHME_OFFEN, PruefEbene::Gesetzlich)],
            default => [],
        };
    }

    /**
     * @return list<Befund>
     */
    private function pruefeVorhanden(Listing $listing): array
    {
        $energy = $listing->energy;
        $befunde = [];

        if ($energy?->ausweistyp === null) {
            $befunde[] = Befund::blockierend('energie.ausweistyp', 'Ausweisart', self::SCHRITT_ENERGIEAUSWEIS, 'Die Art des Energieausweises (Bedarf oder Verbrauch) ist Pflichtangabe.', PruefEbene::Gesetzlich);
        }

        if ($energy?->kennwert_kwh === null) {
            $befunde[] = Befund::blockierend('energie.kennwert_kwh', 'Energiekennwert', self::SCHRITT_ENERGIEAUSWEIS, 'Endenergiebedarf oder Endenergieverbrauch ist Pflichtangabe.', PruefEbene::Gesetzlich);
        }

        if ($listing->energietraeger === null) {
            $befunde[] = Befund::blockierend('energietraeger', 'Energieträger der Heizung', self::SCHRITT_HEIZUNG, 'Der wesentliche Energieträger der Heizung ist Pflichtangabe.', PruefEbene::Gesetzlich);
        }

        if (! $listing->objektart->istWohnobjekt()) {
            return $befunde;
        }

        if ($listing->baujahr === null && $energy?->baujahr_anlage === null) {
            $befunde[] = Befund::blockierend('baujahr', 'Baujahr', self::SCHRITT_FLAECHEN, 'Bei Wohngebäuden ist das Baujahr Pflichtangabe.', PruefEbene::Gesetzlich);
        }

        if ($energy?->effizienzklasse === null) {
            $befunde[] = Befund::blockierend('energie.effizienzklasse', 'Effizienzklasse', self::SCHRITT_ENERGIEAUSWEIS, 'Bei Wohngebäuden ist die Effizienzklasse Pflichtangabe, sofern der Ausweis eine Klasse enthält.', PruefEbene::Gesetzlich);
        }

        return $befunde;
    }
}
