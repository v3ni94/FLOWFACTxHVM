<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Enums\AdressFreigabe;
use App\Enums\GewerbeUnterart;
use App\Enums\ListingStatus;
use App\Enums\Nutzungsstatus;
use App\Enums\SyncStatus;
use App\Enums\Waermeabgabe;
use App\Enums\Warmwasserbereitung;
use App\Flowfact\Mapping\FieldCatalog;
use App\Flowfact\Mapping\FieldMappingResolver;
use App\Flowfact\Mapping\FlowfactPayloadMapper;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;

/**
 * Masterprompt-Abgleich B.2 (Welle 3): Zuordnung der neuen Objektfelder.
 * Stellplatz/Garage ohne FLOWFACT-Code wird nicht übertragen, bis der Code
 * über flowfact.codezuordnung hinterlegt ist.
 */
final class Regression14ObjektartCodesTest extends FlowfactTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
    }

    private function mapper(): FlowfactPayloadMapper
    {
        return app(FlowfactPayloadMapper::class);
    }

    public function test_mehrfamilienhaus_und_grundstueck_haben_bestaetigte_codes(): void
    {
        $mfh = Listing::factory()->kauf()->mehrfamilienhaus()->mitPreisen()->create()->fresh(['price', 'energy', 'media']);
        $grundstueck = Listing::factory()->kauf()->grundstueck()->mitPreisen()->create()->fresh(['price', 'energy', 'media']);

        self::assertSame(['values' => ['02MFH']], $this->mapper()->map($mfh)->fields['estatetype']);
        self::assertSame(['values' => ['03BE']], $this->mapper()->map($grundstueck)->fields['estatetype']);
    }

    public function test_gewerbe_unterart_bestimmt_den_code(): void
    {
        $buero = Listing::factory()->miete()->gewerbe(GewerbeUnterart::Buero)->mitPreisen()->create()->fresh(['price', 'energy', 'media']);
        $laden = Listing::factory()->miete()->gewerbe(GewerbeUnterart::Laden)->mitPreisen()->create()->fresh(['price', 'energy', 'media']);
        $lager = Listing::factory()->miete()->gewerbe(GewerbeUnterart::Lager)->mitPreisen()->create()->fresh(['price', 'energy', 'media']);
        $ohne = Listing::factory()->miete()->gewerbe()->mitPreisen()->create(['gewerbe_unterart' => null])->fresh(['price', 'energy', 'media']);

        self::assertSame(['values' => ['06B']], $this->mapper()->map($buero)->fields['estatetype']);
        self::assertSame(['values' => ['05L']], $this->mapper()->map($laden)->fields['estatetype']);
        self::assertSame(['values' => ['06B']], $this->mapper()->map($ohne)->fields['estatetype'], 'Ohne Unterart bleibt Bürofläche der Standard.');

        $lagerPayload = $this->mapper()->map($lager);
        self::assertArrayNotHasKey('estatetype', $lagerPayload->fields);
        self::assertContains(FieldCatalog::WARNUNG_LAGER, $lagerPayload->warnungen);
        self::assertNull($lagerPayload->blockiert, 'Lagerfläche wird mit Warnung übertragen, nicht gesperrt.');

        // Gewerbefläche hat Vorrang auf commercialarea.
        self::assertSame(['values' => [120]], $this->mapper()->map($buero)->fields['commercialarea']);
    }

    public function test_nutzungsstatus_wird_als_let_uebertragen(): void
    {
        $vermietet = Listing::factory()->kauf()->mitPreisen()->create(['nutzungsstatus' => Nutzungsstatus::Vermietet])->fresh(['price', 'energy', 'media']);
        $leer = Listing::factory()->kauf()->mitPreisen()->create(['nutzungsstatus' => Nutzungsstatus::Leerstehend])->fresh(['price', 'energy', 'media']);
        $unbekannt = Listing::factory()->kauf()->mitPreisen()->create(['nutzungsstatus' => Nutzungsstatus::Unbekannt])->fresh(['price', 'energy', 'media']);
        $belegt = Listing::factory()->kauf()->mitPreisen()->create(['nutzungsstatus' => Nutzungsstatus::AnderweitigBelegt])->fresh(['price', 'energy', 'media']);

        self::assertSame(['values' => [true]], $this->mapper()->map($vermietet)->fields['let']);
        self::assertSame(['values' => [false]], $this->mapper()->map($leer)->fields['let']);
        self::assertArrayNotHasKey('let', $this->mapper()->map($unbekannt)->fields);
        self::assertArrayNotHasKey('let', $this->mapper()->map($belegt)->fields);
        self::assertNotContains('let', $this->mapper()->map($unbekannt)->leereFelder, 'Unbekannt löscht nichts.');
    }

    public function test_merkmale_dreiwertig_mit_altem_schluessel_barrierefrei(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->create([
            'ausstattung' => ['balkon' => 'ja', 'keller' => 'nein', 'aufzug' => 'unbekannt', 'barrierefrei' => true, 'gaeste_wc' => 'unbekannt'],
        ])->fresh(['price', 'energy', 'media']);

        $fields = $this->mapper()->map($listing)->fields;

        self::assertSame(['values' => [true]], $fields['balconyavailable']);
        self::assertSame(['values' => [false]], $fields['cellar']);
        self::assertArrayNotHasKey('elevator', $fields, 'Unbekannt wird weder als ja noch als nein übertragen.');
        self::assertArrayNotHasKey('guesttoilet', $fields);
        self::assertSame(['values' => [true]], $fields['barrierfree'], 'Der ältere Schlüssel barrierefrei wird für barrierearm gelesen.');
    }

    public function test_neue_heizungs_und_modernisierungsfelder_erzeugen_nur_warnungen(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->create([
            'modernisierungsjahr' => 2019,
            'heizung_waermeabgabe' => Waermeabgabe::Fussbodenheizung,
            'heizung_warmwasser' => Warmwasserbereitung::Zentral,
        ])->fresh(['price', 'energy', 'media']);

        $payload = $this->mapper()->map($listing);

        self::assertContains('Keine FLOWFACT-Zuordnung für Modernisierungsjahr', $payload->warnungen);
        self::assertContains('Keine FLOWFACT-Zuordnung für Wärmeabgabe', $payload->warnungen);
        self::assertContains('Keine FLOWFACT-Zuordnung für Warmwasserbereitung', $payload->warnungen);
        self::assertNull($payload->blockiert);
    }

    public function test_adressfreigabe_steuert_show_address_und_die_strasse_bleibt_im_payload(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->create([
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'strasse' => 'Kölner Straße',
            'hausnummer' => '12',
        ])->fresh(['price', 'energy', 'media']);

        $payload = $this->mapper()->map($listing);

        self::assertFalse($payload->showAddress);
        self::assertSame('Kölner Straße 12', $payload->fields['addresses']['values'][0]['street']);
    }

    public function test_stellplatz_ohne_code_wird_nicht_uebertragen(): void
    {
        Http::fake();
        $listing = Listing::factory()->miete()->stellplatz()->vollstaendig()->create(['status' => ListingStatus::Bereit]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertFalse($ergebnis->ok);
        self::assertSame(FieldCatalog::MELDUNG_STELLPLATZ, $ergebnis->meldung);
        self::assertSame(SyncStatus::Fehlgeschlagen, $listing->flowfactLink()->first()->sync_status);
        self::assertSame(FieldCatalog::MELDUNG_STELLPLATZ, $listing->flowfactLink()->first()->letzter_fehler);
        Http::assertNothingSent();
    }

    public function test_stellplatz_mit_hinterlegtem_code_wird_uebertragen(): void
    {
        $this->settings()->set(FieldMappingResolver::CODEZUORDNUNG, ['objektart.stellplatz' => '09STELL']);
        $listing = Listing::factory()->miete()->stellplatz()->vollstaendig()->create(['status' => ListingStatus::Bereit]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);

        $fake = $this->fake()
            ->on('POST', '#^/search-service/schemas/[^/]+$#', self::searchResponse([]))
            ->on('POST', '#^/entity-service/schemas/[^/]+$#', self::entityResponse('ent-stellplatz'))
            ->on('GET', '#^/multimedia-service/items/entities/[^/]+$#', [self::multimediaItem(101)])
            ->on('PUT', '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#', ['assignments' => []])
            ->install();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(['values' => ['09STELL']], $fake->requests('POST', '#^/entity-service/schemas/[^/]+$#')[0]->data()['estatetype']);
    }
}
