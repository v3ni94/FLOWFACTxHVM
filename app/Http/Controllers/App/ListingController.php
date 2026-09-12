<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\IllegalStatusTransitionException;
use App\Domain\Listing\ListingStatusMachine;
use App\Domain\Listing\PublishableFields;
use App\Enums\ListingStatus;
use App\Enums\Nutzungsstatus;
use App\Enums\Objektart;
use App\Enums\Vermarktungsart;
use App\Http\Controllers\App\Support\CompletenessFieldMap;
use App\Http\Controllers\App\Support\PortalSummary;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\CreateListingRequest;
use App\Http\Requests\Listing\DuplicateListingRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Objektübersicht, Anlage, Detailseite, Historie und Duplizieren
 * (Masterprompt Abschnitt 7 und 24, Masterprompt-Abgleich B.1, B.3).
 */
class ListingController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Listing::class);

        $query = Listing::query()
            ->with([
                'price', 'flowfactLink', 'portalPublications', 'bearbeiter',
                'media' => fn ($q) => $q->where('typ', 'bild')->orderBy('sortierung')->limit(1),
            ])
            ->orderByDesc('updated_at');

        $status = $request->string('status')->value();
        $vermarktungsart = $request->string('vermarktungsart')->value();
        $bearbeiterId = $request->string('bearbeiter')->value();
        $suche = trim($request->string('suche')->value());

        if ($status !== '') {
            if ($status === 'teilweise') {
                $query->where('status', ListingStatus::Veroeffentlicht->value)
                    ->whereHas('portalPublications', fn ($q) => $q->where('status', 'aktiv'))
                    ->whereHas('portalPublications', fn ($q) => $q->where('status', '!=', 'aktiv'));
            } else {
                $query->where('status', $status);
            }
        }

        if ($vermarktungsart !== '') {
            $query->where('vermarktungsart', $vermarktungsart);
        }

        if ($bearbeiterId !== '') {
            $query->where('bearbeiter_user_id', $bearbeiterId);
        }

        if ($suche !== '') {
            $query->where(function ($sub) use ($suche): void {
                $sub->where('objektnummer', 'like', '%'.$suche.'%')
                    ->orWhere('titel', 'like', '%'.$suche.'%')
                    ->orWhere('interne_bezeichnung', 'like', '%'.$suche.'%')
                    ->orWhere('strasse', 'like', '%'.$suche.'%')
                    ->orWhere('ort', 'like', '%'.$suche.'%');
            });
        }

        $listings = $query->paginate(25)->withQueryString();

        return view('app.listings.index', [
            'listings' => $listings,
            'statusOptionen' => array_merge(ListingStatus::options(), ['teilweise' => 'Teilweise veröffentlicht']),
            'vermarktungsartOptionen' => Vermarktungsart::options(),
            'objektartOptionen' => Objektart::options(),
            'bearbeiterOptionen' => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'filter' => [
                'status' => $status,
                'vermarktungsart' => $vermarktungsart,
                'bearbeiter' => $bearbeiterId,
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
            'bearbeiter_user_id' => $request->user()->id,
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
            'flowfactLink', 'portalPublications', 'ansprechpartner', 'erstelltVon', 'bearbeiter',
            'transferLogs' => fn ($query) => $query->take(20),
            'portalStatusLogs' => fn ($query) => $query->take(20),
        ]);

        $vollstaendigkeit = app(CompletenessCheck::class)->check($listing);

        $fehlendMitSchritt = collect($vollstaendigkeit->fehlend)
            ->map(fn (string $label, string $feld): array => [
                'label' => $label,
                'schritt' => CompletenessFieldMap::schritt($feld),
            ])
            ->values();

        return view('app.listings.show', [
            'listing' => $listing,
            'vollstaendigkeit' => $vollstaendigkeit,
            'fehlendMitSchritt' => $fehlendMitSchritt,
            'portalBadge' => PortalSummary::badgeClass($listing),
            'portalText' => PortalSummary::text($listing),
        ]);
    }

    public function history(Request $request, Listing $listing): View
    {
        $this->authorize('view', $listing);

        // Interne Felder (listing_internals) werden protokolliert, aber nur
        // Benutzern mit Bearbeitungsrecht angezeigt, nie exportiert
        // (Masterprompt-Abgleich B.6).
        $kannBearbeiten = $request->user()?->can('update', $listing) ?? false;

        $aenderungen = $listing->changes()
            ->with('user')
            ->when(! $kannBearbeiten, fn ($query) => $query->where('feld', 'not like', 'listing_internals.%'))
            ->paginate(50);

        return view('app.listings.historie', [
            'listing' => $listing,
            'aenderungen' => $aenderungen,
        ]);
    }

    /**
     * Dupliziert ein Objekt (Masterprompt Abschnitt 24, Masterprompt-Abgleich
     * B.1, B.3): kopiert die Inseratsfelder (ohne uuid, objektnummer, titel,
     * interne_bezeichnung), Preise, Energieausweis, interne Daten und die
     * Mediendateien physisch mit neuen Dateinamen. FLOWFACT-Verknüpfung,
     * Portalveröffentlichungen, Freigabeversionen und KI-Texte werden nie
     * übernommen; die Provisionsbestätigung und der Nutzungsstatus werden
     * zurückgesetzt.
     */
    public function duplicate(DuplicateListingRequest $request, Listing $listing): RedirectResponse
    {
        $listing->load(['price', 'energy', 'internal', 'media']);

        $nichtKopiert = [];

        $kopie = DB::transaction(function () use ($request, $listing, &$nichtKopiert): Listing {
            $kopie = new Listing;

            foreach (PublishableFields::LISTING as $feld) {
                if (in_array($feld, ['uuid', 'objektnummer', 'titel'], true)) {
                    continue;
                }

                $kopie->{$feld} = $listing->{$feld};
            }

            $kopie->interne_bezeichnung = null;
            $kopie->nutzungsstatus = Nutzungsstatus::Unbekannt;
            $kopie->status = ListingStatus::Entwurf;
            $kopie->erstellt_von_user_id = $request->user()->id;
            $kopie->bearbeiter_user_id = $request->user()->id;
            $kopie->freigegeben_fuer_alle = false;
            $kopie->save();

            $nichtKopiert = ['Überschrift', 'Interne Bezeichnung', 'Nutzungsstatus', 'FLOWFACT-Verknüpfung', 'Portalveröffentlichungen', 'Freigabeversionen', 'KI-Texte', 'Provisionsbestätigung'];

            if ($listing->price !== null) {
                $preisDaten = [];

                foreach (PublishableFields::PRICE as $feld) {
                    $preisDaten[$feld] = $listing->price->{$feld};
                }

                $kopie->price()->create($preisDaten);
            }

            if ($listing->energy !== null) {
                $energieDaten = [];

                foreach (PublishableFields::ENERGY as $feld) {
                    $energieDaten[$feld] = $listing->energy->{$feld};
                }

                $kopie->energy()->create($energieDaten);
            }

            if ($listing->internal !== null) {
                $kopie->internal()->create($listing->internal->only([
                    'eigentuemer_name', 'eigentuemer_kontakt', 'verwaltungsobjekt_referenz',
                    'interne_notizen', 'schluessel_hinweis', 'besichtigung_intern', 'kalkulation_notiz',
                    'gebaeudebezeichnung', 'einheitsnummer', 'lage_im_gebaeude',
                ]));
            }

            foreach ($listing->media as $medium) {
                $this->kopiereMedium($kopie, $medium);
            }

            return $kopie;
        });

        return redirect()
            ->route('app.listings.step', ['listing' => $kopie, 'schritt' => 1])
            ->with('status', 'Das Objekt wurde als "'.$kopie->objektnummer.'" dupliziert.')
            ->with('nicht_kopiert', $nichtKopiert);
    }

    private function kopiereMedium(Listing $kopie, ListingMedia $original): void
    {
        $endung = pathinfo($original->pfad, PATHINFO_EXTENSION);
        $verzeichnis = 'listings/'.$kopie->uuid;
        $neuerDateiname = Str::random(32).($endung !== '' ? '.'.$endung : '');
        $neuerPfad = $verzeichnis.'/'.$neuerDateiname;

        if (! Storage::disk('media')->exists($original->pfad)) {
            return;
        }

        // Prüfbericht 2026-09-12, Befund 15: die Vorschau wird mitkopiert,
        // sonst zeigt das Duplikat in Übersicht und Schritt 6 das Original
        // in voller Größe statt der kleinen Vorschau. Original und Kopie
        // bleiben unabhängige Dateien.
        app(MediaUploadService::class)->copyWithPreview($original, $neuerPfad);

        $inhalt = Storage::disk('media')->get($neuerPfad) ?? '';

        $kopie->media()->create([
            'typ' => $original->typ,
            'dateiname_original' => $original->dateiname_original,
            'pfad' => $neuerPfad,
            'mime' => $original->mime,
            'groesse_bytes' => $original->groesse_bytes,
            'breite' => $original->breite,
            'hoehe' => $original->hoehe,
            'sortierung' => $original->sortierung,
            'titel' => $original->titel,
            'im_inserat' => $original->im_inserat,
            'freigegeben' => $original->freigegeben,
            'rotation' => $original->rotation,
            'enthaelt_standortdaten' => $original->enthaelt_standortdaten,
            'flowfact_multimedia_id' => null,
            'pruefsumme_sha256' => hash('sha256', $inhalt),
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
}
