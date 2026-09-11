<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\CompletenessCheck;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Objektart;
use App\Enums\ProvisionTyp;
use App\Enums\Vermarktungsart;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CompletenessCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_eine_unvollstaendige_mietwohnung_listet_die_fehlenden_felder(): void
    {
        $listing = Listing::factory()->create([
            'titel' => null,
            'beschreibung_objekt' => null,
            'ansprechpartner_user_id' => null,
        ]);

        $ergebnis = app(CompletenessCheck::class)->check($listing);

        $this->assertFalse($ergebnis->istVollstaendig());
        $this->assertArrayHasKey('titel', $ergebnis->fehlend);
        $this->assertArrayHasKey('preis.kaltmiete_cent', $ergebnis->fehlend);
        $this->assertArrayHasKey('preis.nebenkosten_cent', $ergebnis->fehlend);
        $this->assertArrayHasKey('energie.status', $ergebnis->fehlend);
        $this->assertArrayHasKey('medien.bild', $ergebnis->fehlend);
        $this->assertArrayHasKey('beschreibung_objekt', $ergebnis->fehlend);
        $this->assertArrayHasKey('ansprechpartner_user_id', $ergebnis->fehlend);
    }

    public function test_ein_unvollstaendiges_kaufobjekt_verlangt_den_kaufpreis(): void
    {
        $listing = Listing::factory()->kauf()->create([
            'objektart' => Objektart::Wohnung,
        ]);

        $ergebnis = app(CompletenessCheck::class)->check($listing);

        $this->assertFalse($ergebnis->istVollstaendig());
        $this->assertArrayHasKey('preis.kaufpreis_cent', $ergebnis->fehlend);
        $this->assertArrayNotHasKey('preis.kaltmiete_cent', $ergebnis->fehlend);
        $this->assertArrayNotHasKey('heizkosten_versorgung', $ergebnis->fehlend);
    }

    public function test_ein_vollstaendiges_objekt_besteht_die_pruefung(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();

        $ergebnis = app(CompletenessCheck::class)->check($listing->fresh(['price', 'energy', 'media']));

        $this->assertTrue($ergebnis->istVollstaendig());
        $this->assertSame([], $ergebnis->fehlend);
    }

    public function test_dezentrale_heizkostenversorgung_erzeugt_einen_hinweis(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'vermarktungsart' => Vermarktungsart::Miete,
            'heizkosten_versorgung' => HeizkostenVersorgung::Dezentral,
        ]);

        $listing->price->update([
            'kaltmiete_cent' => 80_000,
            'nebenkosten_cent' => 15_000,
            'heizkosten_cent' => null,
            'heizkosten_in_nebenkosten_enthalten' => false,
        ]);

        $ergebnis = app(CompletenessCheck::class)->check($listing->fresh(['price', 'energy', 'media']));

        $this->assertTrue($ergebnis->istVollstaendig());
        $this->assertNotEmpty($ergebnis->hinweise);
    }

    public function test_provisionspflichtig_ohne_provisionstext_ist_unvollstaendig(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();

        $listing->price->update([
            'provision_typ' => ProvisionTyp::Provisionspflichtig,
            'provision_text' => null,
        ]);

        $ergebnis = app(CompletenessCheck::class)->check($listing->fresh(['price', 'energy', 'media']));

        $this->assertFalse($ergebnis->istVollstaendig());
        $this->assertArrayHasKey('preis.provision_text', $ergebnis->fehlend);
    }
}
