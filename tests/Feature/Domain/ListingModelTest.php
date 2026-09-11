<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\MediaTyp;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ListingModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_beim_anlegen_werden_uuid_und_objektnummer_vergeben(): void
    {
        $listing = Listing::factory()->create();

        $this->assertNotEmpty($listing->uuid);
        $this->assertMatchesRegularExpression(
            '/^MF-\d{4}-\d{4}$/',
            $listing->objektnummer,
        );
    }

    public function test_jedes_objekt_erhaelt_eine_eigene_fortlaufende_objektnummer(): void
    {
        $erstes = Listing::factory()->create();
        $zweites = Listing::factory()->create();

        $this->assertNotSame($erstes->objektnummer, $zweites->objektnummer);
    }

    public function test_eine_vorgegebene_uuid_und_objektnummer_werden_nicht_ueberschrieben(): void
    {
        $listing = Listing::factory()->create([
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'objektnummer' => 'MF-2020-0099',
        ]);

        $this->assertSame('11111111-1111-1111-1111-111111111111', $listing->uuid);
        $this->assertSame('MF-2020-0099', $listing->objektnummer);
    }

    public function test_die_relationen_zu_ansprechpartner_und_ersteller_funktionieren(): void
    {
        $ersteller = User::factory()->create();
        $ansprechpartner = User::factory()->create();

        $listing = Listing::factory()->create([
            'erstellt_von_user_id' => $ersteller->id,
            'ansprechpartner_user_id' => $ansprechpartner->id,
        ]);

        $this->assertTrue($listing->erstelltVon->is($ersteller));
        $this->assertTrue($listing->ansprechpartner->is($ansprechpartner));
        $this->assertTrue($ersteller->listings->contains($listing));
        $this->assertTrue($ansprechpartner->ansprechpartnerFuer->contains($listing));
    }

    public function test_die_vollstaendig_state_erzeugt_alle_zugehoerigen_daten(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();

        $this->assertNotNull($listing->price);
        $this->assertNotNull($listing->energy);
        $this->assertCount(1, $listing->media);
        $this->assertNotNull($listing->ansprechpartner_user_id);
        $this->assertNotEmpty($listing->beschreibung_objekt);
    }

    public function test_mark_content_changed_setzt_den_zeitstempel(): void
    {
        $listing = Listing::factory()->create(['inhalt_geaendert_at' => null]);

        $listing->markContentChanged();

        $this->assertNotNull($listing->inhalt_geaendert_at);
    }

    public function test_ist_miete_und_ist_kauf_spiegeln_die_vermarktungsart(): void
    {
        $miete = Listing::factory()->miete()->create();
        $kauf = Listing::factory()->kauf()->create();

        $this->assertTrue($miete->istMiete());
        $this->assertFalse($miete->istKauf());
        $this->assertTrue($kauf->istKauf());
        $this->assertFalse($kauf->istMiete());
    }

    public function test_adresse_kurz_baut_die_kurzadresse(): void
    {
        $listing = Listing::factory()->create([
            'strasse' => 'Kölner Straße',
            'hausnummer' => '12',
            'plz' => '41812',
            'ort' => 'Erkelenz',
        ]);

        $this->assertSame('Kölner Straße 12, 41812 Erkelenz', $listing->adresseKurz());
    }

    public function test_scope_aktiv_schliesst_archivierte_objekte_aus(): void
    {
        Listing::factory()->count(2)->create();
        Listing::factory()->archiviert()->create();

        $this->assertSame(2, Listing::query()->aktiv()->count());
        $this->assertSame(3, Listing::query()->count());
    }

    public function test_listing_media_scopes_und_titelbild(): void
    {
        $listing = Listing::factory()->create();

        $listing->media()->create([
            'typ' => MediaTyp::Bild,
            'dateiname_original' => 'titel.jpg',
            'pfad' => 'x/titel.jpg',
            'mime' => 'image/jpeg',
            'groesse_bytes' => 1000,
            'sortierung' => 0,
            'im_inserat' => true,
            'pruefsumme_sha256' => hash('sha256', 'a'),
        ]);

        $listing->media()->create([
            'typ' => MediaTyp::Dokument,
            'dateiname_original' => 'expose.pdf',
            'pfad' => 'x/expose.pdf',
            'mime' => 'application/pdf',
            'groesse_bytes' => 2000,
            'sortierung' => 1,
            'im_inserat' => false,
            'pruefsumme_sha256' => hash('sha256', 'b'),
        ]);

        $this->assertSame(1, $listing->media()->bilder()->count());
        $this->assertSame(1, $listing->media()->imInserat()->count());
        $this->assertTrue($listing->media()->bilder()->first()->istTitelbild());
    }
}
