<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\InvalidRentInputException;
use App\Domain\Listing\ListingChangeTracker;
use App\Domain\Listing\RentCalculator;
use App\Enums\Ausstattungsqualitaet;
use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use App\Enums\Energietraeger;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Heizungsart;
use App\Enums\Objektart;
use App\Enums\ProvisionTyp;
use App\Enums\StellplatzTyp;
use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Vermarktungsart;
use App\Enums\Zustand;
use App\Flowfact\Sync\PublishingService;
use App\Http\Controllers\App\Support\ListingPreviewBuilder;
use App\Http\Controllers\App\Support\WizardSteps;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\Step1GrunddatenRequest;
use App\Http\Requests\Listing\Step2FlaechenRequest;
use App\Http\Requests\Listing\Step3EnergieRequest;
use App\Http\Requests\Listing\Step4PreiseRequest;
use App\Http\Requests\Listing\Step6TexteRequest;
use App\Http\Requests\Listing\Step7InternRequest;
use App\Models\Listing;
use App\Support\Money;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Erfassungsassistent mit acht Schritten (Datenvertrag Abschnitt 5). Jeder
 * Schritt validiert nur seine eigenen Felder über ein eigenes FormRequest,
 * Entwürfe dürfen unvollständig gespeichert werden.
 */
class ListingWizardController extends Controller
{
    use AuthorizesRequests;

    /**
     * @var list<string>
     */
    private const array AUSSTATTUNGS_SCHLUESSEL = [
        'balkon', 'terrasse', 'garten', 'keller', 'aufzug', 'einbaukueche',
        'gaeste_wc', 'barrierefrei', 'moebliert', 'wg_geeignet', 'haustiere_erlaubt',
    ];

    public function step(Listing $listing, int $schritt): View
    {
        $this->authorize('update', $listing);
        abort_unless(WizardSteps::istGueltig($schritt), 404);

        $listing->load(['price', 'energy', 'internal', 'media', 'texts', 'ansprechpartner']);

        $daten = [
            'listing' => $listing,
            'schritt' => $schritt,
            'vollstaendigkeit' => app(CompletenessCheck::class)->check($listing),
        ];

        if ($schritt === 4) {
            $daten['warmmieteVorschau'] = $this->warmmieteVorschau($listing);
        }

        if ($schritt === 6) {
            $daten['textvorschlaege'] = $listing->texts
                ->where('quelle', TextQuelle::Ki)
                ->where('uebernommen', false)
                ->sortByDesc('created_at')
                ->groupBy(fn ($text) => $text->feld->value);
        }

        if ($schritt === 8) {
            $publishingService = app(PublishingService::class);

            $daten['vorschau'] = app(ListingPreviewBuilder::class)->build($listing);
            $daten['publishingConfigured'] = $publishingService->isConfigured();
            $daten['portale'] = $publishingService->isConfigured() ? $publishingService->portals() : [];
        }

        return view('app.listings.schritte.schritt-'.$schritt, $daten);
    }

    public function stepStore(Request $request, Listing $listing, int $schritt): RedirectResponse
    {
        $this->authorize('update', $listing);
        abort_unless(WizardSteps::istGueltig($schritt), 404);

        match ($schritt) {
            1 => $this->speichereSchritt1($request, $listing),
            2 => $this->speichereSchritt2($request, $listing),
            3 => $this->speichereSchritt3($request, $listing),
            4 => $this->speichereSchritt4($request, $listing),
            6 => $this->speichereSchritt6($request, $listing),
            7 => $this->speichereSchritt7($request, $listing),
            default => null,
        };

        app(ListingChangeTracker::class)->recordChange($listing);

        $weiter = $request->string('aktion')->value() === 'weiter' && $schritt < WizardSteps::LETZTER_SCHRITT;
        $ziel = $weiter ? $schritt + 1 : $schritt;

        return redirect()
            ->route('app.listings.step', ['listing' => $listing, 'schritt' => $ziel])
            ->with('status', 'Schritt "'.WizardSteps::titel($schritt).'" wurde gespeichert.');
    }

    private function speichereSchritt1(Request $request, Listing $listing): void
    {
        app(Step1GrunddatenRequest::class)->validated();

        $listing->update([
            'vermarktungsart' => Vermarktungsart::from($request->string('vermarktungsart')->value()),
            'objektart' => Objektart::from($request->string('objektart')->value()),
            'strasse' => $this->leerAlsNull($request->input('strasse')),
            'hausnummer' => $this->leerAlsNull($request->input('hausnummer')),
            'plz' => $this->leerAlsNull($request->input('plz')),
            'ort' => $this->leerAlsNull($request->input('ort')),
            'land' => $this->leerAlsNull($request->input('land')) ?? 'DE',
            'adresse_im_inserat_anzeigen' => $request->boolean('adresse_im_inserat_anzeigen'),
        ]);
    }

    private function speichereSchritt2(Request $request, Listing $listing): void
    {
        app(Step2FlaechenRequest::class)->validated();

        $listing->update([
            'wohnflaeche_qm' => $this->leerAlsNull($request->input('wohnflaeche_qm')),
            'nutzflaeche_qm' => $this->leerAlsNull($request->input('nutzflaeche_qm')),
            'grundstuecksflaeche_qm' => $this->leerAlsNull($request->input('grundstuecksflaeche_qm')),
            'zimmer' => $this->leerAlsNull($request->input('zimmer')),
            'schlafzimmer' => $this->leerAlsNull($request->input('schlafzimmer')),
            'badezimmer' => $this->leerAlsNull($request->input('badezimmer')),
            'etage' => $this->leerAlsNull($request->input('etage')),
            'etagen_gesamt' => $this->leerAlsNull($request->input('etagen_gesamt')),
            'baujahr' => $this->leerAlsNull($request->input('baujahr')),
            'zustand' => $this->enumOderNull(Zustand::class, $request->input('zustand')),
            'ausstattungsqualitaet' => $this->enumOderNull(Ausstattungsqualitaet::class, $request->input('ausstattungsqualitaet')),
            'heizungsart' => $this->enumOderNull(Heizungsart::class, $request->input('heizungsart')),
            'energietraeger' => $this->enumOderNull(Energietraeger::class, $request->input('energietraeger')),
            'heizkosten_versorgung' => $this->enumOderNull(HeizkostenVersorgung::class, $request->input('heizkosten_versorgung')),
            'verfuegbar_ab_typ' => $this->enumOderNull(VerfuegbarAbTyp::class, $request->input('verfuegbar_ab_typ')),
            'verfuegbar_ab_datum' => $this->leerAlsNull($request->input('verfuegbar_ab_datum')),
            'ausstattung' => $this->ausstattungsWerte($request),
            'stellplatz_typ' => $this->enumOderNull(StellplatzTyp::class, $request->input('stellplatz_typ')),
            'stellplatz_anzahl' => $this->leerAlsNull($request->input('stellplatz_anzahl')),
        ]);
    }

    private function speichereSchritt3(Request $request, Listing $listing): void
    {
        app(Step3EnergieRequest::class)->validated();

        $listing->energy()->updateOrCreate([], [
            'status' => $this->enumOderNull(EnergieausweisStatus::class, $request->input('status')),
            'ausweistyp' => $this->enumOderNull(Ausweistyp::class, $request->input('ausweistyp')),
            'kennwert_kwh' => $this->leerAlsNull($request->input('kennwert_kwh')),
            'effizienzklasse' => $this->enumOderNull(Effizienzklasse::class, $request->input('effizienzklasse')),
            'baujahr_anlage' => $this->leerAlsNull($request->input('baujahr_anlage')),
            'gueltig_bis' => $this->leerAlsNull($request->input('gueltig_bis')),
            'enthaelt_warmwasser' => $request->boolean('enthaelt_warmwasser'),
        ]);
    }

    private function speichereSchritt4(Request $request, Listing $listing): void
    {
        app(Step4PreiseRequest::class)->validated();

        $provisionTyp = ProvisionTyp::from($request->string('provision_typ')->value());
        $provisionText = $request->filled('provision_text') ? $request->string('provision_text')->value() : null;

        if ($listing->istMiete()) {
            $kaltmieteCent = Money::parse((string) $request->input('kaltmiete'));
            $nebenkostenCent = Money::parse((string) $request->input('nebenkosten'));
            $heizkostenCent = $request->filled('heizkosten') ? Money::parse((string) $request->input('heizkosten')) : null;
            $enthalten = $request->boolean('heizkosten_in_nebenkosten_enthalten');
            $versorgung = HeizkostenVersorgung::from($request->string('heizkosten_versorgung')->value());
            $kautionCent = $request->filled('kaution') ? Money::parse((string) $request->input('kaution')) : null;
            $stellplatzMieteCent = $request->filled('stellplatz_miete') ? Money::parse((string) $request->input('stellplatz_miete')) : null;

            try {
                $ergebnis = app(RentCalculator::class)->calculate(
                    kaltmieteCent: (int) $kaltmieteCent,
                    nebenkostenCent: (int) $nebenkostenCent,
                    heizkostenCent: $heizkostenCent,
                    heizkostenInNebenkostenEnthalten: $enthalten,
                    versorgung: $versorgung,
                );
            } catch (InvalidRentInputException $exception) {
                throw ValidationException::withMessages([
                    'heizkosten' => $exception->getMessage(),
                ]);
            }

            $listing->heizkosten_versorgung = $versorgung;
            $listing->save();

            $listing->price()->updateOrCreate([], [
                'kaltmiete_cent' => $kaltmieteCent,
                'nebenkosten_cent' => $nebenkostenCent,
                'heizkosten_cent' => $heizkostenCent,
                'heizkosten_in_nebenkosten_enthalten' => $enthalten,
                'warmmiete_cent' => $ergebnis->warmmieteCent,
                'kaution_cent' => $kautionCent,
                'stellplatz_miete_cent' => $stellplatzMieteCent,
                'kaufpreis_cent' => null,
                'hausgeld_cent' => null,
                'stellplatz_kaufpreis_cent' => null,
                'mieteinnahmen_ist_cent' => null,
                'provision_typ' => $provisionTyp,
                'provision_text' => $provisionText,
            ]);

            return;
        }

        $kaufpreisCent = Money::parse((string) $request->input('kaufpreis'));
        $hausgeldCent = $request->filled('hausgeld') ? Money::parse((string) $request->input('hausgeld')) : null;
        $stellplatzKaufpreisCent = $request->filled('stellplatz_kaufpreis') ? Money::parse((string) $request->input('stellplatz_kaufpreis')) : null;
        $mieteinnahmenCent = $request->filled('mieteinnahmen_ist') ? Money::parse((string) $request->input('mieteinnahmen_ist')) : null;

        $listing->price()->updateOrCreate([], [
            'kaltmiete_cent' => null,
            'nebenkosten_cent' => null,
            'heizkosten_cent' => null,
            'heizkosten_in_nebenkosten_enthalten' => false,
            'warmmiete_cent' => null,
            'kaution_cent' => null,
            'stellplatz_miete_cent' => null,
            'kaufpreis_cent' => $kaufpreisCent,
            'hausgeld_cent' => $hausgeldCent,
            'stellplatz_kaufpreis_cent' => $stellplatzKaufpreisCent,
            'mieteinnahmen_ist_cent' => $mieteinnahmenCent,
            'provision_typ' => $provisionTyp,
            'provision_text' => $provisionText,
        ]);
    }

    private function speichereSchritt6(Request $request, Listing $listing): void
    {
        app(Step6TexteRequest::class)->validated();

        $felderKarte = [
            'titel' => TextFeld::Titel,
            'beschreibung_objekt' => TextFeld::BeschreibungObjekt,
            'beschreibung_ausstattung' => TextFeld::BeschreibungAusstattung,
            'beschreibung_lage' => TextFeld::BeschreibungLage,
            'beschreibung_sonstiges' => TextFeld::BeschreibungSonstiges,
        ];

        $aenderungen = [];

        foreach ($felderKarte as $spalte => $feld) {
            $neuerWert = $this->leerAlsNull($request->input($spalte));
            $bisherigerWert = $listing->{$spalte};

            if ($neuerWert === $bisherigerWert) {
                continue;
            }

            $aenderungen[$spalte] = $neuerWert;

            if ($neuerWert !== null) {
                $listing->texts()->create([
                    'feld' => $feld,
                    'quelle' => TextQuelle::Manuell,
                    'modell' => null,
                    'inhalt' => $neuerWert,
                    'uebernommen' => true,
                    'created_by_user_id' => $request->user()->id,
                ]);
            }
        }

        if ($aenderungen !== []) {
            $listing->update($aenderungen);
        }
    }

    private function speichereSchritt7(Request $request, Listing $listing): void
    {
        app(Step7InternRequest::class)->validated();

        $listing->internal()->updateOrCreate([], [
            'eigentuemer_name' => $this->leerAlsNull($request->input('eigentuemer_name')),
            'eigentuemer_kontakt' => $this->leerAlsNull($request->input('eigentuemer_kontakt')),
            'verwaltungsobjekt_referenz' => $this->leerAlsNull($request->input('verwaltungsobjekt_referenz')),
            'interne_notizen' => $this->leerAlsNull($request->input('interne_notizen')),
            'schluessel_hinweis' => $this->leerAlsNull($request->input('schluessel_hinweis')),
            'besichtigung_intern' => $this->leerAlsNull($request->input('besichtigung_intern')),
            'kalkulation_notiz' => $this->leerAlsNull($request->input('kalkulation_notiz')),
        ]);
    }

    /**
     * @return array{warmmieteCent: int, hinweise: list<string>}|null
     */
    private function warmmieteVorschau(Listing $listing): ?array
    {
        if (! $listing->istMiete()) {
            return null;
        }

        $preis = $listing->price;

        if ($preis === null || $preis->kaltmiete_cent === null || $preis->nebenkosten_cent === null || $listing->heizkosten_versorgung === null) {
            return null;
        }

        try {
            $ergebnis = app(RentCalculator::class)->calculate(
                kaltmieteCent: $preis->kaltmiete_cent,
                nebenkostenCent: $preis->nebenkosten_cent,
                heizkostenCent: $preis->heizkosten_cent,
                heizkostenInNebenkostenEnthalten: $preis->heizkosten_in_nebenkosten_enthalten,
                versorgung: $listing->heizkosten_versorgung,
            );
        } catch (InvalidRentInputException) {
            return null;
        }

        return [
            'warmmieteCent' => $ergebnis->warmmieteCent,
            'hinweise' => array_map(fn ($hinweis) => $hinweis->label(), $ergebnis->hinweise),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function ausstattungsWerte(Request $request): array
    {
        $eingabe = (array) $request->input('ausstattung', []);
        $ergebnis = [];

        foreach (self::AUSSTATTUNGS_SCHLUESSEL as $schluessel) {
            $ergebnis[$schluessel] = filter_var($eingabe[$schluessel] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $ergebnis;
    }

    private function leerAlsNull(mixed $value): mixed
    {
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * @param  class-string<\BackedEnum>  $enumClass
     */
    private function enumOderNull(string $enumClass, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $enumClass::from($value);
    }
}
