<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use App\Http\Controllers\App\Support\InternalNamePattern;
use App\Http\Controllers\App\Support\TitleSuggestions;
use App\Http\Requests\Listing\Step7TitelRequest;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Schritt 7: Überschrift und interne Bezeichnung (Masterprompt-Abgleich B.1
 * Schritt 7, Masterprompt Abschnitt 15).
 *
 * Die technische Referenz (Objektnummer, UUID) ist nur lesbar und ändert sich
 * nie. Die Überschrift wird zusätzlich als ListingText (quelle manuell)
 * gespeichert, wenn sie sich ändert, damit die Historie der Texte
 * (Datenvertrag Abschnitt 2.7) auch die Überschrift umfasst.
 */
final class Schritt7Controller extends AbstractStep implements StepHandler
{
    protected function schritt(): int
    {
        return 7;
    }

    public function show(Request $request, Listing $listing): View
    {
        $this->authorizeAndLoad($request, $listing);

        return view('app.listings.schritte.schritt-7', array_merge($this->headerDaten($listing), [
            'titelVorschlaege' => TitleSuggestions::fuer($listing),
            'interneBezeichnungVorschlag' => app(InternalNamePattern::class)->vorschlag($listing),
        ]));
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        /** @var Step7TitelRequest $validiert */
        $validiert = app(Step7TitelRequest::class);
        $daten = $validiert->validated();

        $this->saveWithTracking($listing, function (Listing $listing) use ($request, $daten): void {
            $this->anwenden($request, $listing, $daten, historieSchreiben: true);
        });

        return $this->redirectNachSpeichern($request, $listing);
    }

    public function autosave(Request $request, Listing $listing): JsonResponse
    {
        try {
            $daten = Validator::make($request->all(), [
                'titel' => ['sometimes', 'nullable', 'string', 'max:100'],
                'interne_bezeichnung' => ['sometimes', 'nullable', 'string', 'max:255'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->autosaveFehler($exception);
        }

        // Prüfbericht 2026-09-12, Befund 6: der Autosave darf keine
        // listing_texts-Zeile anlegen, sonst wächst die Textversionierung
        // mit jeder Eingabepause. Nur "Weiter" oder "Entwurf speichern"
        // (store()) schreiben die Historie.
        $this->saveWithTracking($listing, function (Listing $listing) use ($request, $daten): void {
            $this->anwenden($request, $listing, $daten, historieSchreiben: false);
        });

        return $this->autosaveErfolg($listing);
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function anwenden(Request $request, Listing $listing, array $daten, bool $historieSchreiben): void
    {
        $titelAlt = $listing->titel;
        $aenderungen = [];

        if (array_key_exists('titel', $daten)) {
            $aenderungen['titel'] = $this->leerAlsNull($daten['titel']);
        }

        if (array_key_exists('interne_bezeichnung', $daten)) {
            $aenderungen['interne_bezeichnung'] = $this->leerAlsNull($daten['interne_bezeichnung']);
        }

        if ($aenderungen !== []) {
            $listing->update($aenderungen);
        }

        if ($historieSchreiben && $listing->titel !== null && $listing->titel !== $titelAlt) {
            $listing->texts()->create([
                'feld' => TextFeld::Titel,
                'quelle' => TextQuelle::Manuell,
                'modell' => null,
                'inhalt' => $listing->titel,
                'uebernommen' => true,
                'datenbasis_hash' => app(ListingContentHasher::class)->hash($listing),
                'created_by_user_id' => $request->user()->id,
            ]);
        }
    }
}
