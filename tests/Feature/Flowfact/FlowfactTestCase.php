<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Domain\Listing\ReleaseService;
use App\Domain\Settings\SettingsRepository;
use App\Enums\ListingStatus;
use App\Enums\ReleaseAktion;
use App\Flowfact\Client\SettingsTokenProvider;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Flowfact\Support\FakeFlowfact;
use Tests\TestCase;

/**
 * Gemeinsame Basis der Connector-Tests: Token in den Einstellungen,
 * Http::preventStrayRequests(), Antwortformen aus flowfact-api.md.
 */
abstract class FlowfactTestCase extends TestCase
{
    use RefreshDatabase;

    protected const string TOKEN = 'SECRET-TOKEN-ABC';

    protected const string BASE = 'https://api.production.cloudios.flowfact-prod.cloud';

    protected const string SCHEMA_MIETE = 'wohnung_miete';

    protected const string SCHEMA_KAUF = 'haus_kauf';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('flowfact.base_url', self::BASE);
    }

    protected function settings(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }

    protected function hinterlegeToken(string $token = self::TOKEN): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, $token);
    }

    protected function setzeSchemata(): void
    {
        $this->settings()->set(ListingSyncService::SCHEMA_MIETE, self::SCHEMA_MIETE);
        $this->settings()->set(ListingSyncService::SCHEMA_KAUF, self::SCHEMA_KAUF);
    }

    protected function fake(): FakeFlowfact
    {
        return new FakeFlowfact;
    }

    protected function bereitesListing(array $attributes = []): Listing
    {
        $listing = Listing::factory()->miete()->vollstaendig()->create(array_merge([
            'status' => ListingStatus::Bereit,
        ], $attributes));

        return $listing->fresh(['price', 'energy', 'media']);
    }

    /**
     * Freigabeversion wie im Schritt Prüfen und veröffentlichen
     * (Masterprompt-Abgleich B.6): Übertragung und Veröffentlichung arbeiten
     * ausschließlich mit der jüngsten Version.
     *
     * @param  list<string>  $portalIds
     */
    protected function freigeben(Listing $listing, array $portalIds = ['portal-is24', 'portal-openimmo'], ?ReleaseAktion $aktion = null, ?User $user = null): ListingRelease
    {
        $aktion ??= $portalIds === [] ? ReleaseAktion::FlowfactSpeichern : ReleaseAktion::Veroeffentlichen;
        $user ??= User::factory()->create();

        $release = app(ReleaseService::class)->freigeben($listing->fresh(['price', 'energy', 'media']), $user, $aktion, $portalIds);

        $listing->unsetRelation('latestRelease');
        $listing->unsetRelation('releases');

        return $release;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected static function entityResponse(string $id, array $fields = [], string $schema = self::SCHEMA_MIETE): array
    {
        return array_merge([
            'id' => $id,
            '_metadata' => [
                'id' => $id,
                'schema' => $schema,
                'timestamp' => 1757590000000,
                'creator' => 'api-user',
                'createdTimestamp' => 1757590000000,
                'documentType' => 'ENTITY',
                'currentAccessLevel' => 'WRITE',
            ],
            '_acls' => [],
            '_refs' => [],
            '_acps' => [],
        ], $fields);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, mixed>
     */
    protected static function searchResponse(array $entries, ?int $totalCount = null): array
    {
        return [
            'entries' => $entries,
            'totalCount' => $totalCount ?? count($entries),
            'page' => 1,
            'offset' => 0,
            'size' => count($entries),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function presignedResponse(): array
    {
        return [
            'presignedUrl' => 'https://s3.eu-central-1.amazonaws.com/flowfact-media/upload/abc?X-Amz-Signature=deadbeef&X-Amz-Expires=900',
            'itemLink' => 's3://flowfact-media/upload/abc',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function multimediaItem(int $id, string $fileName = 'bild.jpg'): array
    {
        return [
            'id' => $id,
            'createdAt' => 1757590000000,
            'entityId' => 'ent-1',
            'schemaName' => self::SCHEMA_MIETE,
            'title' => null,
            'fileName' => $fileName,
            'contentType' => 'image/jpeg',
            'contentCategory' => 'IMAGE',
            'fileReference' => 's3://flowfact-media/upload/'.$id,
            'fileSize' => 1234,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function albumsResponse(): array
    {
        return [[
            'id' => 'album-1',
            'schema' => self::SCHEMA_MIETE,
            'name' => 'estate_album',
            'captions' => ['de' => 'Objektbilder'],
            'hidden' => false,
            'categories' => [
                ['id' => 'k-doc', 'name' => 'documents', 'captions' => ['de' => 'Dokumente'], 'sorting' => 1, 'allowedContentCategories' => ['DOCUMENT']],
                ['id' => 'k-img', 'name' => 'images', 'captions' => ['de' => 'Bilder'], 'sorting' => 0, 'allowedContentCategories' => ['IMAGE']],
            ],
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function portalsResponse(): array
    {
        return [
            ['id' => 'portal-is24', 'portalType' => 'IS24', 'authenticated' => true, 'fullUpdate' => false, 'name' => 'ImmoScout24'],
            ['id' => 'portal-openimmo', 'portalType' => 'OPENIMMO', 'authenticated' => true, 'fullUpdate' => true, 'name' => 'Immowelt (OpenImmo)'],
            ['id' => 'portal-inaktiv', 'portalType' => 'IS24', 'authenticated' => false, 'fullUpdate' => false, 'name' => 'Nicht verbunden'],
        ];
    }

    /**
     * @param  list<string>  $online
     * @param  list<string>  $ohneOnlineSince
     * @return list<array<string, mixed>>
     */
    protected static function estatePortalsResponse(array $online, array $ohneOnlineSince = []): array
    {
        $eintraege = [];

        foreach ($online as $portalId) {
            $eintraege[] = ['portalId' => $portalId, 'entityId' => 'ent-1', 'lastUpdate' => 1757590000000, 'onlineSince' => 1757590000000, 'showAddress' => true, 'channels' => []];
        }

        foreach ($ohneOnlineSince as $portalId) {
            $eintraege[] = ['portalId' => $portalId, 'entityId' => 'ent-1', 'lastUpdate' => 1757590000000, 'showAddress' => true, 'channels' => []];
        }

        return $eintraege;
    }

    /**
     * @param  list<string>  $transferiert  Portal-IDs
     * @param  array<string, string>  $fehler  Portal-ID => translatedMessage
     * @param  list<string>  $geplant
     * @return array<string, mixed>
     */
    protected static function publishResponse(string $entityId, array $transferiert = [], array $fehler = [], array $geplant = []): array
    {
        $eintrag = fn (string $portalId, array $extra = []): array => array_merge([
            'entityId' => $entityId,
            'externalId' => null,
            'portalId' => $portalId,
            'schema' => self::SCHEMA_MIETE,
            'schemaId' => 'schema-1',
            'targetStatus' => 'ONLINE',
            'timestamp' => 1757590000000,
            'usedIdForPortal' => $entityId,
            'showAddress' => true,
            'messages' => [],
            'detailedMessages' => [],
            'publishChannels' => [],
        ], $extra);

        return [
            'companyId' => 'company-1',
            'userId' => 'api-user',
            'errors' => array_values(array_map(
                fn (string $portalId, string $meldung): array => $eintrag($portalId, [
                    'messages' => ['Validation failed'],
                    'detailedMessages' => [['originalMessage' => 'Validation failed', 'validationError' => ['translatedMessage' => $meldung, 'type' => 'MISSING_FIELD']]],
                ]),
                array_keys($fehler),
                $fehler,
            )),
            'warnings' => [],
            'successFullyTransfered' => array_map($eintrag, $transferiert),
            'successfullyScheduled' => array_map($eintrag, $geplant),
            'portalSyncMode' => 'PART_SYNC',
            'publishType' => 'MANUAL',
        ];
    }

    protected function beispielbild(int $breite = 64, int $hoehe = 64): string
    {
        $bild = imagecreatetruecolor($breite, $hoehe);
        $farbe = imagecolorallocate($bild, 230, 168, 60);
        imagefilledrectangle($bild, 0, 0, $breite - 1, $hoehe - 1, $farbe);
        ob_start();
        imagejpeg($bild, null, 85);
        imagedestroy($bild);

        return (string) ob_get_clean();
    }
}
