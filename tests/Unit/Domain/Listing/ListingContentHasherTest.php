<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
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
}
