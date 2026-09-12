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

        $hinweisOhneKi = $generator->modell() === 'fake' ? ' Es handelt sich um Vorlagenentwürfe ohne KI.' : '';

        return back()->with(
            'status',
            'Es wurden '.count($vorschlaege).' Textvorschläge erzeugt (Modell: '.$generator->modell().').'.$hinweisOhneKi
        );
    }

    public function accept(Request $request, Listing $listing, ListingText $text): RedirectResponse
    {
        $this->authorize('update', $listing);
        abort_unless($text->listing_id === $listing->id, 404);

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

        $validator = Validator::make($request->all(), [
            'feld' => ['required', Rule::enum(TextFeld::class)],
            'text' => ['required', 'string'],
            'anweisung' => ['required', Rule::in([
                TextReviser::KUERZER, TextReviser::SACHLICHER, TextReviser::SPRACHLICH,
            ])],
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
