<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Client\Exceptions\ServerException;
use App\Flowfact\Sync\Jobs\PublishListingJob;
use App\Flowfact\Sync\Jobs\TransferListingJob;
use App\Flowfact\Sync\ListingSyncService;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;

final class TransferJobsTest extends FlowfactTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
    }

    private function listing(): Listing
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['im_inserat' => false]);

        return $listing->fresh(['price', 'energy', 'media']);
    }

    private function queueJob(): Job
    {
        return Mockery::mock(Job::class);
    }

    public function test_job_konfiguration_entspricht_dem_entwurf(): void
    {
        $job = new TransferListingJob(1);

        self::assertSame(3, $job->tries);
        self::assertSame([30, 120, 300], $job->backoff);
        self::assertSame(3, (new PublishListingJob(1, []))->tries);
        self::assertSame([30, 120, 300], (new PublishListingJob(1, []))->backoff);
    }

    public function test_erfolgreiche_uebertragung_beendet_den_job(): void
    {
        $listing = $this->listing();
        $this->fake()
            ->on('POST', '#^/search-service/schemas/[^/]+$#', self::searchResponse([]))
            ->on('POST', '#^/entity-service/schemas/[^/]+$#', self::entityResponse('ent-1'))
            ->install();

        $job = new TransferListingJob($listing->id);
        $queueJob = $this->queueJob();
        $queueJob->shouldNotReceive('release');
        $queueJob->shouldNotReceive('fail');
        $job->setJob($queueJob);

        $job->handle(app(ListingSyncService::class));

        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink()->first()->sync_status);
    }

    public function test_ratenbegrenzung_reiht_den_job_mit_retry_after_neu_ein(): void
    {
        $listing = $this->listing();
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'slow'], 429, ['Retry-After' => '90'])]);

        $job = new TransferListingJob($listing->id);
        $queueJob = $this->queueJob();
        $queueJob->shouldReceive('release')->once()->with(90);
        $queueJob->shouldReceive('isReleased')->andReturn(true);
        $job->setJob($queueJob);

        $job->handle(app(ListingSyncService::class));
    }

    public function test_auth_fehler_laesst_den_job_sofort_endgueltig_scheitern(): void
    {
        $listing = $this->listing();
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'nope'], 401)]);

        $job = new TransferListingJob($listing->id);
        $queueJob = $this->queueJob();
        $queueJob->shouldReceive('fail')->once()->withArgs(function (\Throwable $e): bool {
            return str_contains($e->getMessage(), 'Token ungültig oder Rechte fehlen') && ! str_contains($e->getMessage(), self::TOKEN);
        });
        $queueJob->shouldNotReceive('release');
        $job->setJob($queueJob);

        $job->handle(app(ListingSyncService::class));

        self::assertSame(SyncStatus::Fehlgeschlagen, $listing->flowfactLink()->first()->sync_status);
    }

    public function test_serverfehler_wird_fuer_die_wiederholung_mit_backoff_geworfen(): void
    {
        $listing = $this->listing();
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'down'], 500)]);

        $job = new TransferListingJob($listing->id);

        $this->expectException(ServerException::class);

        $job->handle(app(ListingSyncService::class));
    }

    public function test_fachlicher_fehler_wird_nicht_wiederholt(): void
    {
        $listing = $this->listing();
        $this->settings()->forget(ListingSyncService::SCHEMA_MIETE);
        Http::fake();

        $job = new TransferListingJob($listing->id);
        $queueJob = $this->queueJob();
        $queueJob->shouldReceive('fail')->once();
        $job->setJob($queueJob);

        $job->handle(app(ListingSyncService::class));
    }

    public function test_publish_job_fordert_die_veroeffentlichung_an_ohne_aktiv_zu_setzen(): void
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['flowfact_multimedia_id' => '101']);
        $this->fake()
            ->on('POST', '#^/search-service/schemas/[^/]+$#', self::searchResponse([]))
            ->on('POST', '#^/entity-service/schemas/[^/]+$#', self::entityResponse('ent-1'))
            ->on('GET', '#^/portal-management-service/portals$#', self::portalsResponse())
            ->on('POST', '#^/portal-management-service/publish$#', fn () => Http::response('', 200))
            ->install();

        (new PublishListingJob($listing->id, ['portal-is24']))->handle(app(PublishingService::class));

        self::assertSame(PortalStatus::Angefordert, $listing->portalPublications()->first()->status);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/estates/'));
    }
}
