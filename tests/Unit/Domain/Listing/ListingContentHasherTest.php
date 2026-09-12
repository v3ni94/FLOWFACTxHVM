<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\MediaTyp;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ListingContentHasherTest extends TestCase
{
    use RefreshDatabase;

    private function frisch(Listing $listing): Listing
    {
        return $listing->fresh(['price', 'energy', 'media', 'internal']);
    }

    public function test_der_hash_ist_stabil_bei_gleichen_daten(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $hasher = app(ListingContentHasher::class);

        $ersterHash = $hasher->hash($this->frisch($listing));
        $zweiterHash = $hasher->hash($this->frisch($listing));

        $this->assertSame($ersterHash, $zweiterHash);
    }

    public function test_der_hash_aendert_sich_bei_einer_preisaenderung(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $hasher = app(ListingContentHasher::class);

        $vorher = $hasher->hash($this->frisch($listing));

        $listing->price->update(['kaltmiete_cent' => 99_900]);

        $nachher = $hasher->hash($this->frisch($listing));

        $this->assertNotSame($vorher, $nachher);
    }

    public function test_der_hash_aendert_sich_nicht_bei_aenderung_interner_notizen(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $hasher = app(ListingContentHasher::class);

        $vorher = $hasher->hash($this->frisch($listing));

        $listing->internal()->create([
            'interne_notizen' => 'Streng vertraulicher interner Vermerk, darf nie übertragen werden.',
            'eigentuemer_name' => 'Geheimer Eigentümer',
        ]);

        $nachher = $hasher->hash($this->frisch($listing));

        $this->assertSame($vorher, $nachher);
    }

    public function test_der_hash_aendert_sich_nicht_bei_einem_statuswechsel(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $hasher = app(ListingContentHasher::class);

        $vorher = $hasher->hash($this->frisch($listing));

        $listing->status = ListingStatus::Bereit;
        $listing->save();

        $nachher = $hasher->hash($this->frisch($listing));

        $this->assertSame($vorher, $nachher);
    }

    /**
     * Masterprompt-Abgleich B.6: Freigabe und Drehung eines Mediums gehören
     * zum veröffentlichbaren Inhalt.
     */
    public function test_der_hash_aendert_sich_bei_freigabe_und_drehung_eines_mediums(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $dokument = $listing->media()->create([
            'typ' => MediaTyp::Dokument,
            'dateiname_original' => 'expose.pdf',
            'pfad' => 'x/expose.pdf',
            'mime' => 'application/pdf',
            'groesse_bytes' => 2000,
            'sortierung' => 1,
            'im_inserat' => true,
            'pruefsumme_sha256' => hash('sha256', 'expose'),
        ]);
        $hasher = app(ListingContentHasher::class);

        $vorher = $hasher->hash($this->frisch($listing));

        $dokument->update(['freigegeben' => true]);
        $mitDokument = $hasher->hash($this->frisch($listing));
        $this->assertNotSame($vorher, $mitDokument);

        $listing->media()->where('typ', 'bild')->first()->update(['rotation' => 90]);
        $this->assertNotSame($mitDokument, $hasher->hash($this->frisch($listing)));
    }

    public function test_der_hash_aendert_sich_nicht_bei_aenderung_eines_nicht_freigegebenen_dokuments(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $dokument = $listing->media()->create([
            'typ' => MediaTyp::Dokument,
            'dateiname_original' => 'expose.pdf',
            'pfad' => 'x/expose.pdf',
            'mime' => 'application/pdf',
            'groesse_bytes' => 2000,
            'sortierung' => 1,
            'im_inserat' => true,
            'pruefsumme_sha256' => hash('sha256', 'expose'),
        ]);
        $hasher = app(ListingContentHasher::class);

        $vorher = $hasher->hash($this->frisch($listing));

        $dokument->update(['titel' => 'Neuer Dokumenttitel']);

        $this->assertSame($vorher, $hasher->hash($this->frisch($listing)));
    }

    public function test_der_hash_aendert_sich_bei_neuen_inseratsfeldern(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $hasher = app(ListingContentHasher::class);

        $vorher = $hasher->hash($this->frisch($listing));

        $listing->update(['stadtteil' => 'Innenstadt', 'modernisierungsjahr' => 2019]);

        $this->assertNotSame($vorher, $hasher->hash($this->frisch($listing)));

        $vorher = $hasher->hash($this->frisch($listing));
        $listing->update(['interne_bezeichnung' => 'MF Intern 12']);

        $this->assertSame($vorher, $hasher->hash($this->frisch($listing)), 'Die interne Bezeichnung ist kein Inseratsfeld.');
    }
}
