<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Enums\Objektart;
use App\Enums\StellplatzTyp;
use App\Enums\Zustand;
use App\Flowfact\Mapping\FieldMappingResolver;
use App\Flowfact\Mapping\FlowfactPayloadMapper;
use App\Models\Listing;
use App\Models\ListingInternal;

final class FlowfactPayloadMapperTest extends FlowfactTestCase
{
    private function mapper(): FlowfactPayloadMapper
    {
        return app(FlowfactPayloadMapper::class);
    }

    public function test_werteform_codes_adresse_und_betraege(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->mitEnergieausweis()->create([
            'titel' => 'Helle 3-Zimmer-Wohnung',
            'strasse' => 'Kölner Straße',
            'hausnummer' => '12a',
            'plz' => '41812',
            'ort' => 'Erkelenz',
            'land' => 'DE',
            'stellplatz_typ' => StellplatzTyp::Tiefgarage,
            'zustand' => Zustand::Gepflegt,
            'adresse_im_inserat_anzeigen' => false,
        ])->fresh(['price', 'energy']);

        $payload = $this->mapper()->map($listing);
        $f = $payload->fields;

        self::assertSame(['values' => ['Helle 3-Zimmer-Wohnung']], $f['headline']);
        self::assertSame(['values' => [$listing->objektnummer]], $f['identifier']);
        self::assertSame(['values' => ['01ETAG']], $f['estatetype']);
        self::assertSame(['values' => ['active']], $f['status']);
        self::assertSame(['values' => ['07']], $f['condition']);
        self::assertSame(['values' => ['7']], $f['parking']);
        self::assertSame(['values' => ['04']], $f['energyefficienceclass']);
        self::assertSame(['values' => [800.0]], $f['rent']);
        self::assertSame(['values' => [65]], $f['livingarea']);
        self::assertSame(['values' => [3]], $f['rooms']);
        self::assertSame(['values' => [2]], $f['numberbedrooms']);
        self::assertSame(['values' => [1998]], $f['yearofconstruction']);
        self::assertSame(['values' => [true]], $f['balconyavailable']);
        self::assertSame(['values' => [true]], $f['cellar']);
        self::assertSame(['values' => [false]], $f['elevator']);
        self::assertSame(['values' => [false]], $f['guesttoilet']);
        self::assertSame(['values' => [[
            'type' => 'private',
            'street' => 'Kölner Straße 12a',
            'zipcode' => '41812',
            'city' => 'Erkelenz',
            'country' => 'Deutschland',
        ]]], $f['addresses']);

        // Adresse wird trotzdem übertragen, nur das Portal-Flag ist aus.
        self::assertFalse($payload->showAddress);
        self::assertArrayNotHasKey('purchaseprice', $f);
        self::assertArrayNotHasKey('contact', $f);
    }

    public function test_kaufpreis_als_euro_mit_zwei_dezimalstellen(): void
    {
        $listing = Listing::factory()->kauf()->mitPreisen()->create()->fresh(['price', 'energy']);
        $listing->price->update(['kaufpreis_cent' => 34_900_099]);

        $payload = $this->mapper()->map($listing->fresh(['price', 'energy']));

        self::assertSame(['values' => [349000.99]], $payload->fields['purchaseprice']);
        self::assertArrayNotHasKey('rent', $payload->fields);
    }

    public function test_fehlende_zuordnung_erzeugt_warnung_mit_deutschem_label(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->create([
            'beschreibung_objekt' => 'Schöne Wohnung.',
        ])->fresh(['price', 'energy']);

        $payload = $this->mapper()->map($listing);

        self::assertContains('Keine FLOWFACT-Zuordnung für Nebenkosten', $payload->warnungen);
        self::assertContains('Keine FLOWFACT-Zuordnung für Objektbeschreibung', $payload->warnungen);
        self::assertContains('Keine FLOWFACT-Zuordnung für Kaution', $payload->warnungen);
        self::assertNotContains('Keine FLOWFACT-Zuordnung für Ansprechpartner', $payload->warnungen);
        self::assertNotContains('Keine FLOWFACT-Zuordnung für UUID', $payload->warnungen);
    }

    public function test_fehlender_code_erzeugt_warnung_und_laesst_das_feld_aus(): void
    {
        $listing = Listing::factory()->miete()->create([
            'objektart' => Objektart::Stellplatz,
            'wohnflaeche_qm' => null,
            'zimmer' => null,
        ])->fresh(['price', 'energy']);

        $payload = $this->mapper()->map($listing);

        self::assertArrayNotHasKey('estatetype', $payload->fields);
        self::assertContains('Kein FLOWFACT-Code für Objektart (Stellplatz)', $payload->warnungen);
    }

    public function test_einstellungen_ueberschreiben_feld_und_codezuordnung(): void
    {
        $this->settings()->set(FieldMappingResolver::FELDZUORDNUNG, [
            'beschreibung_objekt' => 'description',
            'nebenkosten_cent' => 'additionalcosts',
            'titel' => null,
        ]);
        $this->settings()->set(FieldMappingResolver::CODEZUORDNUNG, [
            'objektart.stellplatz' => '09STELL',
            'zustand.gepflegt' => null,
        ]);

        $listing = Listing::factory()->miete()->mitPreisen()->create([
            'objektart' => Objektart::Stellplatz,
            'beschreibung_objekt' => 'Text',
            'zustand' => Zustand::Gepflegt,
        ])->fresh(['price', 'energy']);

        $payload = $this->mapper()->map($listing);

        self::assertSame(['values' => ['Text']], $payload->fields['description']);
        self::assertSame(['values' => [200.0]], $payload->fields['additionalcosts']);
        self::assertSame(['values' => ['09STELL']], $payload->fields['estatetype']);
        self::assertArrayNotHasKey('headline', $payload->fields);
        self::assertArrayNotHasKey('condition', $payload->fields);
        self::assertContains('Kein FLOWFACT-Code für Zustand (Gepflegt)', $payload->warnungen);
        self::assertNotContains('Keine FLOWFACT-Zuordnung für Nebenkosten', $payload->warnungen);
    }

    /**
     * Prüfbericht 2026-09-11, Befund 13.
     */
    public function test_heizkosten_gehen_bei_enthalten_in_nebenkosten_nicht_separat_an_flowfact(): void
    {
        $this->settings()->set(FieldMappingResolver::FELDZUORDNUNG, [
            'nebenkosten_cent' => 'servicecharge',
            'heizkosten_cent' => 'heatingcosts',
            'warmmiete_cent' => 'totalrent',
        ]);

        $listing = Listing::factory()->miete()->create();
        $listing->price()->create([
            'kaltmiete_cent' => 80_000,
            'nebenkosten_cent' => 30_000,           // enthält bereits 10.000 Heizkosten (Fall B)
            'heizkosten_cent' => 10_000,
            'heizkosten_in_nebenkosten_enthalten' => true,
            'warmmiete_cent' => 110_000,
        ]);

        $payload = $this->mapper()->map($listing->fresh(['price', 'energy', 'media']));

        self::assertSame(800.0, $payload->fields['rent']['values'][0]);
        self::assertSame(300.0, $payload->fields['servicecharge']['values'][0]);
        self::assertSame(1100.0, $payload->fields['totalrent']['values'][0]);
        self::assertArrayNotHasKey('heatingcosts', $payload->fields, 'Heizkosten werden nicht zusätzlich separat gesendet.');
        self::assertContains(FlowfactPayloadMapper::WARNUNG_HEIZKOSTEN_ENTHALTEN, $payload->warnungen);
        self::assertContains('heatingcosts', $payload->leereFelder, 'Ein früher separat übertragener Wert wird beim PATCH gelöscht.');
    }

    /**
     * Prüfbericht 2026-09-11, Befund 13: bei getrennter Angabe (Fall A) gehen die Heizkosten weiterhin separat.
     */
    public function test_heizkosten_gehen_bei_getrennter_angabe_separat_an_flowfact(): void
    {
        $this->settings()->set(FieldMappingResolver::FELDZUORDNUNG, ['heizkosten_cent' => 'heatingcosts']);

        $listing = Listing::factory()->miete()->create();
        $listing->price()->create([
            'kaltmiete_cent' => 80_000,
            'nebenkosten_cent' => 20_000,
            'heizkosten_cent' => 10_000,
            'heizkosten_in_nebenkosten_enthalten' => false,
            'warmmiete_cent' => 110_000,
        ]);

        $payload = $this->mapper()->map($listing->fresh(['price', 'energy', 'media']));

        self::assertSame(['values' => [100.0]], $payload->fields['heatingcosts']);
        self::assertNotContains(FlowfactPayloadMapper::WARNUNG_HEIZKOSTEN_ENTHALTEN, $payload->warnungen);
    }

    /**
     * Prüfbericht 2026-09-11, Befund 5.
     */
    public function test_leere_zugeordnete_felder_werden_als_loeschbefehle_bereitgestellt(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->create([
            'baujahr' => null,
            'zustand' => null,
            'stellplatz_typ' => null,
        ])->fresh(['price', 'energy']);

        $payload = $this->mapper()->map($listing);

        self::assertArrayNotHasKey('yearofconstruction', $payload->fields);
        self::assertContains('yearofconstruction', $payload->leereFelder);
        self::assertContains('condition', $payload->leereFelder);
        self::assertContains('parking', $payload->leereFelder);
        self::assertNotContains('headline', $payload->leereFelder, 'Gesetzte Felder werden nicht gelöscht.');

        $patch = $payload->fieldsMitLoeschungen();
        self::assertSame(['values' => []], $patch['yearofconstruction']);
        self::assertSame($payload->fields['headline'], $patch['headline']);
        self::assertArrayNotHasKey('yearofconstruction', $payload->fields, 'Die Anlage bleibt frei von leeren Wertelisten.');
    }

    public function test_interne_markerwerte_erscheinen_nirgends_im_payload(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->mitEnergieausweis()->create();
        ListingInternal::factory()->create([
            'listing_id' => $listing->id,
            'eigentuemer_name' => 'INTERN-MARKER-XYZ Eigentümer',
            'eigentuemer_kontakt' => 'INTERN-MARKER-XYZ Kontakt',
            'verwaltungsobjekt_referenz' => 'INTERN-MARKER-XYZ Referenz',
            'interne_notizen' => 'INTERN-MARKER-XYZ Notiz',
            'schluessel_hinweis' => 'INTERN-MARKER-XYZ Schlüssel',
            'besichtigung_intern' => 'INTERN-MARKER-XYZ Besichtigung',
            'kalkulation_notiz' => 'INTERN-MARKER-XYZ Kalkulation',
        ]);

        $payload = $this->mapper()->map($listing->fresh(['price', 'energy', 'internal']));

        $json = json_encode([$payload->fields, $payload->warnungen], JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('INTERN-MARKER-XYZ', $json);
    }
}
