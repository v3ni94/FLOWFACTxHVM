<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\IllegalStatusTransitionException;
use App\Domain\Listing\ListingStatusMachine;
use App\Enums\ListingStatus;
use App\Enums\Objektart;
use App\Enums\PortalStatus;
use App\Enums\Vermarktungsart;
use App\Flowfact\Sync\PublishingService;
use App\Http\Controllers\App\Support\CompletenessFieldMap;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\CreateListingRequest;
use App\Http\Requests\Listing\PublishRequest;
use App\Models\Listing;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Objektübersicht, Anlage, Detailseite und Statusaktionen (Datenvertrag
 * Abschnitt 5 und 4, Architektur Abschnitt 4).
 */
class ListingController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Listing::class);

        $query = Listing::query()
            ->with(['price', 'flowfactLink', 'portalPublications'])
            ->orderByDesc('updated_at');

        $status = $request->string('status')->value();
        $vermarktungsart = $request->string('vermarktungsart')->value();
        $suche = trim($request->string('suche')->value());

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($vermarktungsart !== '') {
            $query->where('vermarktungsart', $vermarktungsart);
        }

        if ($suche !== '') {
            $query->where(function ($sub) use ($suche): void {
                $sub->where('objektnummer', 'like', '%'.$suche.'%')
                    ->orWhere('titel', 'like', '%'.$suche.'%')
                    ->orWhere('ort', 'like', '%'.$suche.'%');
            });
        }

        $listings = $query->paginate(25)->withQueryString();

        return view('app.listings.index', [
            'listings' => $listings,
            'statusOptionen' => ListingStatus::options(),
            'vermarktungsartOptionen' => Vermarktungsart::options(),
            'objektartOptionen' => Objektart::options(),
            'filter' => [
                'status' => $status,
                'vermarktungsart' => $vermarktungsart,
                'suche' => $suche,
            ],
        ]);
    }

    public function store(CreateListingRequest $request): RedirectResponse
    {
        $listing = Listing::create([
            'vermarktungsart' => Vermarktungsart::from($request->string('vermarktungsart')->value()),
            'objektart' => Objektart::from($request->string('objektart')->value()),
            'land' => 'DE',
            'adresse_im_inserat_anzeigen' => true,
            'status' => ListingStatus::Entwurf,
            'erstellt_von_user_id' => $request->user()->id,
            'ansprechpartner_user_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('app.listings.step', ['listing' => $listing, 'schritt' => 1])
            ->with('status', 'Der Entwurf "'.$listing->objektnummer.'" wurde angelegt.');
    }

    public function show(Listing $listing): View
    {
        $this->authorize('view', $listing);

        $listing->load([
            'price', 'energy', 'internal', 'media', 'texts',
            'flowfactLink', 'portalPublications', 'ansprechpartner', 'erstelltVon',
            'transferLogs' => fn ($query) => $query->take(20),
        ]);

        $vollstaendigkeit = app(CompletenessCheck::class)->check($listing);

        $fehlendMitSchritt = collect($vollstaendigkeit->fehlend)
            ->map(fn (string $label, string $feld): array => [
                'label' => $label,
                'schritt' => CompletenessFieldMap::schritt($feld),
            ])
            ->values();

        $publishingService = app(PublishingService::class);

        return view('app.listings.show', [
            'listing' => $listing,
            'vollstaendigkeit' => $vollstaendigkeit,
            'fehlendMitSchritt' => $fehlendMitSchritt,
            'portale' => $publishingService->isConfigured() ? $publishingService->portals() : [],
            'publishingConfigured' => $publishingService->isConfigured(),
        ]);
    }

    public function archive(Listing $listing): RedirectResponse
    {
        $this->authorize('archive', $listing);

        try {
            app(ListingStatusMachine::class)->transition($listing, ListingStatus::Archiviert);
        } catch (IllegalStatusTransitionException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', 'Das Objekt wurde archiviert.');
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

        $ergebnis = $publishingService->transfer($listing, $request->user());

        return back()
            ->with($ergebnis->ok ? 'status' : 'error', $ergebnis->meldung)
            ->with('warnungen', $ergebnis->warnungen);
    }

    public function publish(PublishRequest $request, Listing $listing, PublishingService $publishingService): RedirectResponse
    {
        $vollstaendigkeit = app(CompletenessCheck::class)->check($listing);

        if (! $vollstaendigkeit->istVollstaendig()) {
            return back()->with(
                'error',
                'Das Objekt ist nicht vollständig und kann nicht veröffentlicht werden. Es fehlen: '
                    .implode(', ', $vollstaendigkeit->fehlend).'.'
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

        if ($listing->status === ListingStatus::Entwurf) {
            try {
                app(ListingStatusMachine::class)->transition($listing, ListingStatus::Bereit);
            } catch (IllegalStatusTransitionException $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }

        /** @var list<string> $portale */
        $portale = $request->validated('portale');

        $ergebnis = $publishingService->publish($listing, $portale, $request->user());

        if ($ergebnis->ok && $listing->status === ListingStatus::Bereit) {
            app(ListingStatusMachine::class)->transition($listing, ListingStatus::Veroeffentlicht);
        }

        return back()
            ->with($ergebnis->ok ? 'status' : 'error', $ergebnis->meldung)
            ->with('warnungen', $ergebnis->warnungen);
    }

    public function withdraw(Request $request, Listing $listing, PublishingService $publishingService): RedirectResponse
    {
        $this->authorize('withdraw', $listing);

        $portalIds = $listing->portalPublications()
            ->whereIn('status', [PortalStatus::Angefordert->value, PortalStatus::Aktiv->value])
            ->pluck('portal_id')
            ->all();

        $ergebnis = $publishingService->withdraw($listing, $portalIds, $request->user());

        if ($ergebnis->ok && $listing->status === ListingStatus::Veroeffentlicht) {
            app(ListingStatusMachine::class)->transition($listing, ListingStatus::Zurueckgezogen);
        }

        return back()
            ->with($ergebnis->ok ? 'status' : 'error', $ergebnis->meldung)
            ->with('warnungen', $ergebnis->warnungen);
    }
}
