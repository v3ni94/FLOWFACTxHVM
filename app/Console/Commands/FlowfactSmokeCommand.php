<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Settings\SettingsRepository;
use App\Flowfact\Client\FlowfactClient;
use App\Flowfact\Query\Flowdsl;
use App\Flowfact\Services\CompanyService;
use App\Flowfact\Services\EntityService;
use App\Flowfact\Services\MultimediaService;
use App\Flowfact\Services\PortalService;
use App\Flowfact\Services\SchemaService;
use App\Flowfact\Services\SearchService;
use App\Flowfact\Services\UserService;
use App\Flowfact\Sync\ListingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

/**
 * flow:flowfact:smoke
 *
 * Smoke-Test am echten Konto (docs/connector.md Abschnitt 6, flowfact-api.md
 * Abschnitt 10). Ohne Option nur lesend (Schritte 1 bis 5). Mit --write
 * folgen die Schritte 6 bis 17 mit einem Testobjekt (identifier TEST-<Zeit>)
 * und einem erzeugten 64x64-Bild, anschließend wird aufgeräumt.
 *
 * WARUM kein Publish: Ein POST /publish würde das Testobjekt real auf ein
 * Portal exportieren. Der Befehl enthält diesen Aufruf nicht und bietet keine
 * Option, ihn zu aktivieren (Schritt 15 entfällt bewusst).
 */
class FlowfactSmokeCommand extends Command
{
    protected $signature = 'flow:flowfact:smoke {--write : Führt zusätzlich die schreibenden Schritte 6 bis 17 mit einem Testobjekt aus}';

    protected $description = 'Prüft die FLOWFACT-Verbindung Schritt für Schritt und schreibt ein Protokoll nach storage/logs.';

    /** @var list<array{nr: string, aufruf: string, erwartung: string, ergebnis: string, status: string, ok: bool}> */
    private array $zeilen = [];

    public function handle(
        FlowfactClient $client,
        UserService $users,
        CompanyService $companies,
        SchemaService $schemata,
        SearchService $search,
        EntityService $entities,
        MultimediaService $multimedia,
        PortalService $portale,
        SettingsRepository $settings,
    ): int {
        if (! $client->isConfigured()) {
            $this->error('Kein FLOWFACT-Token hinterlegt. Bitte den Token im Adminbereich unter FLOWFACT eintragen, bevor der Smoke-Test läuft.');

            return CommandAlias::FAILURE;
        }

        $start = Carbon::now();
        $this->info('FLOWFACT-Smoke-Test, Basis-URL '.config('flowfact.base_url'));

        $companyId = null;
        $schema = null;

        // Schritt 1
        $ok = $this->schritt('1', 'GET user-service/users/currentUser (x-ff-version 2)', '200, User mit companyId, type API', function () use ($users, &$companyId): string {
            $user = $users->currentUser();
            $companyId = isset($user['companyId']) ? (string) $user['companyId'] : null;

            return sprintf('Benutzer %s, Typ %s, companyId %s', (string) ($user['loginRelatedMailAddress'] ?? $user['businessMailAddress'] ?? $user['id'] ?? '?'), (string) ($user['type'] ?? '?'), $companyId ?? 'fehlt');
        }, $client);

        // Schritt 2
        if ($ok) {
            $ok = $this->schritt('2', 'GET company-service/company/{companyId}', '200, companyName passt', function () use ($companies, $companyId): string {
                if ($companyId === null) {
                    throw new \RuntimeException('Keine companyId aus Schritt 1.');
                }

                $company = $companies->company($companyId);

                return 'Company '.(string) ($company['companyName'] ?? '?');
            }, $client);
        }

        // Schritt 3
        $liste = [];

        if ($ok) {
            $ok = $this->schritt('3', 'GET schema-service/v2/schemas?group=estates', '200, entries[] mit name', function () use ($schemata, &$liste): string {
                $liste = $schemata->estateSchemas();

                return count($liste).' Schema(ta): '.implode(', ', array_column($liste, 'name'));
            }, $client);
        }

        // Schritt 4
        if ($ok) {
            $schema = $this->waehleSchema($settings, $liste);

            $ok = $this->schritt('4', 'GET schema-service/v2/schemas/{schema}?extensions=all', '200, properties', function () use ($schemata, $schema): string {
                if ($schema === null) {
                    throw new \RuntimeException('Kein Estate-Schema bekannt (Einstellung oder Schritt 3).');
                }

                $definition = $schemata->schema($schema);
                $properties = SchemaService::properties($definition);

                return sprintf('Schema %s mit %d Properties', $schema, count($properties));
            }, $client);
        }

        // Schritt 5
        if ($ok) {
            $ok = $this->schritt('5', 'POST search-service/schemas/estates?page=1&size=1&withCount=true (Flowdsl identifier)', '200, totalCount', function () use ($search): string {
                $ergebnis = $search->findByField('estates', 'identifier', 'TEST-SMOKE-PROBE', 1, Flowdsl::EQUALS);

                return 'totalCount '.$ergebnis['totalCount'];
            }, $client);
        }

        if ($ok && $this->option('write')) {
            $this->schreibendeSchritte($client, $entities, $multimedia, $portale, (string) $schema);
        } elseif (! $this->option('write')) {
            $this->line('Schreibende Schritte 6 bis 17 übersprungen (Option --write nicht gesetzt).');
        }

        $this->tabelle();
        $pfad = $this->protokollSchreiben($start);
        $this->line('Protokoll: '.$pfad);

        $fehler = count(array_filter($this->zeilen, fn (array $zeile): bool => ! $zeile['ok']));

        if ($fehler > 0) {
            $this->error(sprintf('%d Schritt(e) fehlgeschlagen.', $fehler));

            return CommandAlias::FAILURE;
        }

        $this->info('Alle Schritte erfolgreich.');

        return CommandAlias::SUCCESS;
    }

    private function schreibendeSchritte(FlowfactClient $client, EntityService $entities, MultimediaService $multimedia, PortalService $portale, string $schema): void
    {
        $identifier = 'TEST-'.Carbon::now()->format('Ymd-His');
        $entityId = null;
        $itemId = null;
        $itemGeloescht = false;
        $entityGeloescht = false;

        try {
            $ok = $this->schritt('6', 'POST entity-service/schemas/{schema} (x-ff-version 2)', '2xx, Antwort mit id', function () use ($entities, $schema, $identifier, &$entityId): string {
                $entityId = $entities->create($schema, [
                    'headline' => ['values' => ['Testobjekt Müller FLOW, bitte nicht veröffentlichen']],
                    'identifier' => ['values' => [$identifier]],
                    'status' => ['values' => ['active']],
                ]);

                return sprintf('Entität %s mit identifier %s angelegt', $entityId, $identifier);
            }, $client);

            if (! $ok || $entityId === null) {
                return;
            }

            $this->schritt('7', 'GET entity-service/schemas/{schema}/entities/{id}', '200, Werte in values[]', function () use ($entities, $schema, $entityId): string {
                $entity = $entities->get($schema, $entityId);
                $headline = $entity['headline']['values'][0] ?? null;

                return 'headline '.(is_scalar($headline) ? (string) $headline : 'fehlt');
            }, $client);

            $this->schritt('8', 'PATCH entity-service/schemas/{schema}/entities/{id}', '200', function () use ($entities, $schema, $entityId): string {
                $entities->patch($schema, $entityId, ['headline' => ['values' => ['Test geändert']]]);

                return 'headline geändert';
            }, $client);

            $album = null;
            $this->schritt('9', 'GET multimedia-service/albums/schemas/{schema}', '200, Album[]', function () use ($multimedia, $schema, &$album): string {
                $alben = $multimedia->albums($schema);

                foreach ($alben as $kandidat) {
                    foreach ((array) ($kandidat['categories'] ?? []) as $kategorie) {
                        if (is_array($kategorie) && in_array('IMAGE', (array) ($kategorie['allowedContentCategories'] ?? []), true)) {
                            $album = ['album' => (string) $kandidat['name'], 'kategorie' => (string) $kategorie['name']];
                            break 2;
                        }
                    }
                }

                return sprintf('%d Album(s), Bildkategorie: %s', count($alben), $album !== null ? $album['album'].'/'.$album['kategorie'] : 'keine gefunden');
            }, $client);

            $bild = $this->testbild();
            $presigned = null;

            $ok = $this->schritt('10', 'GET multimedia-service/items/schemas/{schema}/entities/{id}/presigned-url', '200, presignedUrl, itemLink', function () use ($multimedia, $schema, $entityId, $bild, &$presigned): string {
                $presigned = $multimedia->presignedUrl($schema, $entityId, 'image/jpeg', 'test.jpg', strlen($bild));

                if ($presigned['presignedUrl'] === '' || $presigned['itemLink'] === '') {
                    throw new \RuntimeException('presignedUrl oder itemLink fehlt.');
                }

                return 'Upload-URL erhalten';
            }, $client);

            if ($ok && $presigned !== null) {
                $this->schritt('11', 'PUT presignedUrl, dann POST multimedia-service/items/schemas/{schema}/entities/{id}', '2xx, multimediaItem.id', function () use ($multimedia, $schema, $entityId, $bild, $presigned, $album, &$itemId): string {
                    $multimedia->uploadBinary($presigned['presignedUrl'], $bild, 'image/jpeg');

                    $body = [
                        'contentType' => 'image/jpeg',
                        'fileName' => 'test.jpg',
                        'fileSize' => strlen($bild),
                        'itemLink' => $presigned['itemLink'],
                        'title' => 'Testbild',
                    ];

                    if ($album !== null) {
                        $body['albumAssignments'] = [['albumName' => $album['album'], 'categories' => [$album['kategorie']]]];
                    }

                    $item = $multimedia->registerItem($schema, $entityId, $body);
                    $itemId = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : null;

                    if ($itemId === null) {
                        throw new \RuntimeException('multimediaItem.id fehlt.');
                    }

                    return 'Item '.$itemId;
                }, $client);
            }

            $this->schritt('12', 'GET multimedia-service/items/entities/{id}?contentCategory=IMAGE', '200, ein Eintrag', function () use ($multimedia, $entityId): string {
                return count($multimedia->items($entityId, 'IMAGE')).' Bild(er) am Objekt';
            }, $client);

            $this->schritt('13', 'GET portal-management-service/portals?ignoreInactivePortals=true', '200, Portal[] mit authenticated', function () use ($portale): string {
                $liste = $portale->portals(true);
                $authentifiziert = count(array_filter($liste, fn (array $p): bool => (bool) ($p['authenticated'] ?? false)));

                return sprintf('%d Portal(e), davon %d authentifiziert', count($liste), $authentifiziert);
            }, $client);

            $this->schritt('14', 'GET portal-management-service/estates/{id}/portals', '200, leeres Array für das Testobjekt', function () use ($portale, $entityId): string {
                return count($portale->estatePortals($entityId)).' Portaleintrag/-einträge';
            }, $client);

            $this->zeile('15', 'POST /publish', 'entfällt', 'Bewusst nicht ausgeführt, keine Veröffentlichung des Testobjekts', '-', true);

            $this->schritt('16', 'DELETE multimedia-service/items/{id}, dann DELETE entity-service/schemas/{schema}/entities/{id}', '2xx', function () use ($multimedia, $entities, $schema, $entityId, $itemId, &$itemGeloescht, &$entityGeloescht): string {
                if ($itemId !== null) {
                    $multimedia->deleteItem($itemId);
                    $itemGeloescht = true;
                }

                $entities->delete($schema, $entityId);
                $entityGeloescht = true;

                return 'Testobjekt gelöscht';
            }, $client);

            $this->schritt('17', 'GET entity-service/recovery/entities?schema={schema}', '200, Eintrag mit entityId', function () use ($entities, $schema, $entityId): string {
                $papierkorb = $entities->recoveryEntities($schema);
                $gefunden = false;

                foreach ((array) ($papierkorb['entries'] ?? []) as $eintrag) {
                    if (is_array($eintrag) && (string) ($eintrag['entityId'] ?? '') === $entityId) {
                        $gefunden = true;
                    }
                }

                return $gefunden ? 'Testobjekt im Papierkorb' : 'Testobjekt nicht im Papierkorb gefunden (Löschverhalten prüfen)';
            }, $client);
        } finally {
            $this->aufraeumen($entities, $multimedia, $schema, $entityId, $itemId, $itemGeloescht, $entityGeloescht);
        }
    }

    private function aufraeumen(EntityService $entities, MultimediaService $multimedia, string $schema, ?string $entityId, ?string $itemId, bool $itemGeloescht, bool $entityGeloescht): void
    {
        if ($itemId !== null && ! $itemGeloescht) {
            try {
                $multimedia->deleteItem($itemId);
            } catch (Throwable $exception) {
                $this->warn('Aufräumen: Testbild konnte nicht gelöscht werden: '.$exception->getMessage());
            }
        }

        if ($entityId !== null && ! $entityGeloescht) {
            try {
                $entities->delete($schema, $entityId);
            } catch (Throwable $exception) {
                $this->warn('Aufräumen: Testobjekt konnte nicht gelöscht werden: '.$exception->getMessage());
            }
        }
    }

    /**
     * @param  list<array{name: string, caption: string}>  $liste
     */
    private function waehleSchema(SettingsRepository $settings, array $liste): ?string
    {
        foreach ([ListingSyncService::SCHEMA_MIETE, ListingSyncService::SCHEMA_KAUF] as $schluessel) {
            $wert = $settings->get($schluessel);

            if (is_string($wert) && trim($wert) !== '') {
                return trim($wert);
            }
        }

        return $liste[0]['name'] ?? null;
    }

    /**
     * Erzeugt ein 64x64-JPEG mit GD, ohne Dateiabhängigkeit.
     */
    private function testbild(): string
    {
        $bild = imagecreatetruecolor(64, 64);
        $orange = imagecolorallocate($bild, 230, 168, 60);
        $grau = imagecolorallocate($bild, 20, 20, 20);
        imagefilledrectangle($bild, 0, 0, 63, 63, $orange);
        imagefilledrectangle($bild, 16, 16, 47, 47, $grau);

        ob_start();
        imagejpeg($bild, null, 85);
        imagedestroy($bild);

        return (string) ob_get_clean();
    }

    /**
     * @param  callable(): string  $ausfuehrung
     */
    private function schritt(string $nr, string $aufruf, string $erwartung, callable $ausfuehrung, FlowfactClient $client): bool
    {
        try {
            $ergebnis = $ausfuehrung();
            $this->zeile($nr, $aufruf, $erwartung, $ergebnis, (string) ($client->lastStatus() ?? '-'), true);

            return true;
        } catch (Throwable $exception) {
            $this->zeile($nr, $aufruf, $erwartung, 'FEHLER: '.$exception->getMessage(), (string) ($client->lastStatus() ?? '-'), false);

            return false;
        }
    }

    private function zeile(string $nr, string $aufruf, string $erwartung, string $ergebnis, string $status, bool $ok): void
    {
        $this->zeilen[] = ['nr' => $nr, 'aufruf' => $aufruf, 'erwartung' => $erwartung, 'ergebnis' => $ergebnis, 'status' => $status, 'ok' => $ok];

        $prefix = $ok ? '[OK]  ' : '[FEHLER] ';
        $text = sprintf('%sSchritt %s: %s -> %s (HTTP %s)', $prefix, $nr, $aufruf, $ergebnis, $status);

        if ($ok) {
            $this->line($text);
        } else {
            $this->error($text);
        }
    }

    private function tabelle(): void
    {
        $this->newLine();
        $this->table(
            ['Nr', 'Aufruf', 'Erwartung', 'Ergebnis', 'HTTP'],
            array_map(fn (array $z): array => [$z['nr'], $z['aufruf'], $z['erwartung'], $z['ergebnis'], $z['status']], $this->zeilen),
        );
    }

    private function protokollSchreiben(Carbon $start): string
    {
        $pfad = storage_path('logs/flowfact-smoke-'.$start->format('Ymd-His').'.md');

        $inhalt = "# FLOWFACT-Smoke-Test\n\n";
        $inhalt .= 'Zeitpunkt: '.$start->format('d.m.Y H:i:s')."\n";
        $inhalt .= 'Basis-URL: '.config('flowfact.base_url')."\n";
        $inhalt .= 'Modus: '.($this->option('write') ? 'lesend und schreibend (--write)' : 'nur lesend')."\n\n";
        $inhalt .= "| Nr | Aufruf | Erwartung | Ergebnis | HTTP | Status |\n|---|---|---|---|---|---|\n";

        foreach ($this->zeilen as $zeile) {
            $inhalt .= sprintf(
                "| %s | %s | %s | %s | %s | %s |\n",
                $zeile['nr'],
                str_replace('|', '\\|', $zeile['aufruf']),
                str_replace('|', '\\|', $zeile['erwartung']),
                str_replace(['|', "\n"], ['\\|', ' '], $zeile['ergebnis']),
                $zeile['status'],
                $zeile['ok'] ? 'OK' : 'FEHLER',
            );
        }

        $inhalt .= "\nEin POST /publish ist in diesem Befehl nicht enthalten und kann nicht aktiviert werden.\n";

        File::ensureDirectoryExists(dirname($pfad));
        File::put($pfad, $inhalt);

        return $pfad;
    }
}
