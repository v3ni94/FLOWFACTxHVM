<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Listing\ListingChangeTracker;
use App\Domain\Listing\ListingContentHasher;
use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\TextGenerateRequest;
use App\Models\Listing;
use App\Models\ListingText;
use App\Services\Ai\TextGenerationException;
use App\Services\Ai\TextGenerator;
use App\Services\Ai\TextReviser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Textvorschläge, Übernahme und gezielte Überarbeitung (Datenvertrag
 * Abschnitt 2.7, Masterprompt Abschnitt 16, Masterprompt-Abgleich B.1
 * Schritte 7 und 8).
 *
 * Ein Vorschlag ist immer eine eigene ListingText-Zeile. Kein Vorschlag
 * überschreibt jemals das aktuelle Feld von sich aus; nur accept() übernimmt
 * einen Vorschlag ausdrücklich in das Objekt.
 */
class ListingTextController extends Controller
{
    use AuthorizesRequests;

    /**
     * @var array<string, TextFeld>
     */
    private const array FELDER = [
        'titel' => TextFeld::Titel,
        'beschreibung_objekt' => TextFeld::BeschreibungObjekt,
        'beschreibung_ausstattung' => TextFeld::BeschreibungAusstattung,
        'beschreibung_lage' => TextFeld::BeschreibungLage,
        'beschreibung_sonstiges' => TextFeld::BeschreibungSonstiges,
    ];

    /**
     * Spaltenlängen der Zielfelder (Prüfbericht 2026-09-12, Befund 12):
     * "titel" ist string(100); ein ungeprüft übernommener oder überarbeiteter
     * Vorschlag speichert unter SQLite unbemerkt, unter MariaDB im strict
     * mode antwortet die Datenbank mit einem SQL-Fehler und 500.
     *
     * @var array<string, int>
     */
    private const array FELDGRENZEN = [
        'titel' => 100,
    ];

    public function generate(TextGenerateRequest $request, Listing $listing, TextGenerator $generator): RedirectResponse
    {
        $ausgewaehlt = (array) $request->input('felder', []);

        if ($ausgewaehlt === []) {
            $felder = [];

            foreach (self::FELDER as $spalte => $feld) {
                if (empty($listing->{$spalte})) {
                    $felder[] = $feld;
                }
            }
        } else {
            $felder = array_map(fn (string $wert): TextFeld => TextFeld::from($wert), $ausgewaehlt);
        }

        if ($felder === []) {
            return back()->with('status', 'Es sind keine leeren Felder für einen Textvorschlag vorhanden.');
        }

        try {
            $vorschlaege = $generator->generate($listing, $felder);
        } catch (TextGenerationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        foreach ($vorschlaege as $feldWert => $inhalt) {
            $listing->texts()->create([
                'feld' => TextFeld::from($feldWert),
                'quelle' => TextQuelle::Ki,
                'modell' => $generator->modell(),
                'inhalt' => $inhalt,
                'uebernommen' => false,
                'created_by_user_id' => $request->user()->id,
            ]);
        }

        $hinweisOhneKi = $generator->modell() === 'vorlage' ? ' Es handelt sich um Vorlagenentwürfe ohne KI.' : '';

        return back()->with(
            'status',
            'Es wurden '.count($vorschlaege).' Textvorschläge erzeugt (Modell: '.$generator->modell().').'.$hinweisOhneKi
        );
    }

    public function accept(Request $request, Listing $listing, ListingText $text): RedirectResponse
    {
        $this->authorize('update', $listing);
        abort_unless($text->listing_id === $listing->id, 404);

        $grenze = self::FELDGRENZEN[$text->feld->value] ?? null;

        if ($grenze !== null && mb_strlen((string) $text->inhalt) > $grenze) {
            return back()->with('error', sprintf('Der Vorschlag ist mit %d Zeichen zu lang für das Feld (maximal %d Zeichen erlaubt) und wurde nicht übernommen.', mb_strlen((string) $text->inhalt), $grenze));
        }

        $vorherHash = app(ListingContentHasher::class)->hash($listing);

        $listing->update([$text->feld->value => $text->inhalt]);

        $text->update([
            'uebernommen' => true,
            'datenbasis_hash' => app(ListingContentHasher::class)->hash($listing),
        ]);

        app(ListingChangeTracker::class)->recordChange($listing, $vorherHash);

        return back()->with('status', 'Der Text wurde übernommen.');
    }

    /**
     * Gezielte Überarbeitung eines vorhandenen Textes (Masterprompt Abschnitt
     * 16): kürzer, sachlicher, sprachlich verbessern. Das Ergebnis ist eine
     * neue Vorschlagszeile (quelle ki), das aktuelle Feld bleibt unverändert,
     * bis der Benutzer den Vorschlag ausdrücklich übernimmt.
     */
    public function revise(Request $request, Listing $listing, TextReviser $reviser): RedirectResponse
    {
        $this->authorize('update', $listing);

        // Prüfbericht 2026-09-12, Befund 12: für den Titel gilt dieselbe
        // Spaltengrenze wie bei der Übernahme (string(100)); eine zu lange
        // Vorlage wird erst gar nicht zur Überarbeitung angenommen.
        $titelGrenze = self::FELDGRENZEN['titel'];

        $validator = Validator::make($request->all(), [
            'feld' => ['required', Rule::enum(TextFeld::class)],
            'text' => [
                'required',
                'string',
                Rule::when(fn (): bool => $request->input('feld') === TextFeld::Titel->value, ['max:'.$titelGrenze]),
            ],
            'anweisung' => ['required', Rule::in([
                TextReviser::KUERZER, TextReviser::SACHLICHER, TextReviser::SPRACHLICH,
            ])],
        ], [
            'text.max' => 'Der Titel darf höchstens '.$titelGrenze.' Zeichen lang sein.',
        ]);

        $validator->validate();

        /** @var TextFeld $feld */
        $feld = TextFeld::from($request->string('feld')->value());
        $anweisung = $request->string('anweisung')->value();
        $text = $request->string('text')->value();

        try {
            $ueberarbeitet = $reviser->revise($listing, $text, $anweisung);
        } catch (TextGenerationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $grenze = self::FELDGRENZEN[$feld->value] ?? null;

        if ($grenze !== null && mb_strlen($ueberarbeitet) > $grenze) {
            return back()->with('error', sprintf('Die Überarbeitung ist mit %d Zeichen zu lang für das Feld (maximal %d Zeichen erlaubt) und wurde verworfen.', mb_strlen($ueberarbeitet), $grenze));
        }

        $listing->texts()->create([
            'feld' => $feld,
            'quelle' => TextQuelle::Ki,
            'modell' => $reviser->modell(),
            'inhalt' => $ueberarbeitet,
            'uebernommen' => false,
            'anweisung' => $anweisung,
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Die Überarbeitung wurde als Vorschlag gespeichert.');
    }
}
