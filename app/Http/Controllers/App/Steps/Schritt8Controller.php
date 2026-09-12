<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use App\Http\Controllers\App\Support\ListingPreviewBuilder;
use App\Http\Requests\Listing\Step8BeschreibungenRequest;
use App\Models\Listing;
use App\Models\ListingText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Schritt 8: Beschreibungen (Masterprompt-Abgleich B.1 Schritt 8, Masterprompt
 * Abschnitt 16).
 *
 * Manuelle Änderungen werden nie durch eine Texterzeugung überschrieben:
 * Vorschläge sind eigene ListingText-Zeilen (quelle ki, uebernommen false),
 * erst eine ausdrückliche Übernahme (ListingTextController::accept) ändert
 * das Feld. Ein Feld gilt als "prüfbedürftig", wenn der aktuelle Inhalts-Hash
 * des Objekts vom Datenbasis-Hash des zuletzt übernommenen Textes abweicht.
 */
final class Schritt8Controller extends AbstractStep implements StepHandler
{
    /**
     * @var array<string, TextFeld>
     */
    private const array FELDER = [
        'beschreibung_objekt' => TextFeld::BeschreibungObjekt,
        'beschreibung_ausstattung' => TextFeld::BeschreibungAusstattung,
        'beschreibung_lage' => TextFeld::BeschreibungLage,
        'beschreibung_sonstiges' => TextFeld::BeschreibungSonstiges,
    ];

    protected function schritt(): int
    {
        return 8;
    }

    public function show(Request $request, Listing $listing): View
    {
        $this->authorizeAndLoad($request, $listing);
        $listing->loadMissing('texts');

        return view('app.listings.schritte.schritt-8', array_merge($this->headerDaten($listing), [
            'textvorschlaege' => $this->offeneVorschlaege($listing),
            'pruefbeduerftig' => $this->pruefbeduerftigJeFeld($listing),
            'adressWarnung' => $this->adressWarnungen($listing),
        ]));
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        /** @var Step8BeschreibungenRequest $validiert */
        $validiert = app(Step8BeschreibungenRequest::class);
        $daten = $validiert->validated();

        $this->saveWithTracking($listing, function (Listing $listing) use ($request, $daten): void {
            $this->speichereFelder($request, $listing, $daten, historieSchreiben: true);
        });

        return $this->redirectNachSpeichern($request, $listing);
    }

    public function autosave(Request $request, Listing $listing): JsonResponse
    {
        try {
            $daten = Validator::make($request->all(), [
                'beschreibung_objekt' => ['sometimes', 'nullable', 'string'],
                'beschreibung_ausstattung' => ['sometimes', 'nullable', 'string'],
                'beschreibung_lage' => ['sometimes', 'nullable', 'string'],
                'beschreibung_sonstiges' => ['sometimes', 'nullable', 'string'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->autosaveFehler($exception);
        }

        // Prüfbericht 2026-09-12, Befund 6: der Autosave darf keine
        // listing_texts-Zeile anlegen, sonst wächst die Textversionierung
        // mit jeder Eingabepause. Nur "Weiter" oder "Entwurf speichern"
        // (store()) schreiben die Historie.
        $this->saveWithTracking($listing, function (Listing $listing) use ($request, $daten): void {
            $this->speichereFelder($request, $listing, $daten, historieSchreiben: false);
        });

        return $this->autosaveErfolg($listing);
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function speichereFelder(Request $request, Listing $listing, array $daten, bool $historieSchreiben): void
    {
        $aenderungen = [];
        $geaenderteSpalten = [];

        foreach (self::FELDER as $spalte => $feld) {
            if (! array_key_exists($spalte, $daten)) {
                continue;
            }

            $neuerWert = $this->leerAlsNull($daten[$spalte]);

            if ($neuerWert === $listing->{$spalte}) {
                continue;
            }

            $aenderungen[$spalte] = $neuerWert;
            $geaenderteSpalten[] = $spalte;
        }

        if ($aenderungen === []) {
            return;
        }

        $listing->update($aenderungen);

        if (! $historieSchreiben) {
            return;
        }

        foreach ($geaenderteSpalten as $spalte) {
            $wert = $listing->{$spalte};

            if ($wert === null) {
                continue;
            }

            $listing->texts()->create([
                'feld' => self::FELDER[$spalte],
                'quelle' => TextQuelle::Manuell,
                'modell' => null,
                'inhalt' => $wert,
                'uebernommen' => true,
                'datenbasis_hash' => app(ListingContentHasher::class)->hash($listing),
                'created_by_user_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Noch nicht übernommene Textvorschläge (quelle ki), je Feld die neuesten
     * zuerst, für die Vorschlagskarten.
     *
     * @return Collection<string, Collection<int, ListingText>>
     */
    private function offeneVorschlaege(Listing $listing): Collection
    {
        return $listing->texts
            ->where('quelle', TextQuelle::Ki)
            ->where('uebernommen', false)
            ->sortByDesc('created_at')
            ->groupBy(fn (ListingText $text): string => $text->feld->value);
    }

    /**
     * @return array<string, bool>
     */
    private function pruefbeduerftigJeFeld(Listing $listing): array
    {
        $aktuellerHash = app(ListingContentHasher::class)->hash($listing);
        $ergebnis = [];

        foreach (self::FELDER as $spalte => $feld) {
            $letzterText = $listing->texts
                ->where('feld', $feld)
                ->where('uebernommen', true)
                ->sortByDesc('id')
                ->first();

            $ergebnis[$spalte] = $letzterText !== null
                && $letzterText->datenbasis_hash !== null
                && $letzterText->datenbasis_hash !== $aktuellerHash;
        }

        return $ergebnis;
    }

    /**
     * Blockierender Hinweis, wenn ein Text bei "nur PLZ und Ort" dennoch
     * Straße oder Hausnummer enthält (Masterprompt-Abgleich B.7).
     *
     * @return array<string, bool>
     */
    private function adressWarnungen(Listing $listing): array
    {
        $ergebnis = [];

        foreach (array_keys(self::FELDER) as $spalte) {
            $ergebnis[$spalte] = ListingPreviewBuilder::enthaeltAdresse($listing, $listing->{$spalte});
        }

        return $ergebnis;
    }
}
