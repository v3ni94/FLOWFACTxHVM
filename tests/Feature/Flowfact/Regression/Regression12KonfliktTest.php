<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Masterprompt Abschnitt 23: Konflikterkennung über
 * _metadata.lastModifiedTimestamp der FLOWFACT-Entität. Müller FLOW ist das
 * führende System für die zugeordneten Felder, FLOWFACT für alles andere.
 * Wurde das Objekt in FLOWFACT seit der letzten Übertragung geändert, bricht
 * der Lauf ab (Standard) oder überschreibt mit Warnung (Einstellung).
 */
final class Regression12KonfliktTest extends FlowfactTestCase
{
    private const string SEARCH = '#^/search-service/schemas/[^/]+$#';

    private const string CREATE = '#^/entity-service/schemas/[^/]+$#';

    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    private const string GESPEICHERT = '1757590000000';

    private const string FREMD = '1757600000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
    }

    private function uebertragenesListing(?string $lastModified = self::GESPEICHERT): Listing
    {
        $listing = $this->bereitesListing(['status' => ListingStatus::Veroeffentlicht]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $release = $this->freigeben($listing, []);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
            'release_id' => $release->id,
            'flowfact_last_modified' => $lastModified,
        ]);

        // Neue Freigabe mit geändertem Titel, damit ein PATCH ansteht.
        $listing->update(['titel' => 'Neuer Titel']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);

        return $listing;
    }

    /**
     * @return array<string, mixed>
     */
    private static function entityMitZeitstempel(string $id, string $lastModified): array
    {
        $entity = self::entityResponse($id);
        $entity['_metadata']['lastModifiedTimestamp'] = (int) $lastModified;

        return $entity;
    }

    private function fakeMitRemoteZeitstempel(string $remote, string $nachPatch = '1757700000000'): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityMitZeitstempel('ent-1', $remote))
            ->on('PATCH', self::GET_ENTITY, self::entityMitZeitstempel('ent-1', $nachPatch))
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->install();
    }

    public function test_fremde_aenderung_bricht_die_uebertragung_ab(): void
    {
        $listing = $this->uebertragenesListing();
        $fake = $this->fakeMitRemoteZeitstempel(self::FREMD);

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertStringStartsWith('In FLOWFACT wurde das Objekt seit der letzten Übertragung geändert (', $ergebnis->meldung);
        self::assertStringContainsString('Uhr). Bitte prüfen und erneut freigeben.', $ergebnis->meldung);
        self::assertSame(0, $fake->count('PATCH', self::GET_ENTITY), 'Kein PATCH bei Konflikt.');

        $link = $listing->flowfactLink()->first();
        self::assertSame(SyncStatus::Fehlgeschlagen, $link->sync_status);
        self::assertSame($ergebnis->meldung, $link->letzter_fehler);
        self::assertSame(self::GESPEICHERT, $link->flowfact_last_modified, 'Der gespeicherte Zeitpunkt bleibt bis zur nächsten erfolgreichen Übertragung.');
    }

    public function test_einstellung_ueberschreiben_sendet_den_patch_mit_warnung(): void
    {
        $this->settings()->set(ListingSyncService::KONFLIKTVERHALTEN, ListingSyncService::KONFLIKT_UEBERSCHREIBEN);
        $listing = $this->uebertragenesListing();
        $fake = $this->fakeMitRemoteZeitstempel(self::FREMD, '1757700000000');

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));
        self::assertNotEmpty(array_filter($ergebnis->warnungen, fn (string $w): bool => str_contains($w, 'gemäß Einstellung überschrieben')));

        $link = $listing->flowfactLink()->first();
        self::assertSame(SyncStatus::Uebertragen, $link->sync_status);
        self::assertSame('1757700000000', $link->flowfact_last_modified, 'Der Zeitpunkt aus der PATCH-Antwort wird gespeichert.');
    }

    public function test_unveraenderter_zeitstempel_ist_kein_konflikt(): void
    {
        $listing = $this->uebertragenesListing();
        $fake = $this->fakeMitRemoteZeitstempel(self::GESPEICHERT, '1757700000000');

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));
        self::assertSame('1757700000000', $listing->flowfactLink()->first()->flowfact_last_modified);
    }

    public function test_ohne_gespeicherten_zeitstempel_wird_nicht_verglichen_und_nachgelesen(): void
    {
        $listing = $this->uebertragenesListing(null);
        // PATCH ohne Körper: der Zeitpunkt wird per GET nachgelesen.
        $fake = $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityMitZeitstempel('ent-1', self::FREMD))
            ->on('PATCH', self::GET_ENTITY, fn () => Http::response('', 200))
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->install();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));
        self::assertSame(2, $fake->count('GET', self::GET_ENTITY), 'Vor dem PATCH und zum Nachlesen des Zeitpunkts.');
        self::assertSame(self::FREMD, $listing->flowfactLink()->first()->flowfact_last_modified);
    }

    public function test_anlegen_speichert_den_zeitstempel_aus_der_antwort(): void
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $this->fake()
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityMitZeitstempel('ent-neu', self::GESPEICHERT))
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->install();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(self::GESPEICHERT, $listing->flowfactLink()->first()->flowfact_last_modified);
        self::assertNotContains(ListingSyncService::WARNUNG_KEIN_ZEITSTEMPEL, $ergebnis->warnungen);
    }

    public function test_konfliktverhalten_wird_in_check_config_angezeigt(): void
    {
        config()->set('media.root', sys_get_temp_dir());
        Cache::put('scheduler.last_run', now(), now()->addDay());

        // Eine Erwartung je Zeile: der Ausgabe-Mock gleicht mehrere Teilstrings derselben Zeile nur einmal ab.
        $this->artisan('flow:check-config')->expectsOutputToContain('abbrechen (Standard)');

        $this->settings()->set(ListingSyncService::KONFLIKTVERHALTEN, 'ueberschreiben');

        $this->artisan('flow:check-config')->expectsOutputToContain('ueberschreiben');
    }
}
