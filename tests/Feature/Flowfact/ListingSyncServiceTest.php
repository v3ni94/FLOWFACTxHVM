<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\RateLimitException;
use App\Flowfact\Client\Exceptions\TransportException;
use App\Flowfact\Sync\ListingSyncService;
use App\Flowfact\Sync\SyncLease;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\TransferLog;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

final class ListingSyncServiceTest extends FlowfactTestCase
{
    private const string CREATE = '#^/entity-service/schemas/[^/]+$#';

    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    private const string SEARCH = '#^/search-service/schemas/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    protected function setUp(): void
    {
        parent::setUp();

        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        // Album je Schema vorbelegt, damit der Medienabgleich ohne Albumaufruf auskommt.
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
    }

    private function service(): ListingSyncService
    {
        return app(ListingSyncService::class);
    }

    /**
     * Vollständiges Objekt, dessen Bild bereits ein FLOWFACT-Item trägt, damit
     * die Tests des Entitätsablaufs ohne Bildupload auskommen. Ein Objekt ohne
     * Bild im Inserat wäre unvollständig und würde seit Prüfbericht
     * 2026-09-11, Befund 3, gar nicht übertragen.
     */
    private function listingOhneBilder(): Listing
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => null, 'titel' => null]);
        $listing = $listing->fresh(['price', 'energy', 'media']);

        // Welle 3: Übertragung nur aus der jüngsten Freigabeversion (B.6).
        $this->freigeben($listing, []);

        return $listing;
    }

    /**
     * Medienaufrufe, die bei jeder Inhaltsänderung anfallen (Befund 4: die
     * Reihenfolge wird bei geändertem Hash neu gesetzt).
     */
    private function mitMedienRouten(FakeFlowfact $fake): FakeFlowfact
    {
        return $fake
            ->on('GET', self::ITEMS, fn () => Http::response([self::multimediaItem(101)]))
            ->on('PUT', self::ASSIGN, ['assignments' => []]);
    }

    private function fakeOhneTreffer(string $neueId = 'ent-neu', mixed $createAntwort = null): FakeFlowfact
    {
        return $this->mitMedienRouten($this->fake())
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, $createAntwort ?? self::entityResponse($neueId))
            ->install();
    }

    public function test_anlegen_wenn_keine_entitaet_existiert(): void
    {
        $fake = $this->fakeOhneTreffer('ent-neu');
        $listing = $this->listingOhneBilder();

        // Prüfbericht 2026-09-12, Befund 10: status active setzt einen Benutzer
        // mit Veröffentlichungsrecht voraus (Regression 18 prüft inactive).
        $ergebnis = $this->service()->sync($listing, User::factory()->create(['darf_veroeffentlichen' => true]));

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame('ent-neu', $ergebnis->entityId);
        self::assertSame(1, $fake->count('POST', self::SEARCH));
        self::assertSame(1, $fake->count('POST', self::CREATE));

        $create = $fake->requests('POST', self::CREATE)[0];
        self::assertTrue($create->hasHeader('x-ff-version', '2'));
        self::assertSame(self::BASE.'/entity-service/schemas/'.self::SCHEMA_MIETE, $create->url());
        self::assertSame(['values' => [$listing->objektnummer]], $create->data()['identifier']);
        self::assertSame(['values' => ['active']], $create->data()['status']);

        $suche = $fake->requests('POST', self::SEARCH)[0];
        self::assertSame('EQUALS', $suche->data()['conditions'][0]['operator']);
        self::assertSame($listing->objektnummer, $suche->data()['conditions'][0]['value']);

        $link = $listing->flowfactLink()->first();
        self::assertSame('ent-neu', $link->flowfact_entity_id);
        self::assertSame(self::SCHEMA_MIETE, $link->flowfact_schema);
        self::assertSame(SyncStatus::Uebertragen, $link->sync_status);
        self::assertNotNull($link->uebertragener_inhalt_hash);
        self::assertNotNull($link->letzte_uebertragung_at);
        self::assertNull($link->sperre_bis);
    }

    public function test_anlegen_akzeptiert_nackte_id_als_antwort(): void
    {
        $this->fakeOhneTreffer('x', fn () => Http::response('"nur-die-id"', 200));
        $listing = $this->listingOhneBilder();

        $ergebnis = $this->service()->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame('nur-die-id', $listing->flowfactLink()->first()->flowfact_entity_id);
    }

    public function test_aktualisieren_bei_bekannter_id_und_geaendertem_inhalt(): void
    {
        $listing = $this->listingOhneBilder();
        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => 'alter-hash',
        ]);

        $fake = $this->mitMedienRouten($this->fake())
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->install();

        $ergebnis = $this->service()->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame('Objekt in FLOWFACT aktualisiert.', $ergebnis->meldung);
        self::assertSame(1, $fake->count('GET', self::GET_ENTITY));
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));
        self::assertSame(0, $fake->count('POST', self::CREATE));
        self::assertSame(0, $fake->count('POST', self::SEARCH));
        self::assertNotSame('alter-hash', $listing->flowfactLink()->first()->uebertragener_inhalt_hash);
    }

    public function test_unveraenderter_hash_ueberspringt_den_patch(): void
    {
        $listing = $this->listingOhneBilder();

        $this->mitMedienRouten($this->fake())
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityResponse('ent-1'))
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->install();

        $this->service()->sync($listing);
        $zweiter = $this->service()->sync($listing->fresh(['price', 'energy', 'media']));

        self::assertTrue($zweiter->ok);
        self::assertSame('Keine Änderungen seit der letzten Übertragung.', $zweiter->meldung);
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'PATCH');
    }

    public function test_force_erzwingt_den_patch(): void
    {
        $listing = $this->listingOhneBilder();
        $fake = $this->mitMedienRouten($this->fake())
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityResponse('ent-1'))
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->install();

        $this->service()->sync($listing);
        $this->service()->sync($listing->fresh(['price', 'energy', 'media']), null, true);

        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));
    }

    public function test_suche_findet_vorhandene_entitaet_und_legt_nicht_an(): void
    {
        $listing = $this->listingOhneBilder();
        $fake = $this->mitMedienRouten($this->fake())
            ->on('POST', self::SEARCH, self::searchResponse([
                self::entityResponse('ent-vorhanden', ['identifier' => ['values' => [$listing->objektnummer]]]),
            ]))
            ->on('PATCH', self::GET_ENTITY, self::entityResponse('ent-vorhanden'))
            ->install();

        $ergebnis = $this->service()->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame('ent-vorhanden', $ergebnis->entityId);
        self::assertSame(0, $fake->count('POST', self::CREATE));
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));
        self::assertSame('ent-vorhanden', $listing->flowfactLink()->first()->flowfact_entity_id);
    }

    public function test_treffer_mit_abweichendem_identifier_zaehlt_nicht(): void
    {
        $listing = $this->listingOhneBilder();
        $fake = $this->mitMedienRouten($this->fake())
            ->on('POST', self::SEARCH, self::searchResponse([
                self::entityResponse('ent-fremd', ['identifier' => ['values' => [$listing->objektnummer.'-ALT']]]),
            ]))
            ->on('POST', self::CREATE, self::entityResponse('ent-neu'))
            ->install();

        $ergebnis = $this->service()->sync($listing);

        self::assertTrue($ergebnis->ok);
        self::assertSame('ent-neu', $ergebnis->entityId);
        self::assertSame(1, $fake->count('POST', self::CREATE));
    }

    public function test_zeitueberschreitung_nach_anlegen_fuehrt_beim_zweiten_lauf_zur_suche_statt_zum_zweiten_anlegen(): void
    {
        $listing = $this->listingOhneBilder();
        $createVersuche = 0;
        $angelegt = false;

        Http::fake(function (Request $request) use ($listing, &$createVersuche, &$angelegt) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'POST' && preg_match(self::CREATE, $path) === 1) {
                $createVersuche++;
                // Der Anlegebefehl wurde gesendet, die Antwort geht verloren.
                $angelegt = true;

                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            if ($request->method() === 'POST' && preg_match(self::SEARCH, $path) === 1) {
                return Http::response(self::searchResponse($angelegt
                    ? [self::entityResponse('ent-nach-timeout', ['identifier' => ['values' => [$listing->objektnummer]]])]
                    : []));
            }

            if ($request->method() === 'PATCH' && preg_match(self::GET_ENTITY, $path) === 1) {
                return Http::response(self::entityResponse('ent-nach-timeout'));
            }

            if ($request->method() === 'GET' && preg_match(self::ITEMS, $path) === 1) {
                return Http::response([self::multimediaItem(101)]);
            }

            if ($request->method() === 'PUT' && preg_match(self::ASSIGN, $path) === 1) {
                return Http::response(['assignments' => []]);
            }

            return null;
        });

        $erster = $this->service()->sync($listing);

        self::assertFalse($erster->ok);
        self::assertInstanceOf(TransportException::class, $erster->ausnahme);
        $link = $listing->flowfactLink()->first();
        self::assertSame(SyncStatus::Fehlgeschlagen, $link->sync_status);
        self::assertNull($link->flowfact_entity_id);
        self::assertNull($link->sperre_bis, 'Die Lease wird auch im Fehlerfall freigegeben.');
        self::assertStringContainsString('timed out', (string) $link->letzter_fehler);

        $zweiter = $this->service()->sync($listing->fresh(['price', 'energy', 'media']));

        self::assertTrue($zweiter->ok, $zweiter->meldung);
        self::assertSame('ent-nach-timeout', $zweiter->entityId);
        self::assertSame(1, $createVersuche, 'Über beide Läufe wird genau ein POST zum Anlegen gesendet.');
        // Anlegen, Suche, PATCH sowie Items lesen und Reihenfolge setzen (Befund 4).
        Http::assertSentCount(5);
    }

    public function test_suchfehler_verhindert_das_anlegen(): void
    {
        $listing = $this->listingOhneBilder();
        $fake = $this->fake()
            ->on('POST', self::SEARCH, ['message' => 'search down'], 500)
            ->on('POST', self::CREATE, self::entityResponse('ent-neu'))
            ->install();

        $ergebnis = $this->service()->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertSame(0, $fake->count('POST', self::CREATE));
        self::assertSame(SyncStatus::Fehlgeschlagen, $listing->flowfactLink()->first()->sync_status);
    }

    public function test_mehrfachtreffer_stoppt_ohne_anlegen(): void
    {
        $listing = $this->listingOhneBilder();
        $fake = $this->fake()
            ->on('POST', self::SEARCH, self::searchResponse([
                self::entityResponse('ent-a', ['identifier' => ['values' => [$listing->objektnummer]]]),
                self::entityResponse('ent-b', ['identifier' => ['values' => [$listing->objektnummer]]]),
            ]))
            ->on('POST', self::CREATE, self::entityResponse('ent-neu'))
            ->install();

        $ergebnis = $this->service()->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertSame(ListingSyncService::MELDUNG_MEHRFACHTREFFER, $ergebnis->meldung);
        self::assertSame(0, $fake->count('POST', self::CREATE));

        $link = $listing->flowfactLink()->first();
        self::assertSame(SyncStatus::Fehlgeschlagen, $link->sync_status);
        self::assertNull($link->flowfact_entity_id);
        self::assertSame(ListingSyncService::MELDUNG_MEHRFACHTREFFER, $link->letzter_fehler);
    }

    public function test_verworfene_id_bei_404_fuehrt_zur_suche(): void
    {
        $listing = $this->listingOhneBilder();
        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-geloescht',
            'flowfact_schema' => self::SCHEMA_MIETE,
        ]);

        $fake = $this->mitMedienRouten($this->fake())
            ->on('GET', self::GET_ENTITY, ['message' => 'not found'], 404)
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityResponse('ent-neu'))
            ->install();

        $ergebnis = $this->service()->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('POST', self::SEARCH));
        self::assertSame('ent-neu', $listing->flowfactLink()->first()->flowfact_entity_id);
    }

    public function test_lease_blockiert_zweiten_lauf(): void
    {
        $listing = $this->listingOhneBilder();
        $link = ListingFlowfactLink::factory()->create(['listing_id' => $listing->id]);
        self::assertNotNull(app(SyncLease::class)->acquire($link));

        Http::fake();

        $ergebnis = $this->service()->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertTrue($ergebnis->busy);
        Http::assertNothingSent();
        self::assertNotNull($link->fresh()->sperre_bis, 'Der blockierte Lauf gibt die fremde Lease nicht frei.');
    }

    public function test_abgelaufene_lease_wird_uebernommen(): void
    {
        $listing = $this->listingOhneBilder();
        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'sperre_bis' => now()->subMinute(),
        ]);
        $this->fakeOhneTreffer();

        $ergebnis = $this->service()->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
    }

    public function test_entwurf_und_archiviert_werden_abgelehnt(): void
    {
        Http::fake();

        foreach ([ListingStatus::Entwurf, ListingStatus::Archiviert] as $status) {
            $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => $status]);

            $ergebnis = $this->service()->sync($listing);

            self::assertFalse($ergebnis->ok);
            self::assertSame(ListingSyncService::MELDUNG_ENTWURF, $ergebnis->meldung);
            self::assertNull($listing->flowfactLink()->first());
        }

        Http::assertNothingSent();
    }

    /**
     * Masterprompt-Abgleich B.6: ohne Freigabeversion wird nicht übertragen.
     */
    public function test_ohne_freigabe_wird_nicht_uebertragen(): void
    {
        Http::fake();
        $listing = $this->bereitesListing();

        $ergebnis = $this->service()->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertSame(ListingSyncService::MELDUNG_KEINE_FREIGABE, $ergebnis->meldung);
        Http::assertNothingSent();
    }

    /**
     * Kundenwunsch 21.09.2026: Ohne ein je Objekt gewähltes FLOWFACT-Schema
     * gilt das Objekt seit CompletenessCheck als unvollständig und wird gar
     * nicht erst übertragen; die frühere Rückfall-Meldung anhand der
     * globalen Einstellung greift nur noch für Altobjekte ohne dieses Feld.
     */
    public function test_fehlendes_schema_ist_ein_konfigurationsfehler_ohne_api_aufruf(): void
    {
        $this->settings()->forget(ListingSyncService::SCHEMA_MIETE);
        Http::fake();
        $listing = $this->listingOhneBilder();
        $listing->update(['flowfact_schema' => null]);

        $ergebnis = $this->service()->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertStringContainsString('FLOWFACT-Schema', $ergebnis->meldung);
        self::assertSame(SyncStatus::Fehlgeschlagen, $listing->flowfactLink()->first()->sync_status);
        Http::assertNothingSent();
    }

    /**
     * Rückfall für Objekte, die vor der Einführung des je-Objekt-Schemas
     * angelegt wurden: CompletenessCheck würde ohne eigenes Feld blockieren,
     * daher direkt gegen schemaFuer() geprüft statt über sync().
     */
    public function test_schemafuer_greift_ohne_eigenes_feld_auf_die_globale_einstellung_zurueck(): void
    {
        $listing = $this->listingOhneBilder();
        $listing->update(['flowfact_schema' => null]);

        self::assertSame(self::SCHEMA_MIETE, $this->service()->schemaFuer($listing->fresh()));

        $this->settings()->forget(ListingSyncService::SCHEMA_MIETE);

        self::assertNull($this->service()->schemaFuer($listing->fresh()));
    }

    public function test_auth_fehler_ohne_wiederholung_mit_dokumentierter_meldung(): void
    {
        $listing = $this->listingOhneBilder();
        $fake = $this->fake()
            ->on('POST', self::SEARCH, ['message' => 'forbidden'], 401)
            ->install();

        $ergebnis = $this->service()->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertSame(AuthenticationException::MELDUNG, $ergebnis->meldung);
        self::assertInstanceOf(AuthenticationException::class, $ergebnis->ausnahme);
        self::assertSame(1, $fake->count('POST', self::SEARCH));

        $link = $listing->flowfactLink()->first();
        self::assertSame(SyncStatus::Fehlgeschlagen, $link->sync_status);
        self::assertSame(AuthenticationException::MELDUNG, $link->letzter_fehler);
        self::assertStringNotContainsString(self::TOKEN, (string) $link->letzter_fehler);
    }

    public function test_ratenbegrenzung_wird_als_ausnahme_durchgereicht(): void
    {
        $listing = $this->listingOhneBilder();
        $this->fake()->on('POST', self::SEARCH, fn () => Http::response(['message' => 'slow down'], 429, ['Retry-After' => '45']))->install();

        $ergebnis = $this->service()->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertInstanceOf(RateLimitException::class, $ergebnis->ausnahme);
        self::assertSame(45, $ergebnis->ausnahme->retryAfterSeconds);
    }

    public function test_warnungen_des_mappers_landen_im_ergebnis_und_protokoll_traegt_listing(): void
    {
        $listing = $this->listingOhneBilder();
        $this->fakeOhneTreffer();

        $ergebnis = $this->service()->sync($listing);

        self::assertContains('Keine FLOWFACT-Zuordnung für Nebenkosten', $ergebnis->warnungen);
        // Suche, Anlegen, Items lesen, Reihenfolge setzen (Befund 4).
        self::assertSame(4, TransferLog::query()->where('listing_id', $listing->id)->count());
    }
}
