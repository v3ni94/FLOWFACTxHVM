<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\Befund;
use App\Domain\Listing\CompletenessCheck;
use App\Enums\MediaTyp;
use App\Enums\Nutzungsstatus;
use App\Enums\ProvisionTyp;
use App\Enums\PruefArt;
use App\Enums\PruefEbene;
use App\Enums\StellplatzModus;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Befunde mit Ebene, Art und Schritt (Masterprompt-Abgleich B.4).
 */
final class CompletenessBefundeTest extends TestCase
{
    use RefreshDatabase;

    private function frisch(Listing $listing): Listing
    {
        return $listing->fresh(['price', 'energy', 'media']);
    }

    /**
     * @param  list<Befund>  $befunde
     */
    private function befund(array $befunde, string $feld): ?Befund
    {
        foreach ($befunde as $befund) {
            if ($befund->feld === $feld) {
                return $befund;
            }
        }

        return null;
    }

    public function test_ein_vollstaendiges_objekt_blockiert_nicht_und_hat_nur_informative_hinweise(): void
    {
        $listing = $this->frisch(Listing::factory()->vollstaendig()->create());
        $check = app(CompletenessCheck::class);

        self::assertFalse($check->blockiert($listing));
        self::assertTrue($check->check($listing)->istVollstaendig());

        foreach ($check->befunde($listing) as $befund) {
            self::assertSame(PruefArt::Hinweis, $befund->art, $befund->feld);
            self::assertFalse($befund->bestaetigungspflichtig, $befund->feld);
        }

        self::assertSame([], $check->check($listing)->hinweise, 'Informative Hinweise verlangen im bisherigen Assistenten keine Bestätigung.');
    }

    public function test_befunde_tragen_ebene_art_und_schritt(): void
    {
        $listing = $this->frisch(Listing::factory()->create(['titel' => null, 'strasse' => null]));
        $befunde = app(CompletenessCheck::class)->befunde($listing);

        $titel = $this->befund($befunde, 'titel');
        self::assertNotNull($titel);
        self::assertSame(PruefEbene::Intern, $titel->ebene);
        self::assertSame(PruefArt::Blockierend, $titel->art);
        self::assertSame(7, $titel->schritt);

        self::assertSame(2, $this->befund($befunde, 'strasse')?->schritt);
        self::assertSame(4, $this->befund($befunde, 'preis.kaltmiete_cent')?->schritt);
        self::assertSame(5, $this->befund($befunde, 'energie.status')?->schritt);
        self::assertSame(6, $this->befund($befunde, 'medien.bild')?->schritt);
        self::assertSame(8, $this->befund($befunde, 'beschreibung_objekt')?->schritt);
        self::assertSame(1, $this->befund($befunde, 'ansprechpartner_user_id')?->schritt);
        self::assertTrue(app(CompletenessCheck::class)->blockiert($listing));

        $array = $titel->toArray();
        self::assertSame(['feld', 'label', 'ebene', 'art', 'meldung', 'schritt', 'bestaetigungspflichtig'], array_keys($array));
        self::assertSame('intern', $array['ebene']);
    }

    public function test_provisionspflichtig_verlangt_die_bestaetigung(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->price->update([
            'provision_typ' => ProvisionTyp::Provisionspflichtig,
            'provision_text' => '3,57 % inkl. MwSt.',
            'provision_bestaetigt' => false,
        ]);

        $befund = $this->befund(app(CompletenessCheck::class)->befunde($this->frisch($listing)), 'preis.provision_bestaetigt');

        self::assertNotNull($befund);
        self::assertSame(PruefArt::Blockierend, $befund->art);
        self::assertSame(PruefEbene::Intern, $befund->ebene);
        self::assertSame(4, $befund->schritt);
        self::assertArrayHasKey('preis.provision_bestaetigt', app(CompletenessCheck::class)->check($this->frisch($listing))->fehlend);

        $listing->price->update(['provision_bestaetigt' => true]);

        self::assertTrue(app(CompletenessCheck::class)->check($this->frisch($listing))->istVollstaendig());
    }

    public function test_provisionsfrei_verlangt_keine_bestaetigung(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->price->update(['provision_typ' => ProvisionTyp::Provisionsfrei, 'provision_bestaetigt' => false]);

        self::assertNull($this->befund(app(CompletenessCheck::class)->befunde($this->frisch($listing)), 'preis.provision_bestaetigt'));
    }

    public function test_stellplatz_optional_und_pflicht_zusaetzlich_verlangen_einen_betrag(): void
    {
        foreach ([StellplatzModus::Optional, StellplatzModus::PflichtZusaetzlich] as $modus) {
            $listing = Listing::factory()->vollstaendig()->create();
            $listing->price->update(['stellplatz_modus' => $modus, 'stellplatz_miete_cent' => null]);

            $befund = $this->befund(app(CompletenessCheck::class)->befunde($this->frisch($listing)), 'preis.stellplatz_miete_cent');
            self::assertNotNull($befund, $modus->value);
            self::assertSame(PruefArt::Blockierend, $befund->art);

            $listing->price->update(['stellplatz_miete_cent' => 5_000]);
            self::assertFalse(app(CompletenessCheck::class)->blockiert($this->frisch($listing)), $modus->value);
        }
    }

    public function test_stellplatz_kauf_verlangt_den_stellplatzkaufpreis(): void
    {
        $listing = Listing::factory()->kauf()->vollstaendig()->create();
        $listing->price->update(['stellplatz_modus' => StellplatzModus::Optional, 'stellplatz_kaufpreis_cent' => null]);

        self::assertNotNull($this->befund(app(CompletenessCheck::class)->befunde($this->frisch($listing)), 'preis.stellplatz_kaufpreis_cent'));

        $listing->price->update(['stellplatz_kaufpreis_cent' => 15_000_00]);

        self::assertFalse(app(CompletenessCheck::class)->blockiert($this->frisch($listing)));
    }

    public function test_pflicht_enthalten_mit_betrag_ist_blockierend_und_ohne_betrag_zulaessig(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->price->update(['stellplatz_modus' => StellplatzModus::PflichtEnthalten, 'stellplatz_miete_cent' => 5_000]);

        $befund = $this->befund(app(CompletenessCheck::class)->befunde($this->frisch($listing)), 'preis.stellplatz_widerspruch');
        self::assertNotNull($befund);
        self::assertSame(PruefArt::Blockierend, $befund->art);

        $listing->price->update(['stellplatz_miete_cent' => null]);

        self::assertFalse(app(CompletenessCheck::class)->blockiert($this->frisch($listing)));
    }

    public function test_stellplatzbetrag_ohne_modus_ist_nur_ein_hinweis(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->price->update(['stellplatz_modus' => StellplatzModus::Keiner, 'stellplatz_miete_cent' => 5_000]);

        $check = app(CompletenessCheck::class);
        $befund = $this->befund($check->befunde($this->frisch($listing)), 'preis.stellplatz_modus');

        self::assertNotNull($befund);
        self::assertSame(PruefArt::Hinweis, $befund->art);
        self::assertFalse($check->blockiert($this->frisch($listing)));
    }

    public function test_vermietet_beim_verkauf_ergibt_einen_bestaetigungspflichtigen_hinweis(): void
    {
        $listing = Listing::factory()->vermietetZumVerkauf()->vollstaendig()->create();
        $check = app(CompletenessCheck::class);

        $befund = $this->befund($check->befunde($this->frisch($listing)), 'nutzungsstatus');
        self::assertNotNull($befund);
        self::assertSame(PruefArt::Hinweis, $befund->art);
        self::assertSame(1, $befund->schritt);
        self::assertTrue($befund->bestaetigungspflichtig);
        self::assertContains(CompletenessCheck::HINWEIS_VERMIETET, $check->check($this->frisch($listing))->hinweise);
        self::assertSame(Nutzungsstatus::Vermietet, $listing->fresh()->nutzungsstatus);
        self::assertSame(9_600_00, $listing->price->fresh()->mieteinnahmen_ist_cent);
    }

    public function test_unbekannte_merkmale_sind_nur_ein_hinweis(): void
    {
        $listing = Listing::factory()->vollstaendig()->create(['ausstattung' => ['balkon' => 'ja', 'keller' => 'nein']]);
        $check = app(CompletenessCheck::class);

        $befund = $this->befund($check->befunde($this->frisch($listing)), 'ausstattung');

        self::assertNotNull($befund);
        self::assertSame(PruefArt::Hinweis, $befund->art);
        self::assertSame(5, $befund->schritt);
        self::assertStringContainsString('Terrasse', $befund->meldung);
        self::assertStringNotContainsString('Balkon', $befund->meldung);
        self::assertStringNotContainsString('Keller', $befund->meldung);
        self::assertTrue($check->check($this->frisch($listing))->istVollstaendig());
    }

    public function test_ein_nicht_freigegebenes_bild_zaehlt_nicht_und_dokumente_werden_ignoriert(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->media()->update(['freigegeben' => false]);
        ListingMedia::factory()->dokument()->freigegeben()->create(['listing_id' => $listing->id, 'sortierung' => 1]);

        $check = app(CompletenessCheck::class);
        $befund = $this->befund($check->befunde($this->frisch($listing)), 'medien.bild');

        self::assertNotNull($befund);
        self::assertSame(PruefArt::Blockierend, $befund->art);
        self::assertSame(6, $befund->schritt);

        $listing->media()->where('typ', MediaTyp::Bild->value)->update(['freigegeben' => true]);

        self::assertFalse($check->blockiert($this->frisch($listing)));
    }

    public function test_gewerbe_verlangt_gewerbe_oder_nutzflaeche_statt_wohnflaeche_und_zimmer(): void
    {
        $listing = Listing::factory()->gewerbe()->vollstaendig()->create();
        $check = app(CompletenessCheck::class);

        self::assertFalse($check->blockiert($this->frisch($listing)));

        $listing->update(['gewerbeflaeche_qm' => null, 'nutzflaeche_qm' => null]);
        $felder = array_keys($check->check($this->frisch($listing))->fehlend);

        self::assertContains('gewerbeflaeche_qm', $felder);
        self::assertNotContains('wohnflaeche_qm', $felder);
        self::assertNotContains('zimmer', $felder);

        $listing->update(['nutzflaeche_qm' => 90]);
        self::assertFalse($check->blockiert($this->frisch($listing)), 'Die Nutzfläche des bisherigen Assistenten genügt.');
    }

    public function test_grundstueck_verlangt_die_grundstuecksflaeche_und_keine_energieangaben(): void
    {
        $listing = Listing::factory()->grundstueck()->vollstaendig()->create();
        $check = app(CompletenessCheck::class);

        self::assertFalse($check->blockiert($this->frisch($listing)));

        $listing->update(['grundstuecksflaeche_qm' => null]);
        $listing->energy()->delete();

        $felder = array_keys($check->check($this->frisch($listing))->fehlend);
        self::assertSame(['grundstuecksflaeche_qm'], $felder);
    }

    public function test_mehrfamilienhaus_verlangt_wohnflaeche_und_grundstueck_aber_keine_zimmer(): void
    {
        $listing = Listing::factory()->mehrfamilienhaus()->vollstaendig()->create();
        $check = app(CompletenessCheck::class);

        self::assertFalse($check->blockiert($this->frisch($listing)));

        $listing->update(['wohnflaeche_qm' => null, 'grundstuecksflaeche_qm' => null, 'zimmer' => null]);
        $felder = array_keys($check->check($this->frisch($listing))->fehlend);

        self::assertSame(['wohnflaeche_qm', 'grundstuecksflaeche_qm'], $felder);
    }

    public function test_stellplatz_verlangt_weder_flaechen_noch_energieangaben(): void
    {
        $listing = Listing::factory()->stellplatz()->vollstaendig()->create();
        $listing->energy()->delete();

        self::assertFalse(app(CompletenessCheck::class)->blockiert($this->frisch($listing)));
    }

    public function test_miete_ohne_heizkostenstruktur_und_ohne_versorgung_ist_blockiert(): void
    {
        $listing = Listing::factory()->vollstaendig()->create(['heizkosten_versorgung' => null]);
        $listing->price->update(['heizkosten_struktur' => null]);

        $befund = $this->befund(app(CompletenessCheck::class)->befunde($this->frisch($listing)), 'heizkosten_versorgung');

        self::assertNotNull($befund);
        self::assertSame(4, $befund->schritt);
        self::assertSame(PruefArt::Blockierend, $befund->art);
    }

    public function test_gesetzliche_energiebefunde_werden_in_die_pruefung_uebernommen(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->energy->update(['status' => 'beauftragt']);

        $check = app(CompletenessCheck::class);
        $befund = $this->befund($check->befunde($this->frisch($listing)), 'energie.status');

        self::assertNotNull($befund);
        self::assertSame(PruefEbene::Gesetzlich, $befund->ebene);
        self::assertTrue($check->blockiert($this->frisch($listing)));
        self::assertArrayHasKey('energie.status', $check->check($this->frisch($listing))->fehlend);
    }
}
