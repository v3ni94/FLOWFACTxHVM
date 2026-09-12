<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\IllegalStatusTransitionException;
use App\Domain\Listing\ListingStatusMachine;
use App\Domain\Listing\ReleaseService;
use App\Domain\Settings\SettingsRepository;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\ReleaseAktion;
use App\Flowfact\Sync\PublishingService;
use App\Http\Controllers\App\Support\ListingPreviewBuilder;
use App\Http\Controllers\App\Support\PortalSummary;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\ReviewEnergyExceptionRequest;
use App\Http\Requests\Listing\ReviewPublishRequest;
use App\Http\Requests\Listing\ReviewWithdrawRequest;
use App\Models\Listing;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Prüfen und veröffentlichen (Masterprompt Abschnitt 17 bis 21, 24;
 * Masterprompt-Abgleich B.4, B.6).
 *
 * Die Seite selbst ist die einzige Bestätigung vor der Veröffentlichung: es
 * gibt keinen weiteren Dialog. Übertragung und Veröffentlichung frieren den
 * Inhalt zuerst als Freigabeversion ein (App\Domain\Listing\ReleaseService),
 * bevor der Connector aufgerufen wird.
 */
class ReviewController extends Controller
{
    use AuthorizesRequests;

    public function review(Listing $listing): View
    {
        $this->authorize('view', $listing);

        $listing->load([
            'price', 'energy', 'media', 'texts', 'ansprechpartner', 'bearbeiter',
            'portalPublications', 'flowfactLink', 'latestRelease',
            'portalStatusLogs' => fn ($query) => $query->take(10),
        ]);

        $vollstaendigkeit = app(CompletenessCheck::class)->check($listing);
        $befunde = app(CompletenessCheck::class)->befunde($listing);
        $publishingService = app(PublishingService::class);
        $settings = app(SettingsRepository::class);

        $portaleKonfiguriert = $publishingService->isConfigured();
        $portale = $portaleKonfiguriert ? $publishingService->portals() : [];

        $vorauswahlSchluessel = $listing->istMiete() ? 'defaults.portale_miete' : 'defaults.portale_kauf';
        $vorausgewaehlt = (array) $settings->get($vorauswahlSchluessel, []);

        return view('app.listings.pruefen', [
            'listing' => $listing,
            'vollstaendigkeit' => $vollstaendigkeit,
            'befunde' => collect($befunde)->groupBy(fn ($befund) => $befund->ebene->value),
            'vorschau' => app(ListingPreviewBuilder::class)->build($listing),
            'adressLeck' => $this->adressLeck($listing),
            'portaleKonfiguriert' => $portaleKonfiguriert,
            'portale' => $portale,
            'vorausgewaehltePortale' => $vorausgewaehlt,
            'aktuellePublikationen' => $listing->portalPublications,
            'portalBadge' => PortalSummary::badgeClass($listing),
            'portalText' => PortalSummary::text($listing),
        ]);
    }

    public function markiereBereit(Listing $listing): RedirectResponse
    {
        $this->authorize('update', $listing);

        try {
            app(ListingStatusMachine::class)->transition($listing, ListingStatus::Bereit);
        } catch (IllegalStatusTransitionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Das Objekt wurde als bereit markiert.');
    }

    public function transfer(Request $request, Listing $listing, PublishingService $publishingService): RedirectResponse
    {
        $this->authorize('update', $listing);

        if (app(CompletenessCheck::class)->blockiert($listing)) {
            return back()->with('error', 'Das Objekt kann nicht in FLOWFACT gespeichert werden, solange blockierende Prüfpunkte offen sind.');
        }

        app(ReleaseService::class)->freigeben($listing, $request->user(), ReleaseAktion::FlowfactSpeichern, []);

        $ergebnis = $publishingService->transfer($listing, $request->user());

        return back()
            ->with($ergebnis->ok ? 'status' : 'error', $ergebnis->meldung)
            ->with('warnungen', $ergebnis->warnungen);
    }

    public function publish(ReviewPublishRequest $request, Listing $listing, PublishingService $publishingService): RedirectResponse
    {
        if ($listing->status === ListingStatus::Entwurf) {
            return back()->with('error', 'Bitte markieren Sie das Objekt zuerst als bereit.');
        }

        if (! in_array($listing->status, [ListingStatus::Bereit, ListingStatus::Veroeffentlicht, ListingStatus::Zurueckgezogen], true)) {
            return back()->with('error', 'Das Objekt kann in seinem aktuellen Bearbeitungsstatus nicht veröffentlicht werden.');
        }

        $vollstaendigkeit = app(CompletenessCheck::class)->check($listing);

        if (! $vollstaendigkeit->istVollstaendig()) {
            return back()->with(
                'error',
                'Das Objekt ist nicht vollständig und kann nicht veröffentlicht werden. Es fehlen: '
                    .implode(', ', $vollstaendigkeit->fehlend).'.'
            );
        }

        $adressLeck = $this->adressLeck($listing);

        if ($adressLeck !== []) {
            return back()->with(
                'error',
                'Die Adressfreigabe erlaubt nur PLZ und Ort, folgende Texte enthalten dennoch Straße oder Hausnummer: '
                    .implode(', ', $adressLeck).'.'
            );
        }

        if ($vollstaendigkeit->hinweise !== []) {
            $bestaetigt = (array) $request->input('hinweise_bestaetigt', []);

            if (count($bestaetigt) < count($vollstaendigkeit->hinweise)) {
                return back()->with(
                    'error',
                    'Bitte bestätigen Sie vor der Veröffentlichung alle Hinweise: '.implode(', ', $vollstaendigkeit->hinweise).'.'
                );
            }
        }

        /** @var list<string> $portale */
        $portale = $request->validated('portale');

        app(ReleaseService::class)->freigeben($listing, $request->user(), ReleaseAktion::Veroeffentlichen, $portale);

        $ergebnis = $publishingService->publish($listing, $portale, $request->user());

        if ($ergebnis->ok) {
            $statusMachine = app(ListingStatusMachine::class);

            try {
                if ($listing->status === ListingStatus::Bereit) {
                    $statusMachine->transition($listing, ListingStatus::Veroeffentlicht);
                } elseif ($listing->status === ListingStatus::Zurueckgezogen) {
                    $statusMachine->transition($listing, ListingStatus::Bereit);
                    $statusMachine->transition($listing, ListingStatus::Veroeffentlicht);
                }
            } catch (IllegalStatusTransitionException) {
                // Der Connector hat die Veröffentlichung angefordert, der
                // Bearbeitungsstatus bleibt in diesem seltenen Fall unverändert
                // stehen; die Portalstatus zeigen den tatsächlichen Stand.
            }
        }

        return back()
            ->with($ergebnis->ok ? 'status' : 'error', $ergebnis->meldung)
            ->with('warnungen', $ergebnis->warnungen);
    }

    public function withdraw(ReviewWithdrawRequest $request, Listing $listing, PublishingService $publishingService): RedirectResponse
    {
        /** @var list<string> $ausgewaehlt */
        $ausgewaehlt = (array) $request->validated('portale', []);

        if ($ausgewaehlt !== []) {
            $portalIds = $listing->portalPublications()
                ->whereIn('portal_id', $ausgewaehlt)
                ->pluck('portal_id')
                ->all();
        } else {
            // Prüfbericht 2026-09-11, Befund 1: ohne Auswahl werden auch
            // Publikationen mit "fehler" oder "unbekannt" zurückgezogen, sonst
            // bleibt ein Objekt nach einem gescheiterten Portalaufruf ohne
            // Ausweg über die Oberfläche stehen.
            $portalIds = $listing->portalPublications()
                ->whereIn('status', [
                    PortalStatus::Angefordert->value,
                    PortalStatus::Aktiv->value,
                    PortalStatus::Fehler->value,
                    PortalStatus::Unbekannt->value,
                ])
                ->pluck('portal_id')
                ->all();
        }

        $ergebnis = $publishingService->withdraw($listing, $portalIds, $request->user());

        if ($ergebnis->ok && $listing->status === ListingStatus::Veroeffentlicht) {
            app(ListingStatusMachine::class)->transition($listing, ListingStatus::Zurueckgezogen);
        }

        return back()
            ->with($ergebnis->ok ? 'status' : 'error', $ergebnis->meldung)
            ->with('warnungen', $ergebnis->warnungen);
    }

    /**
     * Bestätigung der Ausnahme von der Energieausweispflicht durch einen
     * Administrator (Masterprompt-Abgleich B.5). Die Berechtigung prüft
     * bereits die Middleware role:admin auf der Route.
     */
    public function confirmEnergyException(ReviewEnergyExceptionRequest $request, Listing $listing): RedirectResponse
    {
        $this->authorize('view', $listing);

        $energy = $listing->energy;

        if ($energy === null) {
            return back()->with('error', 'Für dieses Objekt ist kein Energieausweisdatensatz vorhanden.');
        }

        $energy->update([
            'ausnahme_begruendung' => $request->string('ausnahme_begruendung')->value(),
            'ausnahme_bestaetigt_von_user_id' => $request->user()->id,
            'ausnahme_bestaetigt_at' => now(),
        ]);

        return back()->with('status', 'Die Ausnahme von der Energieausweispflicht wurde bestätigt.');
    }

    /**
     * @return list<string>
     */
    private function adressLeck(Listing $listing): array
    {
        $labels = [
            'titel' => 'Überschrift',
            'beschreibung_objekt' => 'Beschreibung Objekt',
            'beschreibung_ausstattung' => 'Beschreibung Ausstattung',
            'beschreibung_lage' => 'Beschreibung Lage',
            'beschreibung_sonstiges' => 'Beschreibung Sonstiges',
        ];

        $treffer = [];

        foreach ($labels as $spalte => $label) {
            if (ListingPreviewBuilder::enthaeltAdresse($listing, $listing->{$spalte})) {
                $treffer[] = $label;
            }
        }

        return $treffer;
    }
}
