<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\CompletenessCheck;
use App\Enums\AdressFreigabe;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Objektart;
use App\Enums\ProvisionTyp;
use App\Enums\PruefEbene;
use App\Enums\Vermarktungsart;
use App\Http\Controllers\App\Support\CompletenessFieldMap;
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

    /**
     * Prüfbericht 2026-09-11, Befund 2: Ein widersprüchlicher Preisdatensatz
     * (z. B. durch einen Wechsel der Heizkostenversorgung außerhalb von
     * Schritt 4 vor der Behebung) darf CompletenessCheck nie mit einer
     * unbehandelten InvalidRentInputException zum Absturz bringen. Stattdessen
     * wird das als fehlendes Feld gemeldet, damit die Oberfläche (Detailseite,
     * alle Schritte, Veröffentlichung) bedienbar bleibt.
     */
    public function test_ein_widerspruechlicher_preisdatensatz_wirft_nicht_sondern_meldet_ein_fehlendes_feld(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'vermarktungsart' => Vermarktungsart::Miete,
            'heizkosten_versorgung' => HeizkostenVersorgung::Dezentral,
        ]);

        // Nur über direktes Schreiben erreichbar: der RentCalculator selbst
        // verbietet Heizkosten bei dezentraler Versorgung.
        $listing->price->update([
            'heizkosten_cent' => 10_000,
            'heizkosten_in_nebenkosten_enthalten' => false,
        ]);

        $ergebnis = app(CompletenessCheck::class)->check($listing->fresh(['price', 'energy', 'media']));

        $this->assertFalse($ergebnis->istVollstaendig());
        $this->assertSame('Preisangaben widersprüchlich', $ergebnis->fehlend['preis.widerspruch'] ?? null);
        $this->assertSame(4, CompletenessFieldMap::schritt('preis.widerspruch'), 'Der Hinweis muss auf Schritt 4 verweisen, wo die Preise erneut gespeichert werden.');
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

    /**
     * Prüfbericht 2026-09-12, Befund 4: B.7 verlangt die Adressprüfung auch
     * für Bildtitel veröffentlichbarer Medien, nicht nur für Überschrift und
     * Beschreibungen.
     */
    public function test_ein_bildtitel_mit_strasse_blockiert_bei_eingeschraenkter_adressfreigabe(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'strasse' => 'Musterstraße', 'hausnummer' => '12',
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
        ]);
        $listing->media()->update(['titel' => 'Fassade Musterstraße 12']);

        $befunde = app(CompletenessCheck::class)->befunde($listing->fresh(['price', 'energy', 'media']));
        $medienTitelBefunde = array_values(array_filter($befunde, fn ($b) => $b->feld === 'medien.titel'));

        $this->assertNotSame([], $medienTitelBefunde, 'Ein Bildtitel mit Straße muss als Befund erscheinen.');
        $this->assertTrue($medienTitelBefunde[0]->istBlockierend());
        $this->assertSame(PruefEbene::Portal, $medienTitelBefunde[0]->ebene);

        $ergebnis = app(CompletenessCheck::class)->check($listing->fresh(['price', 'energy', 'media']));
        $this->assertArrayHasKey('medien.titel', $ergebnis->fehlend);
    }

    public function test_ein_bildtitel_mit_strasse_ist_unschaedlich_bei_vollstaendiger_adressfreigabe(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'strasse' => 'Musterstraße', 'hausnummer' => '12',
            'adress_freigabe' => AdressFreigabe::Vollstaendig,
        ]);
        $listing->media()->update(['titel' => 'Fassade Musterstraße 12']);

        $ergebnis = app(CompletenessCheck::class)->check($listing->fresh(['price', 'energy', 'media']));

        $this->assertArrayNotHasKey('medien.titel', $ergebnis->fehlend);
    }
}
