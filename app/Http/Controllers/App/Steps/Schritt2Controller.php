<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Domain\Settings\SettingsRepository;
use App\Enums\AdressFreigabe;
use App\Http\Requests\Listing\Step2AdresseRequest;
use App\Http\Requests\Listing\Step2UebernahmeRequest;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Schritt 2: Adresse und Lage (Masterprompt-Abgleich B.1 Schritt 2).
 *
 * Die internen Angaben (listing_internals, "nicht Teil des Inserats") werden
 * auf derselben Seite in einer aufklappbaren Karte erfasst, weil sie
 * unmittelbar zur Lage gehören (Gebäudebezeichnung, Einheitsnummer, Lage im
 * Gebäude), landen aber ausschließlich in listing_internals (ADR-003).
 */
final class Schritt2Controller extends AbstractStep implements StepHandler
{
    /**
     * Felder, die "Gebäudedaten aus vorhandenem Objekt übernehmen" in leere
     * Zielfelder kopiert (Masterprompt-Abgleich B.1 Schritt 2). Nur
     * gebäudebezogene, nie einheitsspezifische Daten.
     *
     * @var array<string, string>
     */
    private const array UEBERNAHME_FELDER = [
        'strasse' => 'Straße',
        'hausnummer' => 'Hausnummer',
        'plz' => 'Postleitzahl',
        'ort' => 'Ort',
        'stadtteil' => 'Stadtteil',
        'baujahr' => 'Baujahr',
        'etagen_gesamt' => 'Etagen gesamt',
        'heizungsart' => 'Heizungsart',
        'energietraeger' => 'Energieträger',
        'heizung_waermeabgabe' => 'Wärmeabgabe',
        'heizung_warmwasser' => 'Warmwasserbereitung',
    ];

    protected function schritt(): int
    {
        return 2;
    }

    public function show(Request $request, Listing $listing): View
    {
        $this->authorizeAndLoad($request, $listing);

        $standardLand = $listing->land ?? app(SettingsRepository::class)->get('firma.land', 'DE');

        $moeglicheQuellen = Listing::query()
            ->where('id', '!=', $listing->id)
            ->when($listing->plz, fn ($query) => $query->where('plz', $listing->plz))
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'objektnummer', 'strasse', 'hausnummer', 'plz', 'ort']);

        return view('app.listings.schritte.schritt-2', array_merge($this->headerDaten($listing), [
            'standardLand' => $standardLand,
            'moeglicheQuellen' => $moeglicheQuellen,
        ]));
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        if ($request->string('aktion')->value() === 'uebernehmen') {
            return $this->uebernehmen($request, $listing);
        }

        /** @var Step2AdresseRequest $validiert */
        $validiert = app(Step2AdresseRequest::class);
        $daten = $validiert->validated();

        $this->saveWithTracking($listing, function (Listing $listing) use ($daten): void {
            $this->anwenden($listing, $daten);
        });

        return $this->redirectNachSpeichern($request, $listing);
    }

    public function autosave(Request $request, Listing $listing): JsonResponse
    {
        try {
            $daten = Validator::make($request->all(), Step2AdresseRequest::autosaveRegeln())->validate();
        } catch (ValidationException $exception) {
            return $this->autosaveFehler($exception);
        }

        $this->saveWithTracking($listing, function (Listing $listing) use ($daten): void {
            $this->anwenden($listing, $daten);
        });

        return $this->autosaveErfolg($listing);
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function anwenden(Listing $listing, array $daten): void
    {
        $listingFelder = ['strasse', 'hausnummer', 'adresszusatz', 'plz', 'ort', 'stadtteil', 'land'];
        $aenderungen = [];

        foreach ($listingFelder as $feld) {
            if (array_key_exists($feld, $daten)) {
                $aenderungen[$feld] = $this->leerAlsNull($daten[$feld]);
            }
        }

        if (array_key_exists('adress_freigabe', $daten) && $daten['adress_freigabe'] !== null) {
            $aenderungen['adress_freigabe'] = AdressFreigabe::from($daten['adress_freigabe']);
        }

        if ($aenderungen !== []) {
            $listing->update($aenderungen);
        }

        $interneFelder = [
            'gebaeudebezeichnung', 'einheitsnummer', 'lage_im_gebaeude', 'verwaltungsobjekt_referenz',
            'eigentuemer_name', 'eigentuemer_kontakt', 'interne_notizen', 'schluessel_hinweis',
            'besichtigung_intern', 'kalkulation_notiz',
        ];

        $interneAenderungen = [];

        foreach ($interneFelder as $feld) {
            if (array_key_exists($feld, $daten)) {
                $interneAenderungen[$feld] = $this->leerAlsNull($daten[$feld]);
            }
        }

        if ($interneAenderungen !== []) {
            $listing->internal()->updateOrCreate([], $interneAenderungen);
        }
    }

    private function uebernehmen(Request $request, Listing $listing): RedirectResponse
    {
        /** @var Step2UebernahmeRequest $validiert */
        $validiert = app(Step2UebernahmeRequest::class);
        $daten = $validiert->validated();

        $quelle = Listing::query()->with('internal')->findOrFail($daten['quelle_listing_id']);

        $uebernommen = [];
        $aenderungen = [];

        foreach (self::UEBERNAHME_FELDER as $feld => $label) {
            if ($listing->{$feld} === null && $quelle->{$feld} !== null) {
                $aenderungen[$feld] = $quelle->{$feld};
                $uebernommen[] = $label;
            }
        }

        if ($aenderungen !== []) {
            $this->saveWithTracking($listing, fn (Listing $listing) => $listing->update($aenderungen));
        }

        $ziel = $listing->internal;
        $quelleGebaeudebezeichnung = $quelle->internal?->gebaeudebezeichnung;

        if (($ziel?->gebaeudebezeichnung ?? null) === null && $quelleGebaeudebezeichnung !== null) {
            $listing->internal()->updateOrCreate([], ['gebaeudebezeichnung' => $quelleGebaeudebezeichnung]);
            $uebernommen[] = 'Gebäudebezeichnung';
        }

        $meldung = $uebernommen === []
            ? 'Aus dem gewählten Objekt gab es keine leeren Felder zum Übernehmen.'
            : 'Übernommen aus '.$quelle->objektnummer.': '.implode(', ', $uebernommen).'.';

        return redirect()
            ->route('app.listings.step', ['listing' => $listing, 'schritt' => 2])
            ->with('status', $meldung);
    }
}
