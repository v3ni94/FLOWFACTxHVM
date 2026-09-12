<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Listing\ListingSnapshot;
use App\Domain\Listing\PublishableFields;
use App\Models\Listing;
use App\Models\ListingInternal;
use App\Models\ListingMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-003, Masterprompt-Abgleich B.6: Die Momentaufnahme enthält technisch
 * nur die Positivliste. Alle internen Felder tragen einen Markerwert.
 */
final class ListingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const string MARKER = 'INTERN-MARKER-XYZ';

    public function test_interne_markerwerte_erscheinen_nie_in_der_momentaufnahme(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'interne_bezeichnung' => self::MARKER.' Bezeichnung',
        ]);

        ListingInternal::factory()->create([
            'listing_id' => $listing->id,
            'eigentuemer_name' => self::MARKER.' Eigentümer',
            'eigentuemer_kontakt' => self::MARKER.' Kontakt',
            'verwaltungsobjekt_referenz' => self::MARKER.' Referenz',
            'gebaeudebezeichnung' => self::MARKER.' Gebäude',
            'einheitsnummer' => self::MARKER.' Einheit',
            'lage_im_gebaeude' => self::MARKER.' Lage',
            'interne_notizen' => self::MARKER.' Notizen',
            'schluessel_hinweis' => self::MARKER.' Schlüssel',
            'besichtigung_intern' => self::MARKER.' Besichtigung',
            'kalkulation_notiz' => self::MARKER.' Kalkulation',
        ]);

        $listing->energy->update(['ausnahme_begruendung' => self::MARKER.' Begründung']);
        $listing->flowfactLink()->create(['flowfact_schema' => 'immobilie', 'letzter_fehler' => self::MARKER.' Fehler']);

        // Nicht freigegebenes Dokument mit Markertitel: gehört nicht in die Momentaufnahme.
        ListingMedia::factory()->dokument()->create(['listing_id' => $listing->id, 'titel' => self::MARKER.' Dokument', 'sortierung' => 1]);

        $snapshot = ListingSnapshot::fromListing($listing->fresh(['price', 'energy', 'media', 'internal', 'flowfactLink']));
        $json = json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertStringNotContainsString(self::MARKER, $json);
        self::assertArrayNotHasKey('interne_bezeichnung', $snapshot->listing);
        self::assertArrayNotHasKey('provision_bestaetigt', $snapshot->price ?? []);
        self::assertArrayNotHasKey('ausnahme_begruendung', $snapshot->energy ?? []);
        self::assertArrayNotHasKey('ausnahme_bestaetigt_at', $snapshot->energy ?? []);
        self::assertArrayNotHasKey('status', $snapshot->listing, 'Der Bearbeitungsstatus ist kein Inseratsfeld.');
        self::assertArrayNotHasKey('erstellt_von_user_id', $snapshot->listing);
    }

    public function test_die_momentaufnahme_enthaelt_genau_die_positivliste_und_nur_freigegebene_medien(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        ListingMedia::factory()->dokument()->create(['listing_id' => $listing->id, 'sortierung' => 1]);
        ListingMedia::factory()->grundriss()->create(['listing_id' => $listing->id, 'sortierung' => 2, 'rotation' => 90, 'titel' => 'Grundriss EG']);
        ListingMedia::factory()->bild()->create(['listing_id' => $listing->id, 'sortierung' => 3, 'im_inserat' => false]);

        $snapshot = ListingSnapshot::fromListing($listing->fresh(['price', 'energy', 'media']));

        $sortiert = PublishableFields::LISTING;
        sort($sortiert);
        self::assertSame($sortiert, array_keys($snapshot->listing));

        $sortiert = PublishableFields::PRICE;
        sort($sortiert);
        self::assertSame($sortiert, array_keys($snapshot->price));

        $sortiert = PublishableFields::ENERGY;
        sort($sortiert);
        self::assertSame($sortiert, array_keys($snapshot->energy));

        self::assertCount(2, $snapshot->medien, 'Titelbild und Grundriss; Dokument nicht freigegeben, Bild nicht im Inserat.');
        self::assertSame(['bild', 'grundriss'], array_column($snapshot->medien, 'typ'));
        self::assertSame(90, $snapshot->medien[1]['rotation']);
        self::assertSame('Grundriss EG', $snapshot->medien[1]['titel']);
        self::assertTrue($snapshot->medien[1]['freigegeben']);
        self::assertSame(['id', 'freigegeben', 'pruefsumme_sha256', 'rotation', 'sortierung', 'titel', 'typ'], array_keys($snapshot->medien[0]));

        // Enum- und Datumswerte sind serialisiert, damit die Version JSON-stabil bleibt.
        self::assertSame('miete', $snapshot->listing['vermarktungsart']);
        self::assertSame('liegt_vor', $snapshot->energy['status']);
    }

    public function test_to_array_und_from_array_sind_umkehrbar(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();

        $snapshot = ListingSnapshot::fromListing($listing->fresh(['price', 'energy', 'media']));
        $kopie = ListingSnapshot::fromArray(json_decode(json_encode($snapshot->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        self::assertSame($snapshot->toArray(), $kopie->toArray());
        self::assertSame($snapshot->hashDaten(), $kopie->hashDaten());
        self::assertArrayNotHasKey('medien', $snapshot->payload());
        self::assertArrayNotHasKey('id', $snapshot->hashDaten()['medien'][0]);
    }
}
