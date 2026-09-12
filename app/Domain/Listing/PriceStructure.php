<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\HeizkostenStruktur;
use App\Enums\HeizkostenVersorgung;
use App\Enums\StellplatzModus;
use App\Models\Listing;
use App\Models\ListingPrice;

/**
 * Abbildung der Kostenstruktur (Masterprompt-Abgleich B.1 Schritt 4, B.2) auf
 * die Parameter des unverändert bleibenden RentCalculator und zurück.
 *
 * Heizkosten:
 * - enthalten: Versorgung zentral, Heizkosten in den Nebenkosten enthalten.
 * - zusaetzlich: Versorgung zentral, Heizkosten zusätzlich an den Vermieter.
 * - eigener_vertrag: Versorgung dezentral, keine Heizkosten erfassbar.
 *
 * Stellplatz:
 * - optional und pflicht_zusaetzlich: eigener Betrag, getrennt ausgewiesen,
 *   nie in der Warmmiete (Datenvertrag Abschnitt 3 Regel 4).
 * - pflicht_enthalten: im Preis enthalten, kein eigener Betrag erlaubt.
 * - keiner: kein Stellplatz, kein Betrag.
 */
final class PriceStructure
{
    public const string HINWEIS_STELLPLATZ_OPTIONAL = 'Stellplatz optional hinzubuchbar, Betrag zusätzlich zur Warmmiete.';

    public const string HINWEIS_STELLPLATZ_PFLICHT_ZUSAETZLICH = 'Stellplatz verpflichtend, Betrag zusätzlich zur Warmmiete.';

    public const string HINWEIS_STELLPLATZ_PFLICHT_ENTHALTEN = 'Stellplatz verpflichtend und im Preis enthalten.';

    public function __construct(
        private readonly RentCalculator $rentCalculator = new RentCalculator,
    ) {}

    public static function versorgung(HeizkostenStruktur $struktur): HeizkostenVersorgung
    {
        return $struktur === HeizkostenStruktur::EigenerVertrag
            ? HeizkostenVersorgung::Dezentral
            : HeizkostenVersorgung::Zentral;
    }

    public static function heizkostenEnthalten(HeizkostenStruktur $struktur): bool
    {
        return $struktur === HeizkostenStruktur::Enthalten;
    }

    /**
     * Rückabbildung der bisherigen Flags auf die Struktur. Ohne Versorgung
     * ist keine Aussage möglich.
     */
    public static function struktur(?HeizkostenVersorgung $versorgung, bool $heizkostenEnthalten): ?HeizkostenStruktur
    {
        return match ($versorgung) {
            null => null,
            HeizkostenVersorgung::Dezentral => HeizkostenStruktur::EigenerVertrag,
            HeizkostenVersorgung::Zentral => $heizkostenEnthalten ? HeizkostenStruktur::Enthalten : HeizkostenStruktur::Zusaetzlich,
        };
    }

    /**
     * Maßgebliche Struktur eines Objekts: die gespeicherte Struktur, sonst die
     * Ableitung aus den bisherigen Flags (Bestandsdaten des alten Assistenten).
     */
    public static function ermittle(Listing $listing): ?HeizkostenStruktur
    {
        $preis = $listing->price;

        if ($preis?->heizkosten_struktur !== null) {
            return $preis->heizkosten_struktur;
        }

        return self::struktur($listing->heizkosten_versorgung, (bool) $preis?->heizkosten_in_nebenkosten_enthalten);
    }

    /**
     * Schreibt die Struktur in den Preisdatensatz und leitet Flag und
     * Versorgung ab. Bei eigenem Versorgungsvertrag werden erfasste Heizkosten
     * entfernt (Datenvertrag Abschnitt 3 Regel 1). Die Warmmiete wird neu
     * berechnet, sobald Kaltmiete und Nebenkosten vorliegen.
     */
    public function apply(Listing $listing, HeizkostenStruktur $struktur): ListingPrice
    {
        $preis = $listing->price ?? $listing->price()->make();

        $preis->heizkosten_struktur = $struktur;
        $preis->heizkosten_in_nebenkosten_enthalten = self::heizkostenEnthalten($struktur);

        if ($struktur === HeizkostenStruktur::EigenerVertrag) {
            $preis->heizkosten_cent = null;
        }

        if ($preis->kaltmiete_cent !== null && $preis->nebenkosten_cent !== null) {
            $preis->warmmiete_cent = $this->rentCalculator->calculate(
                kaltmieteCent: $preis->kaltmiete_cent,
                nebenkostenCent: $preis->nebenkosten_cent,
                heizkostenCent: $preis->heizkosten_cent,
                heizkostenInNebenkostenEnthalten: $preis->heizkosten_in_nebenkosten_enthalten,
                versorgung: self::versorgung($struktur),
            )->warmmieteCent;
        }

        $listing->heizkosten_versorgung = self::versorgung($struktur);
        $listing->save();

        $preis->listing_id = $listing->id;
        $preis->save();
        $listing->setRelation('price', $preis);

        return $preis;
    }

    /**
     * Warmmiete nach den Regeln des Datenvertrags Abschnitt 3 für die
     * angegebene Struktur.
     *
     * @throws InvalidRentInputException
     */
    public function berechne(int $kaltmieteCent, int $nebenkostenCent, ?int $heizkostenCent, HeizkostenStruktur $struktur): RentResult
    {
        return $this->rentCalculator->calculate(
            kaltmieteCent: $kaltmieteCent,
            nebenkostenCent: $nebenkostenCent,
            heizkostenCent: $heizkostenCent,
            heizkostenInNebenkostenEnthalten: self::heizkostenEnthalten($struktur),
            versorgung: self::versorgung($struktur),
        );
    }

    /**
     * Ob der Stellplatzbetrag getrennt ausgewiesen wird (nie in der Warmmiete).
     */
    public static function stellplatzGetrenntAusgewiesen(StellplatzModus $modus): bool
    {
        return $modus->verlangtBetrag();
    }

    /**
     * Prüft Modus und Beträge auf Widersprüche.
     *
     * @throws InvalidRentInputException wenn bei pflicht_enthalten ein eigener Betrag erfasst ist
     */
    public static function pruefeStellplatz(StellplatzModus $modus, ?int $stellplatzMieteCent, ?int $stellplatzKaufpreisCent = null): void
    {
        if ($modus === StellplatzModus::PflichtEnthalten && self::stellplatzBetragErfasst($stellplatzMieteCent, $stellplatzKaufpreisCent)) {
            throw new InvalidRentInputException(
                'Bei einem verpflichtend enthaltenen Stellplatz darf kein eigener Stellplatzbetrag erfasst werden. Der Stellplatz ist im Preis enthalten.'
            );
        }
    }

    public static function stellplatzBetragErfasst(?int $stellplatzMieteCent, ?int $stellplatzKaufpreisCent = null): bool
    {
        return ($stellplatzMieteCent !== null && $stellplatzMieteCent > 0)
            || ($stellplatzKaufpreisCent !== null && $stellplatzKaufpreisCent > 0);
    }

    /**
     * Gesamtdarstellung für Inserat und Vorschau: Warmmiete unverändert, der
     * Stellplatzbetrag nur getrennt (Masterprompt-Abgleich B.8).
     *
     * @return array{warmmiete_cent: int, stellplatz_getrennt_cent: int|null, hinweis: string|null}
     */
    public static function gesamtdarstellung(int $warmmieteCent, StellplatzModus $modus, ?int $stellplatzMieteCent): array
    {
        self::pruefeStellplatz($modus, $stellplatzMieteCent);

        return [
            'warmmiete_cent' => $warmmieteCent,
            'stellplatz_getrennt_cent' => $modus->verlangtBetrag() ? $stellplatzMieteCent : null,
            'hinweis' => match ($modus) {
                StellplatzModus::Keiner => null,
                StellplatzModus::Optional => self::HINWEIS_STELLPLATZ_OPTIONAL,
                StellplatzModus::PflichtZusaetzlich => self::HINWEIS_STELLPLATZ_PFLICHT_ZUSAETZLICH,
                StellplatzModus::PflichtEnthalten => self::HINWEIS_STELLPLATZ_PFLICHT_ENTHALTEN,
            },
        ];
    }
}
