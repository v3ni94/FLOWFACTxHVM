<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Flowfact\Sync\Jobs\RefreshPortalStatusJob;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingPortalPublication;
use App\Models\TransferLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-008: Der Token "SECRET-TOKEN-ABC" darf nirgends auftauchen: nicht im
 * Übertragungsprotokoll, nicht im Log, nicht in Meldungen der Oberfläche.
 */
final class TokenLeakTest extends FlowfactTestCase
{
    public function test_token_erscheint_weder_im_protokoll_noch_im_log_noch_in_meldungen(): void
    {
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        Log::spy();

        // FLOWFACT spiegelt den Token in Fehlerkörpern zurück (Worst Case).
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'Unauthorized token '.self::TOKEN, 'header' => 'x-ff-api-token: '.self::TOKEN], 401)]);

        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Veroeffentlicht]);
        ListingFlowfactLink::factory()->create(['listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'flowfact_schema' => self::SCHEMA_MIETE]);
        ListingPortalPublication::factory()->create(['listing_id' => $listing->id, 'status' => PortalStatus::Angefordert, 'angefordert_at' => now()->subHour()]);
        $admin = User::factory()->admin()->create();

        $service = app(PublishingService::class);
        $transfer = $service->transfer($listing, $admin);
        $publish = $service->publish($listing->fresh(['price', 'energy', 'media']), ['portal-is24'], $admin);
        (new RefreshPortalStatusJob($listing->id))->handle($service);

        // Adminbereich: Verbindungstest und Seite
        $this->actingAs($admin)->post(route('admin.flowfact.test'));
        $seite = $this->actingAs($admin)->get(route('admin.flowfact.edit'));

        self::assertFalse($transfer->ok);
        self::assertStringNotContainsString(self::TOKEN, $transfer->meldung);
        self::assertStringNotContainsString(self::TOKEN, $publish->meldung);
        self::assertStringNotContainsString(self::TOKEN, (string) $listing->flowfactLink()->first()->letzter_fehler);
        self::assertStringNotContainsString(self::TOKEN, (string) $listing->portalPublications()->first()->letzter_fehler);
        self::assertStringNotContainsString(self::TOKEN, (string) $this->settings()->get('flowfact.verbindung_ergebnis'));
        self::assertStringNotContainsString(self::TOKEN, $seite->getContent());

        self::assertGreaterThan(0, TransferLog::query()->count());

        foreach (TransferLog::query()->get() as $log) {
            self::assertStringNotContainsString(self::TOKEN, json_encode($log->getAttributes(), JSON_THROW_ON_ERROR));
        }

        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug', 'log'] as $stufe) {
            Log::shouldNotHaveReceived($stufe, function (...$args): bool {
                return str_contains(json_encode($args, JSON_THROW_ON_ERROR), self::TOKEN);
            });
        }
    }
}
