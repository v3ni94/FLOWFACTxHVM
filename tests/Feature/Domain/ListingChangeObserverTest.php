<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Listing\ListingContentHasher;
use App\Domain\Listing\ListingSnapshot;
use App\Enums\EnergieausweisStatus;
use App\Enums\SyncStatus;
use App\Models\Listing;
use App\Models\ListingChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Änderungshistorie über Model-Observer (Masterprompt-Abgleich B.6).
 */
final class ListingChangeObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_aenderungen_an_objekt_preis_energie_und_medien_werden_je_feld_protokolliert(): void
    {
        $listing = Listing::factory()->vollstaendig()->create(['titel' => 'Alter Titel']);
        ListingChange::query()->delete();

        $listing->update(['titel' => 'Neuer Titel', 'zimmer' => 3.5]);
        $listing->price->update(['kaltmiete_cent' => 85_000]);
        $listing->energy->update(['status' => EnergieausweisStatus::Vorhanden]);
        $listing->media->first()->update(['titel' => 'Wohnzimmer', 'rotation' => 90]);

        $felder = ListingChange::query()->orderBy('id')->pluck('feld')->all();

        self::assertSame([
            'listings.titel',
            'listings.zimmer',
            'listing_prices.kaltmiete_cent',
            'listing_energies.status',
            'listing_media.titel',
            'listing_media.rotation',
        ], $felder);

        $titel = ListingChange::query()->where('feld', 'listings.titel')->first();
        self::assertSame('Alter Titel', $titel->alt);
        self::assertSame('Neuer Titel', $titel->neu);
        self::assertSame($listing->id, $titel->listing_id);
        self::assertNull($titel->user_id, 'Ohne angemeldeten Benutzer bleibt user_id leer.');

        $preis = ListingChange::query()->where('feld', 'listing_prices.kaltmiete_cent')->first();
        self::assertSame('80000', $preis->alt);
        self::assertSame('85000', $preis->neu);

        $status = ListingChange::query()->where('feld', 'listing_energies.status')->first();
        self::assertSame('liegt_vor', $status->alt);
        self::assertSame('vorhanden', $status->neu);

        self::assertSame(6, $listing->changes()->count());
    }

    public function test_der_angemeldete_benutzer_wird_festgehalten(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();
        ListingChange::query()->delete();

        $this->actingAs($user);
        $listing->update(['titel' => 'Mit Benutzer']);

        self::assertSame($user->id, ListingChange::query()->first()->user_id);
        self::assertTrue(ListingChange::query()->first()->user->is($user));
    }

    public function test_zeitstempel_und_sync_buchhaltung_werden_uebersprungen(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        ListingChange::query()->delete();

        $listing->markContentChanged();
        $listing->save();
        $listing->media->first()->update(['flowfact_multimedia_id' => 'ff-101', 'flowfact_titel' => 'x']);

        self::assertSame(0, ListingChange::query()->count());

        // Der FLOWFACT-Link wird nicht beobachtet.
        $link = $listing->flowfactLink()->create(['flowfact_schema' => 'immobilie', 'sync_status' => SyncStatus::NichtUebertragen]);
        $link->update(['sync_status' => SyncStatus::Uebertragen, 'letzter_fehler' => null, 'sperre_bis' => now()]);

        self::assertSame(0, ListingChange::query()->count());
    }

    public function test_interne_felder_werden_protokolliert_aber_nie_exportiert(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $intern = $listing->internal()->create(['interne_notizen' => 'Alt']);
        ListingChange::query()->delete();

        $vorher = app(ListingContentHasher::class)->hash($listing->fresh(['price', 'energy', 'media']));

        $intern->update(['interne_notizen' => 'GEHEIM-NOTIZ-987', 'gebaeudebezeichnung' => 'Haus B']);

        $eintraege = ListingChange::query()->orderBy('id')->get();
        self::assertSame(['listing_internals.interne_notizen', 'listing_internals.gebaeudebezeichnung'], $eintraege->pluck('feld')->all());
        self::assertSame('GEHEIM-NOTIZ-987', $eintraege[0]->neu);

        $frisch = $listing->fresh(['price', 'energy', 'media', 'internal']);
        self::assertSame($vorher, app(ListingContentHasher::class)->hash($frisch));
        self::assertStringNotContainsString('GEHEIM-NOTIZ-987', json_encode(ListingSnapshot::fromListing($frisch)->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_unveraenderte_speicherungen_erzeugen_keine_eintraege(): void
    {
        $listing = Listing::factory()->create(['titel' => 'Gleich']);
        ListingChange::query()->delete();

        $listing->update(['titel' => 'Gleich']);
        $listing->save();

        self::assertSame(0, ListingChange::query()->count());
    }

    public function test_json_und_boolesche_werte_werden_lesbar_gespeichert(): void
    {
        $listing = Listing::factory()->create(['ausstattung' => ['balkon' => true], 'adresse_im_inserat_anzeigen' => true]);
        ListingChange::query()->delete();

        $listing->update(['ausstattung' => ['balkon' => 'nein'], 'adresse_im_inserat_anzeigen' => false]);

        $ausstattung = ListingChange::query()->where('feld', 'listings.ausstattung')->first();
        self::assertSame('{"balkon":true}', $ausstattung->alt);
        self::assertSame('{"balkon":"nein"}', $ausstattung->neu);

        $flag = ListingChange::query()->where('feld', 'listings.adresse_im_inserat_anzeigen')->first();
        self::assertSame('1', $flag->alt);
        self::assertSame('0', $flag->neu);

        // Die abgeleitete Adressfreigabe wird ebenfalls festgehalten.
        $freigabe = ListingChange::query()->where('feld', 'listings.adress_freigabe')->first();
        self::assertSame('vollstaendig', $freigabe->alt);
        self::assertSame('nur_plz_ort', $freigabe->neu);
    }
}
