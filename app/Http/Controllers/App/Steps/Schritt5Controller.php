<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Domain\Listing\EnergyRequirements;
use App\Domain\Listing\Merkmale;
use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use App\Enums\MerkmalWert;
use App\Enums\StellplatzTyp;
use App\Http\Requests\Listing\Step5AusstattungRequest;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Schritt 5: Ausstattung und Energieausweis (Masterprompt-Abgleich B.1
 * Schritt 5, B.5). Für Grundstücke und Stellplätze entfällt der
 * Energieausweisabschnitt (Objektart::benoetigt('energieausweis')).
 */
final class Schritt5Controller extends AbstractStep implements StepHandler
{
    protected function schritt(): int
    {
        return 5;
    }

    public function show(Request $request, Listing $listing): View
    {
        $this->authorizeAndLoad($request, $listing);
        $listing->loadMissing('energy', 'price');

        return view('app.listings.schritte.schritt-5', array_merge($this->headerDaten($listing), [
            'merkmalSchluessel' => Merkmale::fuerObjektart($listing->objektart),
            'energieausweisRelevant' => $listing->objektart->benoetigt('energieausweis'),
            'energieregeln' => EnergyRequirements::regeln(),
        ]));
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        /** @var Step5AusstattungRequest $validiert */
        $validiert = app(Step5AusstattungRequest::class);
        $daten = $validiert->validated();

        $this->saveWithTracking($listing, function (Listing $listing) use ($daten): void {
            $this->anwenden($listing, $daten);
        });

        return $this->redirectNachSpeichern($request, $listing);
    }

    public function autosave(Request $request, Listing $listing): JsonResponse
    {
        try {
            $daten = Validator::make($request->all(), Step5AusstattungRequest::autosaveRegeln())->validate();
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
        $ausstattung = is_array($listing->ausstattung) ? $listing->ausstattung : [];

        foreach (Merkmale::schluessel() as $schluessel) {
            $feld = 'merkmal_'.$schluessel;

            if (array_key_exists($feld, $daten) && $daten[$feld] !== null) {
                $ausstattung[$schluessel] = MerkmalWert::from($daten[$feld])->value;
            }
        }

        $aenderungen = ['ausstattung' => $ausstattung];

        if (array_key_exists('einbaukueche_mitvermietet', $daten)) {
            $aenderungen['einbaukueche_mitvermietet'] = $listing->istMiete()
                ? filter_var($daten['einbaukueche_mitvermietet'], FILTER_VALIDATE_BOOLEAN)
                : null;
        }

        if (array_key_exists('stellplatz_typ', $daten)) {
            $aenderungen['stellplatz_typ'] = $this->enumOderNull(StellplatzTyp::class, $daten['stellplatz_typ']);
        }

        if (array_key_exists('stellplatz_anzahl', $daten)) {
            $aenderungen['stellplatz_anzahl'] = $this->leerAlsNull($daten['stellplatz_anzahl']);
        }

        $listing->update($aenderungen);

        if (! $listing->objektart->benoetigt('energieausweis')) {
            return;
        }

        $status = $this->enumOderNull(EnergieausweisStatus::class, $daten['energieausweis_status'] ?? null);
        $vorhanden = $status?->normalisiert() === EnergieausweisStatus::Vorhanden;

        $energieAenderungen = [];

        if (array_key_exists('energieausweis_status', $daten)) {
            $energieAenderungen['status'] = $status;
        }

        foreach (['ausweistyp' => Ausweistyp::class, 'effizienzklasse' => Effizienzklasse::class] as $feld => $enum) {
            if (array_key_exists($feld, $daten)) {
                $energieAenderungen[$feld] = $vorhanden ? $this->enumOderNull($enum, $daten[$feld]) : null;
            }
        }

        foreach (['ausstellungsdatum', 'gueltig_bis', 'kennwert_kwh', 'kennwert_strom_kwh', 'baujahr_anlage'] as $feld) {
            if (array_key_exists($feld, $daten)) {
                $energieAenderungen[$feld] = $vorhanden ? $this->leerAlsNull($daten[$feld]) : null;
            }
        }

        if (array_key_exists('enthaelt_warmwasser', $daten)) {
            $energieAenderungen['enthaelt_warmwasser'] = filter_var($daten['enthaelt_warmwasser'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('ausnahme_begruendung', $daten)) {
            $energieAenderungen['ausnahme_begruendung'] = $status === EnergieausweisStatus::AusnahmeZuPruefen
                ? $this->leerAlsNull($daten['ausnahme_begruendung'])
                : null;
        }

        if ($energieAenderungen !== []) {
            $listing->energy()->updateOrCreate([], $energieAenderungen);
        }
    }
}
