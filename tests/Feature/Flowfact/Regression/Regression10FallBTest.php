<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\PortalStatusTransition;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingPortalPublication;
use App\Models\ListingPortalStatusLog;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;

/**
 * Masterprompt Abschnitt 20 und 21, Masterprompt-Abgleich B.6 und B.8:
 * Fall B (fehlendes Veröffentlichungsrecht) wird als manuelle Freigabe
 * angezeigt, nicht als Fehler und nicht als Erfolg; Fall C liefert das
 * Ergebnis je Portal; jeder Statuswechsel trägt eine Nachweisquelle.
 */
final class Regression10FallBTest extends FlowfactTestCase
{
    private const string PORTALS = '#^/portal-management-service/portals$#';

    private const string PUBLISH = '#^/portal-management-service/publish$#';

    private const string ESTATE_PORTALS = '#^/portal-management-service/estates/[^/]+/portals$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
    }

    private function uebertragenesListing(): Listing
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $release = $this->freigeben($listing, ['portal-is24', 'portal-openimmo']);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
            'release_id' => $release->id,
        ]);

        return $listing;
    }

    public function test_401_auf_publish_setzt_manuelle_freigabe_und_spaeteres_ruecklesen_setzt_aktiv(): void
    {
        $listing = $this->uebertragenesListing();
        $user = User::factory()->admin()->create();
        $online = [];
        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, ['message' => 'Unauthorized'], 401)
            ->on('GET', self::ESTATE_PORTALS, function () use (&$online) {
                return Http::response(self::estatePortalsResponse($online));
            })
            ->install();

        $ergebnis = app(FlowfactPublishingService::class)->publish($listing, ['portal-is24'], $user);

        self::assertTrue($ergebnis->ok, 'Fall B ist kein Fehler.');
        self::assertSame(0, $ergebnis->angefordert, 'Fall B ist kein Erfolg.');
        self::assertContains(FlowfactPublishingService::MELDUNG_MANUELLE_FREIGABE, $ergebnis->warnungen);
        self::assertSame(['ImmoScout24' => FlowfactPublishingService::ERGEBNIS_MANUELL], $ergebnis->jePortal);

        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::ManuelleFreigabeErforderlich, $publication->status);
        self::assertSame(FlowfactPublishingService::MELDUNG_MANUELLE_FREIGABE, $publication->letzter_fehler);
        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);

        // Der Benutzer schließt die Veröffentlichung in FLOWFACT ab: das Rücklesen zeigt onlineSince.
        $online = ['portal-is24'];
        app(FlowfactPublishingService::class)->refreshStatus($listing);

        $publication->refresh();
        self::assertSame(PortalStatus::Aktiv, $publication->status);
        self::assertNull($publication->letzter_fehler);
        self::assertNotNull($publication->bestaetigt_at);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);

        $logs = ListingPortalStatusLog::query()->where('publication_id', $publication->id)->orderBy('id')->get();
        self::assertSame(
            ['nicht_veroeffentlicht>angefordert', 'angefordert>manuelle_freigabe_erforderlich', 'manuelle_freigabe_erforderlich>aktiv'],
            $logs->map(fn (ListingPortalStatusLog $log): string => $log->von_status->value.'>'.$log->nach_status->value)->all(),
        );
        self::assertSame(PortalStatusTransition::QUELLE_PUBLISH_NICHT_AUTORISIERT, $logs[1]->nachweis_quelle);
        self::assertSame(PortalStatusTransition::QUELLE_RUECKLESEN_ONLINE, $logs[2]->nachweis_quelle);
        self::assertSame($user->id, $logs[1]->user_id);
        self::assertNotNull($logs[1]->release_id);
    }

    public function test_portals_without_access_rights_in_der_antwort_ist_fall_b(): void
    {
        $listing = $this->uebertragenesListing();
        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, ['entityId' => 'ent-1', 'targetStatus' => 'ONLINE', 'portalsWithoutAccessRights' => ['portal-is24'], 'succeededPublications' => [], 'failedPublications' => [], 'scheduledPublications' => []])
            ->install();

        $ergebnis = app(FlowfactPublishingService::class)->publish($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok);
        self::assertTrue($ergebnis->nurManuelleFreigabe());
        self::assertSame(PortalStatus::ManuelleFreigabeErforderlich, ListingPortalPublication::query()->first()->status);
        self::assertSame(PortalStatusTransition::QUELLE_PUBLISH_OHNE_PORTALRECHT, ListingPortalStatusLog::query()->latest('id')->first()->nachweis_quelle);
        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    /**
     * Fall C: je Portal ein eigenes Ergebnis, in der Meldung aufgelistet.
     */
    public function test_teilergebnis_je_portal_steht_in_der_meldung(): void
    {
        $listing = $this->uebertragenesListing();
        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, function (Request $request) {
                return (string) $request->data()['portalId'] === 'portal-openimmo'
                    ? Http::response(['message' => 'forbidden'], 403)
                    : Http::response('', 200);
            })
            ->install();

        $ergebnis = app(FlowfactPublishingService::class)->publish($listing, ['portal-is24', 'portal-openimmo']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $ergebnis->angefordert);
        self::assertSame(['Immowelt (OpenImmo)'], $ergebnis->manuelleFreigabe);
        self::assertStringContainsString('ImmoScout24: angefordert, Immowelt (OpenImmo): manuelle Freigabe erforderlich', $ergebnis->meldung);
        self::assertSame(PortalStatus::Angefordert, ListingPortalPublication::query()->where('portal_id', 'portal-is24')->first()->status);
        self::assertSame(PortalStatus::ManuelleFreigabeErforderlich, ListingPortalPublication::query()->where('portal_id', 'portal-openimmo')->first()->status);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status, 'Ein erfolgreich angefordertes Portal genügt.');
    }

    /**
     * Masterprompt Abschnitt 21: Jeder Statuswechsel schreibt genau einen
     * Nachweis mit Quelle, Zeitpunkt, Freigabeversion und Benutzer.
     */
    public function test_jeder_statuswechsel_schreibt_einen_nachweis(): void
    {
        $listing = $this->uebertragenesListing();
        $user = User::factory()->admin()->create();
        $online = [];
        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, function () use (&$online) {
                return Http::response(self::estatePortalsResponse($online));
            })
            ->install();
        $service = app(FlowfactPublishingService::class);

        $service->publish($listing, ['portal-is24'], $user);          // nicht_veroeffentlicht -> angefordert
        $online = ['portal-is24'];
        $service->refreshStatus($listing);                             // angefordert -> aktiv
        $service->refreshStatus($listing);                             // unverändert: kein Nachweis
        $service->withdraw($listing->fresh(), ['portal-is24'], $user); // aktiv -> deaktivierung_angefordert
        $online = [];
        $service->refreshStatus($listing);                             // deaktivierung_angefordert -> deaktivierung_bestaetigt

        $logs = ListingPortalStatusLog::query()->where('listing_id', $listing->id)->orderBy('id')->get();

        self::assertCount(4, $logs);
        self::assertSame(
            [
                [null, 'angefordert', PortalStatusTransition::QUELLE_PUBLISH_ANGEFORDERT],
                ['angefordert', 'aktiv', PortalStatusTransition::QUELLE_RUECKLESEN_ONLINE],
                ['aktiv', 'deaktivierung_angefordert', PortalStatusTransition::QUELLE_OFFLINE_ANGEFORDERT],
                ['deaktivierung_angefordert', 'deaktivierung_bestaetigt', PortalStatusTransition::QUELLE_RUECKLESEN_OHNE_EINTRAG],
            ],
            $logs->map(fn (ListingPortalStatusLog $log): array => [
                $log->von_status?->value === 'nicht_veroeffentlicht' ? null : $log->von_status?->value,
                $log->nach_status->value,
                $log->nachweis_quelle,
            ])->all(),
        );

        foreach ($logs as $log) {
            self::assertNotNull($log->nachweis_at);
            self::assertNotNull($log->release_id, 'Jeder Nachweis trägt die Freigabeversion.');
            self::assertSame('portal-is24', $log->portal_id);
        }

        self::assertSame($user->id, $logs[0]->user_id);
        self::assertNull($logs[1]->user_id, 'Rücklesen erfolgt ohne Benutzer.');
        self::assertSame(ListingStatus::Zurueckgezogen, $listing->fresh()->status);
    }
}
