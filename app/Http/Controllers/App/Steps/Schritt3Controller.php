<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Enums\Zustand;
use App\Http\Requests\Listing\Step3FlaechenRequest;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Schritt 3: Flächen und Objektdaten (Masterprompt-Abgleich B.1 Schritt 3).
 * Zeigt nur die zur Objektart passenden Feldgruppen (Objektart::benoetigt()).
 */
final class Schritt3Controller extends AbstractStep implements StepHandler
{
    /**
     * @var list<string>
     */
    private const array DEZIMALFELDER = ['wohnflaeche_qm', 'nutzflaeche_qm', 'gewerbeflaeche_qm', 'grundstuecksflaeche_qm', 'zimmer'];

    /**
     * @var list<string>
     */
    private const array GANZZAHLFELDER = ['schlafzimmer', 'badezimmer', 'etage', 'etagen_gesamt', 'baujahr', 'modernisierungsjahr'];

    protected function schritt(): int
    {
        return 3;
    }

    public function show(Request $request, Listing $listing): View
    {
        $this->authorizeAndLoad($request, $listing);

        return view('app.listings.schritte.schritt-3', $this->headerDaten($listing));
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        /** @var Step3FlaechenRequest $validiert */
        $validiert = app(Step3FlaechenRequest::class);
        $daten = $validiert->validated();

        $this->saveWithTracking($listing, function (Listing $listing) use ($daten): void {
            $this->anwenden($listing, $daten);
        });

        return $this->redirectNachSpeichern($request, $listing);
    }

    public function autosave(Request $request, Listing $listing): JsonResponse
    {
        try {
            $daten = Validator::make($request->all(), Step3FlaechenRequest::autosaveRegeln())->validate();
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
        $aenderungen = [];

        foreach (self::DEZIMALFELDER as $feld) {
            if (array_key_exists($feld, $daten)) {
                $aenderungen[$feld] = Step3FlaechenRequest::parseDezimal($this->leerAlsNull($daten[$feld]));
            }
        }

        foreach (self::GANZZAHLFELDER as $feld) {
            if (array_key_exists($feld, $daten)) {
                $aenderungen[$feld] = $this->leerAlsNull($daten[$feld]);
            }
        }

        if (array_key_exists('zustand', $daten)) {
            $aenderungen['zustand'] = $this->enumOderNull(Zustand::class, $daten['zustand']);
        }

        if ($aenderungen !== []) {
            $listing->update($aenderungen);
        }
    }
}
