<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\CompletenessResult;
use App\Domain\Listing\ListingChangeTracker;
use App\Domain\Listing\ListingContentHasher;
use App\Http\Controllers\App\Support\WizardSteps;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gemeinsame Hilfen für die Schritt-Controller des Erfassungsassistenten
 * (Masterprompt-Abgleich B.1). Jeder Schritt{n}Controller erweitert diese
 * Klasse und implementiert StepHandler mit den drei Methoden show, store,
 * autosave.
 *
 * Die Berechtigungsprüfung selbst erfolgt zentral im StepDispatcher (view
 * für GET, update für POST/PATCH). authorizeAndLoad lädt hier nur die für
 * fast jeden Schritt benötigten Relationen nach, ohne sie doppelt zu laden.
 */
abstract class AbstractStep
{
    /**
     * Schrittnummer dieses Controllers (1 bis 8, Masterprompt-Abgleich B.1).
     */
    abstract protected function schritt(): int;

    /**
     * Lädt die Relationen nach, die praktisch jeder Schritt für die
     * Kopfzeile (Vollständigkeit) und die eigene Anzeige braucht.
     */
    protected function authorizeAndLoad(Request $request, Listing $listing): Listing
    {
        $listing->loadMissing(['price', 'energy', 'internal', 'media', 'ansprechpartner', 'bearbeiter']);

        return $listing;
    }

    /**
     * Führt $aendern aus und zeichnet die Änderung über ListingChangeTracker
     * auf. Der Inhalts-Hash wird vor der Änderung ermittelt, damit rein
     * interne Änderungen (z. B. listing_internals) den Übertragungsstatus
     * nie kippen (Prüfbericht 2026-09-11, Befund 12).
     *
     * @param  callable(Listing): void  $aendern
     */
    protected function saveWithTracking(Listing $listing, callable $aendern): void
    {
        $listing->loadMissing(['price', 'energy', 'media', 'flowfactLink']);
        $vorherHash = app(ListingContentHasher::class)->hash($listing);

        $aendern($listing);

        app(ListingChangeTracker::class)->recordChange($listing, $vorherHash);
    }

    protected function vollstaendigkeit(Listing $listing): CompletenessResult
    {
        return app(CompletenessCheck::class)->check($listing);
    }

    /**
     * Gemeinsame Daten für die Kopfzeile jedes Schrittformulars
     * (resources/views/app/listings/schritte/_header.blade.php).
     *
     * @return array<string, mixed>
     */
    protected function headerDaten(Listing $listing): array
    {
        return [
            'listing' => $listing,
            'schritt' => $this->schritt(),
            'vollstaendigkeit' => $this->vollstaendigkeit($listing),
        ];
    }

    /**
     * Leitet nach dem Speichern weiter: "weiter" zum nächsten Schritt (beim
     * letzten Schritt zur Prüfen-Seite), "zurueck" zum vorherigen Schritt,
     * sonst bleibt der Benutzer im selben Schritt ("Entwurf speichern").
     */
    protected function redirectNachSpeichern(Request $request, Listing $listing): RedirectResponse
    {
        $schritt = $this->schritt();
        $aktion = $request->string('aktion')->value();

        if ($aktion === 'weiter') {
            if ($schritt >= WizardSteps::LETZTER_SCHRITT) {
                return redirect()
                    ->route('app.listings.review', ['listing' => $listing])
                    ->with('status', 'Schritt "'.WizardSteps::titel($schritt).'" wurde gespeichert.');
            }

            return redirect()
                ->route('app.listings.step', ['listing' => $listing, 'schritt' => $schritt + 1])
                ->with('status', 'Schritt "'.WizardSteps::titel($schritt).'" wurde gespeichert.');
        }

        if ($aktion === 'zurueck' && $schritt > WizardSteps::ERSTER_SCHRITT) {
            return redirect()
                ->route('app.listings.step', ['listing' => $listing, 'schritt' => $schritt - 1])
                ->with('status', 'Schritt "'.WizardSteps::titel($schritt).'" wurde gespeichert.');
        }

        return redirect()
            ->route('app.listings.step', ['listing' => $listing, 'schritt' => $schritt])
            ->with('status', 'Entwurf wurde gespeichert.');
    }

    /**
     * Baut die JSON-Antwort für einen erfolgreichen Autosave
     * (App\Http\Controllers\App\Steps\StepHandler::autosave).
     */
    protected function autosaveErfolg(Listing $listing): JsonResponse
    {
        $ergebnis = $this->vollstaendigkeit($listing);

        return response()->json([
            'ok' => true,
            'gespeichert_at' => now()->format('H:i:s'),
            'fehlend' => array_values($ergebnis->fehlend),
            'hinweise' => $ergebnis->hinweise,
        ]);
    }

    /**
     * Baut die JSON-Antwort für einen fehlgeschlagenen Autosave (HTTP 422).
     */
    protected function autosaveFehler(ValidationException $exception): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'errors' => $exception->errors(),
        ], 422);
    }

    /**
     * Leere Zeichenketten gelten als "unbekannt" (null), nie als 0
     * (Masterprompt-Abgleich B.1 Schritt 3).
     */
    protected function leerAlsNull(mixed $value): mixed
    {
        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * @param  class-string<\BackedEnum>  $enumClass
     */
    protected function enumOderNull(string $enumClass, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $enumClass::from($value);
    }
}
