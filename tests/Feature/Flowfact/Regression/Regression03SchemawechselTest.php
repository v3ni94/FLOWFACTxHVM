<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Enums\Vermarktungsart;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;

/**
 * Prüfbericht 2026-09-11, Befund 3: Bei bekannter Entität gilt das im Link
 * gespeicherte Schema. Ein Wechsel der Vermarktungsart nach der Übertragung
 * wird abgelehnt statt eine zweite Entität anzulegen, und eine Übertragung
 * setzt in jedem Status die bestandene Vollständigkeitsprüfung voraus.
 */
final class Regression03SchemawechselTest extends FlowfactTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
    }

    private function uebertragenesMietobjekt(ListingStatus $status = ListingStatus::Veroeffentlicht): Listing
    {
        $listing = $this->bereitesListing(['status' => $status]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
        ]);

        return $listing;
    }

    public function test_wechsel_miete_zu_kauf_nach_uebertragung_wird_abgelehnt_und_legt_nichts_an(): void
    {
        $listing = $this->uebertragenesMietobjekt();

        // Schritt 1 des Assistenten: Vermarktungsart auf Kauf, Schritt 4 mit Kaufpreis gespeichert.
        $listing->update(['vermarktungsart' => Vermarktungsart::Kauf]);
        $listing->price()->update(['kaufpreis_cent' => 24_900_000]);
        $listing = $listing->fresh(['price', 'energy', 'media']);

        Http::fake();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertSame(ListingSyncService::MELDUNG_SCHEMAWECHSEL, $ergebnis->meldung);
        Http::assertNothingSent();

        $link = $listing->flowfactLink()->first();
        self::assertSame('ent-1', $link->flowfact_entity_id, 'Die bekannte Entität bleibt verknüpft.');
        self::assertSame(self::SCHEMA_MIETE, $link->flowfact_schema, 'Das gespeicherte Schema bleibt maßgeblich.');
        self::assertSame(SyncStatus::Fehlgeschlagen, $link->sync_status);
        self::assertSame(ListingSyncService::MELDUNG_SCHEMAWECHSEL, $link->letzter_fehler);
    }

    public function test_unvollstaendiges_objekt_wird_in_keinem_status_uebertragen(): void
    {
        foreach ([ListingStatus::Bereit, ListingStatus::Veroeffentlicht, ListingStatus::Zurueckgezogen] as $status) {
            $listing = $this->uebertragenesMietobjekt($status);

            // Kauf ohne Kaufpreis: Schritt 4 wurde nach dem Wechsel nicht neu gespeichert.
            $listing->update(['vermarktungsart' => Vermarktungsart::Kauf]);
            $listing = $listing->fresh(['price', 'energy', 'media']);

            Http::fake();

            $ergebnis = app(ListingSyncService::class)->sync($listing);

            self::assertFalse($ergebnis->ok, $status->value);
            self::assertStringContainsString('nicht vollständig', $ergebnis->meldung);
            self::assertStringContainsString('Kaufpreis', $ergebnis->meldung);
            Http::assertNothingSent();
            self::assertSame('ent-1', $listing->flowfactLink()->first()->flowfact_entity_id);
            self::assertSame(SyncStatus::Fehlgeschlagen, $listing->flowfactLink()->first()->sync_status);
        }
    }

    public function test_gespeichertes_schema_wird_fuer_die_bekannte_entitaet_verwendet(): void
    {
        $listing = $this->uebertragenesMietobjekt();
        $listing->update(['titel' => 'Neuer Titel']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);

        $fake = $this->fake()
            ->on('GET', '#^/entity-service/schemas/'.self::SCHEMA_MIETE.'/entities/ent-1$#', self::entityResponse('ent-1'))
            ->on('PATCH', '#^/entity-service/schemas/'.self::SCHEMA_MIETE.'/entities/ent-1$#', fn () => Http::response('', 200))
            ->on('GET', '#^/multimedia-service/items/entities/[^/]+$#', [self::multimediaItem(101)])
            ->on('PUT', '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#', ['assignments' => []])
            ->install();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('PATCH', '#^/entity-service/schemas/'.self::SCHEMA_MIETE.'/entities/ent-1$#'));
        self::assertSame(0, $fake->count('POST', '#^/entity-service/schemas/[^/]+$#'));
    }
}
