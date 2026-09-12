<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Domain\Listing\InvalidRentInputException;
use App\Domain\Listing\PriceStructure;
use App\Enums\Energietraeger;
use App\Enums\HeizkostenStruktur;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Heizungsart;
use App\Enums\ProvisionTyp;
use App\Enums\StellplatzModus;
use App\Enums\Waermeabgabe;
use App\Enums\Warmwasserbereitung;
use App\Http\Requests\Listing\Step4PreiseRequest;
use App\Models\Listing;
use App\Models\ListingPrice;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Schritt 4: Preise und Heizung (Masterprompt-Abgleich B.1 Schritt 4).
 */
final class Schritt4Controller extends AbstractStep implements StepHandler
{
    protected function schritt(): int
    {
        return 4;
    }

    public function show(Request $request, Listing $listing): View
    {
        $this->authorizeAndLoad($request, $listing);
        $listing->loadMissing('price');

        return view('app.listings.schritte.schritt-4', array_merge($this->headerDaten($listing), [
            'struktur' => PriceStructure::ermittle($listing),
        ]));
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        /** @var Step4PreiseRequest $validiert */
        $validiert = app(Step4PreiseRequest::class);
        $daten = $validiert->validated();

        try {
            $this->saveWithTracking($listing, function (Listing $listing) use ($daten): void {
                $this->anwenden($listing, $daten);
            });
        } catch (InvalidRentInputException $exception) {
            throw ValidationException::withMessages(['heizkosten' => $exception->getMessage()]);
        }

        return $this->redirectNachSpeichern($request, $listing);
    }

    public function autosave(Request $request, Listing $listing): JsonResponse
    {
        try {
            $daten = Validator::make($request->all(), Step4PreiseRequest::autosaveRegeln($listing))->validate();
        } catch (ValidationException $exception) {
            return $this->autosaveFehler($exception);
        }

        try {
            $this->saveWithTracking($listing, function (Listing $listing) use ($daten): void {
                $this->anwenden($listing, $daten);
            });
        } catch (InvalidRentInputException $exception) {
            return $this->autosaveFehler(ValidationException::withMessages(['heizkosten' => $exception->getMessage()]));
        }

        return $this->autosaveErfolg($listing);
    }

    /**
     * @param  array<string, mixed>  $daten
     *
     * @throws InvalidRentInputException
     */
    private function anwenden(Listing $listing, array $daten): void
    {
        $heizungAenderungen = [];

        foreach ([
            'heizungsart' => Heizungsart::class,
            'energietraeger' => Energietraeger::class,
            'heizung_waermeabgabe' => Waermeabgabe::class,
            'heizung_warmwasser' => Warmwasserbereitung::class,
        ] as $feld => $enum) {
            if (array_key_exists($feld, $daten)) {
                $heizungAenderungen[$feld] = $this->enumOderNull($enum, $daten[$feld]);
            }
        }

        if ($heizungAenderungen !== []) {
            $listing->update($heizungAenderungen);
        }

        if ($listing->istMiete()) {
            $this->anwendenMiete($listing, $daten);

            return;
        }

        $this->anwendenKauf($listing, $daten);
    }

    /**
     * @param  array<string, mixed>  $daten
     *
     * @throws InvalidRentInputException
     */
    private function anwendenMiete(Listing $listing, array $daten): void
    {
        /** @var ListingPrice $preis */
        $preis = $listing->price ?? $listing->price()->make();
        $preis->listing_id = $listing->id;

        if (array_key_exists('kaltmiete', $daten)) {
            $preis->kaltmiete_cent = $this->betrag($daten['kaltmiete']);
        }

        if (array_key_exists('nebenkosten', $daten)) {
            $preis->nebenkosten_cent = $this->betrag($daten['nebenkosten']);
        }

        if (array_key_exists('heizkosten', $daten)) {
            $preis->heizkosten_cent = $this->betrag($daten['heizkosten']);
        }

        if (array_key_exists('kaution', $daten)) {
            $preis->kaution_cent = $this->betrag($daten['kaution']);
        }

        if (array_key_exists('stellplatz_modus', $daten) && $daten['stellplatz_modus'] !== null) {
            $preis->stellplatz_modus = StellplatzModus::from($daten['stellplatz_modus']);
        }

        if (array_key_exists('stellplatz_miete', $daten)) {
            $preis->stellplatz_miete_cent = $this->betrag($daten['stellplatz_miete']);
        }

        $this->uebernehmeProvision($preis, $daten);

        $preis->kaufpreis_cent = null;
        $preis->hausgeld_cent = null;
        $preis->stellplatz_kaufpreis_cent = null;
        $preis->stellplatz_im_kaufpreis = null;
        $preis->mieteinnahmen_ist_cent = null;

        PriceStructure::pruefeStellplatz($preis->stellplatz_modus ?? StellplatzModus::Keiner, $preis->stellplatz_miete_cent);

        $preis->save();
        $listing->setRelation('price', $preis);

        $struktur = (array_key_exists('heizkosten_struktur', $daten) && $daten['heizkosten_struktur'] !== null)
            ? HeizkostenStruktur::from($daten['heizkosten_struktur'])
            : PriceStructure::ermittle($listing);

        if ($struktur !== null) {
            (new PriceStructure)->apply($listing, $struktur);
        }
    }

    /**
     * @param  array<string, mixed>  $daten
     *
     * @throws InvalidRentInputException
     */
    private function anwendenKauf(Listing $listing, array $daten): void
    {
        /** @var ListingPrice $preis */
        $preis = $listing->price ?? $listing->price()->make();
        $preis->listing_id = $listing->id;

        if (array_key_exists('kaufpreis', $daten)) {
            $preis->kaufpreis_cent = $this->betrag($daten['kaufpreis']);
        }

        if (array_key_exists('hausgeld', $daten)) {
            $preis->hausgeld_cent = $this->betrag($daten['hausgeld']);
        }

        if (array_key_exists('mieteinnahmen_ist', $daten)) {
            $preis->mieteinnahmen_ist_cent = $this->betrag($daten['mieteinnahmen_ist']);
        }

        if (array_key_exists('stellplatz_modus', $daten) && $daten['stellplatz_modus'] !== null) {
            $modus = StellplatzModus::from($daten['stellplatz_modus']);
            $preis->stellplatz_modus = $modus;
            $preis->stellplatz_im_kaufpreis = $modus === StellplatzModus::PflichtEnthalten;
        }

        if (array_key_exists('stellplatz_kaufpreis', $daten)) {
            $preis->stellplatz_kaufpreis_cent = $this->betrag($daten['stellplatz_kaufpreis']);
        }

        $this->uebernehmeProvision($preis, $daten);

        $preis->kaltmiete_cent = null;
        $preis->nebenkosten_cent = null;
        $preis->heizkosten_cent = null;
        $preis->heizkosten_in_nebenkosten_enthalten = false;
        $preis->heizkosten_struktur = null;
        $preis->warmmiete_cent = null;
        $preis->kaution_cent = null;
        $preis->stellplatz_miete_cent = null;

        PriceStructure::pruefeStellplatz($preis->stellplatz_modus ?? StellplatzModus::Keiner, null, $preis->stellplatz_kaufpreis_cent);

        $preis->save();
        $listing->setRelation('price', $preis);

        if (array_key_exists('heizkosten_versorgung', $daten) && $daten['heizkosten_versorgung'] !== null) {
            $listing->heizkosten_versorgung = HeizkostenVersorgung::from($daten['heizkosten_versorgung']);
            $listing->save();
        }
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function uebernehmeProvision(ListingPrice $preis, array $daten): void
    {
        if (array_key_exists('provision_typ', $daten) && $daten['provision_typ'] !== null) {
            $preis->provision_typ = ProvisionTyp::from($daten['provision_typ']);
        }

        if (array_key_exists('provision_text', $daten)) {
            $preis->provision_text = $this->leerAlsNull($daten['provision_text']);
        }

        if (array_key_exists('provision_bestaetigt', $daten)) {
            $preis->provision_bestaetigt = filter_var($daten['provision_bestaetigt'], FILTER_VALIDATE_BOOLEAN);
        }
    }

    private function betrag(mixed $wert): ?int
    {
        $wert = $this->leerAlsNull($wert);

        return $wert === null ? null : Money::parse((string) $wert);
    }
}
