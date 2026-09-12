<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ListingStatus;
use App\Enums\Nutzungsstatus;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\ListingPortalPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Duplizieren eines Objekts (Masterprompt Abschnitt 24, Masterprompt-Abgleich
 * B.1, B.3).
 */
final class DuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_das_duplikat_kopiert_die_inseratsfelder_und_setzt_status_und_referenzen_zurueck(): void
    {
        $user = User::factory()->create();
        $original = Listing::factory()->vollstaendig()->create([
            'titel' => 'Originaltitel',
            'interne_bezeichnung' => 'Original-Intern',
            'nutzungsstatus' => Nutzungsstatus::Vermietet,
            'status' => ListingStatus::Veroeffentlicht,
        ]);
        $original->price()->update(['provision_bestaetigt' => true]);

        $original->flowfactLink()->create([
            'flowfact_entity_id' => 'ff-123',
            'flowfact_schema' => 'immobilie',
        ]);
        ListingPortalPublication::factory()->for($original)->create();

        $response = $this->actingAs($user)->post(route('app.listings.duplicate', $original));

        $kopie = Listing::query()->where('id', '!=', $original->id)->latest('id')->first();
        $this->assertNotNull($kopie);

        $response->assertRedirect(route('app.listings.step', ['listing' => $kopie, 'schritt' => 1]));

        // Nie kopiert: uuid, objektnummer, titel, interne Bezeichnung.
        $this->assertNotSame($original->uuid, $kopie->uuid);
        $this->assertNotSame($original->objektnummer, $kopie->objektnummer);
        $this->assertNull($kopie->titel);
        $this->assertNull($kopie->interne_bezeichnung);

        // Status immer Entwurf, Nutzungsstatus zurückgesetzt.
        $this->assertSame(ListingStatus::Entwurf, $kopie->status);
        $this->assertSame(Nutzungsstatus::Unbekannt, $kopie->nutzungsstatus);

        // Übrige Inseratsfelder werden übernommen.
        $this->assertSame($original->wohnflaeche_qm, $kopie->wohnflaeche_qm);
        $this->assertSame($original->beschreibung_objekt, $kopie->beschreibung_objekt);

        // Provisionsbestätigung zurückgesetzt.
        $this->assertFalse($kopie->price->provision_bestaetigt);

        // Nie kopiert: FLOWFACT-Verknüpfung und Portalveröffentlichungen.
        $this->assertNull($kopie->flowfactLink);
        $this->assertSame(0, $kopie->portalPublications()->count());
        $this->assertSame(0, $kopie->releases()->count());
    }

    public function test_das_duplikat_kopiert_medien_physisch_mit_neuem_dateinamen(): void
    {
        Storage::fake('media');

        $user = User::factory()->create();
        $original = Listing::factory()->create();

        Storage::disk('media')->put('listings/'.$original->uuid.'/bild.jpg', 'inhalt-der-datei');

        $medium = ListingMedia::factory()->for($original)->create([
            'pfad' => 'listings/'.$original->uuid.'/bild.jpg',
            'mime' => 'image/jpeg',
            'pruefsumme_sha256' => hash('sha256', 'inhalt-der-datei'),
        ]);

        $this->actingAs($user)->post(route('app.listings.duplicate', $original));

        $kopie = Listing::query()->where('id', '!=', $original->id)->latest('id')->first();
        $kopieMedium = $kopie->media()->first();

        $this->assertNotNull($kopieMedium);
        $this->assertNotSame($medium->pfad, $kopieMedium->pfad);
        $this->assertTrue(Storage::disk('media')->exists($kopieMedium->pfad));
        $this->assertNull($kopieMedium->flowfact_multimedia_id);
    }

    /**
     * Prüfbericht 2026-09-12, Befund 15: das Duplikat muss die
     * Vorschaudatei mitkopieren (sonst zeigen Übersicht und Schritt 6 für
     * das Duplikat das Original in voller Größe) und enthaelt_standortdaten
     * übernehmen (sonst geht der GPS-Hinweis am Duplikat verloren).
     */
    public function test_das_duplikat_kopiert_die_vorschau_und_den_standortdaten_hinweis(): void
    {
        Storage::fake('media');

        $user = User::factory()->create();
        $original = Listing::factory()->create();

        Storage::disk('media')->put('listings/'.$original->uuid.'/bild.jpg', 'inhalt-der-datei');
        Storage::disk('media')->put('listings/'.$original->uuid.'/bild_vorschau.jpg', 'inhalt-der-vorschau');

        ListingMedia::factory()->for($original)->create([
            'pfad' => 'listings/'.$original->uuid.'/bild.jpg',
            'mime' => 'image/jpeg',
            'pruefsumme_sha256' => hash('sha256', 'inhalt-der-datei'),
            'enthaelt_standortdaten' => true,
        ]);

        $this->actingAs($user)->post(route('app.listings.duplicate', $original));

        $kopie = Listing::query()->where('id', '!=', $original->id)->latest('id')->first();
        $kopieMedium = $kopie->media()->first();

        $this->assertNotNull($kopieMedium);
        $this->assertTrue($kopieMedium->enthaelt_standortdaten, 'enthaelt_standortdaten muss übernommen werden.');

        $vorschauPfad = pathinfo($kopieMedium->pfad, PATHINFO_DIRNAME).'/'.pathinfo($kopieMedium->pfad, PATHINFO_FILENAME).'_vorschau.jpg';
        $this->assertTrue(Storage::disk('media')->exists($vorschauPfad), 'Die Vorschau des Duplikats fehlt.');
        $this->assertSame('inhalt-der-vorschau', Storage::disk('media')->get($vorschauPfad));

        // Original und Duplikat bleiben unabhängige Dateien.
        Storage::disk('media')->delete($kopieMedium->pfad);
        $this->assertTrue(Storage::disk('media')->exists('listings/'.$original->uuid.'/bild.jpg'));
    }

    public function test_ein_leser_darf_nicht_duplizieren(): void
    {
        $leser = User::factory()->leser()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($leser)->post(route('app.listings.duplicate', $listing));

        $response->assertForbidden();
    }
}
