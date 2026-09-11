<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Listing\ListingChangeTracker;
use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\TextGenerateRequest;
use App\Models\Listing;
use App\Models\ListingText;
use App\Services\Ai\TextGenerationException;
use App\Services\Ai\TextGenerator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Textvorschläge und deren Übernahme in Schritt 6 (Datenvertrag Abschnitt
 * 2.7, ADR-009).
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

        return back()->with(
            'status',
            'Es wurden '.count($vorschlaege).' Textvorschläge erzeugt (Modell: '.$generator->modell().').'
        );
    }

    public function accept(Request $request, Listing $listing, ListingText $text): RedirectResponse
    {
        $this->authorize('update', $listing);
        abort_unless($text->listing_id === $listing->id, 404);

        $listing->update([$text->feld->value => $text->inhalt]);
        $text->update(['uebernommen' => true]);

        app(ListingChangeTracker::class)->recordChange($listing);

        return back()->with('status', 'Der Text wurde übernommen.');
    }
}
