<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Enums\GewerbeUnterart;
use App\Enums\Nutzungsstatus;
use App\Enums\Objektart;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Vermarktungsart;
use App\Flowfact\Mapping\FieldCatalog;
use App\Http\Requests\Listing\Step1VermarktungRequest;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Schritt 1: Vermietung oder Verkauf (Masterprompt-Abgleich B.1 Schritt 1).
 */
final class Schritt1Controller extends AbstractStep implements StepHandler
{
    protected function schritt(): int
    {
        return 1;
    }

    public function show(Request $request, Listing $listing): View
    {
        $this->authorizeAndLoad($request, $listing);

        return view('app.listings.schritte.schritt-1', array_merge($this->headerDaten($listing), [
            'benutzer' => Step1VermarktungRequest::auswaehlbareBenutzer(),
            'objektartHinweis' => $this->objektartHinweis($listing->objektart),
        ]));
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        /** @var Step1VermarktungRequest $validiert */
        $validiert = app(Step1VermarktungRequest::class);
        $daten = $validiert->validated();

        $this->saveWithTracking($listing, function (Listing $listing) use ($daten): void {
            $this->anwenden($listing, $daten);
        });

        return $this->redirectNachSpeichern($request, $listing);
    }

    public function autosave(Request $request, Listing $listing): JsonResponse
    {
        try {
            $daten = Validator::make($request->all(), Step1VermarktungRequest::autosaveRegeln())->validate();
        } catch (ValidationException $exception) {
            return $this->autosaveFehler($exception);
        }

        $this->saveWithTracking($listing, function (Listing $listing) use ($daten): void {
            $this->anwenden($listing, $daten, autosave: true);
        });

        return $this->autosaveErfolg($listing);
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function anwenden(Listing $listing, array $daten, bool $autosave = false): void
    {
        $aenderungen = [];

        if (array_key_exists('vermarktungsart', $daten) && $daten['vermarktungsart'] !== null) {
            $aenderungen['vermarktungsart'] = Vermarktungsart::from($daten['vermarktungsart']);
        }

        if (array_key_exists('objektart', $daten) && $daten['objektart'] !== null) {
            $objektart = Objektart::from($daten['objektart']);
            $aenderungen['objektart'] = $objektart;
            $aenderungen['gewerbe_unterart'] = $objektart === Objektart::Gewerbe
                ? $this->enumOderNull(GewerbeUnterart::class, $daten['gewerbe_unterart'] ?? null)
                : null;
        } elseif (array_key_exists('gewerbe_unterart', $daten)) {
            $aenderungen['gewerbe_unterart'] = $this->enumOderNull(GewerbeUnterart::class, $daten['gewerbe_unterart']);
        }

        if (array_key_exists('bearbeiter_user_id', $daten)) {
            $aenderungen['bearbeiter_user_id'] = $this->leerAlsNull($daten['bearbeiter_user_id']);
        } elseif (! $autosave && $listing->bearbeiter_user_id === null) {
            $aenderungen['bearbeiter_user_id'] = $listing->erstellt_von_user_id;
        }

        if (array_key_exists('ansprechpartner_user_id', $daten)) {
            $aenderungen['ansprechpartner_user_id'] = $this->leerAlsNull($daten['ansprechpartner_user_id'])
                ?? ($aenderungen['bearbeiter_user_id'] ?? $listing->bearbeiter_user_id);
        }

        if (array_key_exists('verfuegbar_ab_typ', $daten)) {
            $typ = $this->enumOderNull(VerfuegbarAbTyp::class, $daten['verfuegbar_ab_typ']);
            $aenderungen['verfuegbar_ab_typ'] = $typ;
            $aenderungen['verfuegbar_ab_datum'] = $typ === VerfuegbarAbTyp::Datum
                ? $this->leerAlsNull($daten['verfuegbar_ab_datum'] ?? null)
                : null;
        }

        if (array_key_exists('nutzungsstatus', $daten) && $daten['nutzungsstatus'] !== null) {
            $aenderungen['nutzungsstatus'] = Nutzungsstatus::from($daten['nutzungsstatus']);
        }

        if ($aenderungen !== []) {
            $listing->update($aenderungen);
        }
    }

    /**
     * Objektarten ohne bestätigten FLOWFACT-Code werden zwar lokal erfasst,
     * die Übertragung ist aber noch nicht möglich (Masterprompt-Abgleich B.1
     * Schritt 1, app/Flowfact/Mapping/FieldCatalog).
     */
    private function objektartHinweis(Objektart $objektart): ?string
    {
        $codes = FieldCatalog::CODES['objektart'] ?? [];

        if (($codes[$objektart->value] ?? null) !== null) {
            return null;
        }

        return 'Diese Objektart wird lokal erfasst, die Übertragung an FLOWFACT ist noch nicht freigegeben.';
    }
}
