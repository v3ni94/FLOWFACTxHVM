<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Domain\Settings\SettingsRepository;
use App\Enums\MediaTyp;
use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\Exceptions\NotFoundException;
use App\Flowfact\Client\Exceptions\RateLimitException;
use App\Flowfact\Services\ImageResizer;
use App\Flowfact\Services\MultimediaService;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bildabgleich je Objekt (docs/connector.md Abschnitt 3 Schritt 7,
 * flowfact-api.md Abschnitt 8).
 *
 * Hochgeladen werden nur Medien mit im_inserat = true vom Typ Bild oder
 * Grundriss. Dokumente werden in dieser Version nicht übertragen
 * (Datenvertrag 2.6). Jedes Medium erhält genau einmal ein FLOWFACT-Item;
 * die gespeicherte flowfact_multimedia_id verhindert Doppeluploads.
 */
final class MediaSyncService
{
    public const string DISK = 'media';

    public function __construct(
        private readonly MultimediaService $multimedia,
        private readonly ImageResizer $resizer,
        private readonly SettingsRepository $settings,
    ) {}

    public function sync(Listing $listing, string $schema, string $entityId, ?User $user = null, ?Carbon $deadline = null): MediaSyncResult
    {
        $multimedia = $this->multimedia->scoped($listing, $user);
        $warnungen = [];

        $geloescht = $this->verarbeiteLoeschungen($listing, $multimedia, $warnungen);

        $medien = $listing->media()
            ->whereIn('typ', [MediaTyp::Bild->value, MediaTyp::Grundriss->value])
            ->where('im_inserat', true)
            ->orderByRaw("CASE WHEN typ = 'bild' THEN 0 ELSE 1 END")
            ->orderBy('sortierung')
            ->get();

        $offen = $medien->filter(fn (ListingMedia $medium): bool => $medium->flowfact_multimedia_id === null);

        // Ohne offene Uploads und ohne Löschungen gibt es nichts abzugleichen;
        // das spart die Album- und Zuordnungsaufrufe bei jedem Lauf.
        if ($medien->isEmpty() || ($offen->isEmpty() && $geloescht === 0)) {
            return new MediaSyncResult($warnungen, true, 0, $geloescht);
        }

        $album = $this->album($schema, $multimedia);

        if ($album === null) {
            $warnungen[] = sprintf('Kein Album mit Bildkategorie im Schema "%s" gefunden, Bilder wurden nicht übertragen.', $schema);

            return new MediaSyncResult($warnungen, true, 0, $geloescht);
        }

        $hochgeladen = 0;
        $vollstaendig = true;

        foreach ($offen as $medium) {

            if ($deadline !== null && Carbon::now()->greaterThanOrEqualTo($deadline)) {
                $vollstaendig = false;
                break;
            }

            if ($this->upload($medium, $schema, $entityId, $album, $multimedia, $warnungen)) {
                $hochgeladen++;
            }
        }

        if ($hochgeladen > 0 || $geloescht > 0) {
            $this->setzeReihenfolge($medien, $schema, $entityId, $album, $multimedia, $warnungen);
        }

        return new MediaSyncResult($warnungen, $vollstaendig, $hochgeladen, $geloescht);
    }

    /**
     * Offene Arbeit: fehlende Uploads oder vorgemerkte Löschungen.
     */
    public function hatOffeneArbeit(Listing $listing): bool
    {
        $offeneUploads = $listing->media()
            ->whereIn('typ', [MediaTyp::Bild->value, MediaTyp::Grundriss->value])
            ->where('im_inserat', true)
            ->whereNull('flowfact_multimedia_id')
            ->exists();

        return $offeneUploads || ListingMediaDeletion::query()->where('listing_id', $listing->id)->exists();
    }

    /**
     * @param  list<string>  $warnungen
     */
    private function verarbeiteLoeschungen(Listing $listing, MultimediaService $multimedia, array &$warnungen): int
    {
        $anzahl = 0;

        foreach (ListingMediaDeletion::query()->where('listing_id', $listing->id)->get() as $vormerkung) {
            try {
                $multimedia->deleteItem($vormerkung->flowfact_multimedia_id);
                $vormerkung->delete();
                $anzahl++;
            } catch (NotFoundException) {
                $vormerkung->delete();
                $anzahl++;
            } catch (AuthenticationException|RateLimitException $exception) {
                throw $exception;
            } catch (FlowfactException $exception) {
                $warnungen[] = sprintf('Bild %s konnte in FLOWFACT nicht gelöscht werden: %s', $vormerkung->flowfact_multimedia_id, $exception->getMessage());
            }
        }

        return $anzahl;
    }

    /**
     * Album und Kategorie je Schema, einmal ermittelt und in den Einstellungen
     * unter flowfact.album_<schema> abgelegt (offener Punkt 18 in
     * flowfact-api.md Abschnitt 9: die Namen sind kontospezifisch).
     *
     * @return array{album: string, bilder: string, dokumente: string|null}|null
     */
    private function album(string $schema, MultimediaService $multimedia): ?array
    {
        $schluessel = 'flowfact.album_'.$schema;
        $gespeichert = $this->settings->get($schluessel);

        if (is_string($gespeichert)) {
            $gespeichert = json_decode($gespeichert, true);
        }

        if (is_array($gespeichert) && isset($gespeichert['album'], $gespeichert['bilder'])) {
            return [
                'album' => (string) $gespeichert['album'],
                'bilder' => (string) $gespeichert['bilder'],
                'dokumente' => isset($gespeichert['dokumente']) ? (string) $gespeichert['dokumente'] : null,
            ];
        }

        $ermittelt = $this->ermittleAlbum($multimedia->albums($schema));

        if ($ermittelt !== null) {
            $this->settings->set($schluessel, $ermittelt);
        }

        return $ermittelt;
    }

    /**
     * @param  list<array<string, mixed>>  $alben
     * @return array{album: string, bilder: string, dokumente: string|null}|null
     */
    private function ermittleAlbum(array $alben): ?array
    {
        foreach ($alben as $album) {
            $name = $album['name'] ?? null;
            $kategorien = $album['categories'] ?? [];

            if (! is_string($name) || ! is_array($kategorien)) {
                continue;
            }

            $bilder = null;
            $dokumente = null;

            foreach ($kategorien as $kategorie) {
                if (! is_array($kategorie) || ! isset($kategorie['name']) || ! is_string($kategorie['name'])) {
                    continue;
                }

                $erlaubt = is_array($kategorie['allowedContentCategories'] ?? null) ? $kategorie['allowedContentCategories'] : [];

                if ($bilder === null && in_array('IMAGE', $erlaubt, true)) {
                    $bilder = $kategorie['name'];
                }

                if ($dokumente === null && in_array('DOCUMENT', $erlaubt, true)) {
                    $dokumente = $kategorie['name'];
                }
            }

            if ($bilder !== null) {
                return ['album' => $name, 'bilder' => $bilder, 'dokumente' => $dokumente];
            }
        }

        return null;
    }

    /**
     * Upload-Kette: Presigned-URL, PUT, Item registrieren, ID speichern.
     *
     * @param  array{album: string, bilder: string, dokumente: string|null}  $album
     * @param  list<string>  $warnungen
     */
    private function upload(ListingMedia $medium, string $schema, string $entityId, array $album, MultimediaService $multimedia, array &$warnungen): bool
    {
        $inhalt = Storage::disk(self::DISK)->get($medium->pfad);

        if ($inhalt === null || $inhalt === '') {
            $warnungen[] = sprintf('Datei für Bild "%s" nicht gefunden, Upload übersprungen.', $medium->dateiname_original);

            return false;
        }

        try {
            $bild = $this->resizer->resize($inhalt, $medium->mime);
        } catch (Throwable $exception) {
            $warnungen[] = sprintf('Bild "%s" konnte nicht verarbeitet werden: %s', $medium->dateiname_original, $exception->getMessage());

            return false;
        }

        $dateiname = $this->dateiname($medium, $bild['extension']);
        $groesse = strlen($bild['content']);

        $presigned = $multimedia->presignedUrl($schema, $entityId, $bild['mime'], $dateiname, $groesse);

        if ($presigned['presignedUrl'] === '' || $presigned['itemLink'] === '') {
            $warnungen[] = sprintf('FLOWFACT hat für Bild "%s" keine Upload-URL geliefert.', $medium->dateiname_original);

            return false;
        }

        $multimedia->uploadBinary($presigned['presignedUrl'], $bild['content'], $bild['mime']);

        $body = [
            'contentType' => $bild['mime'],
            'fileName' => $dateiname,
            'fileSize' => $groesse,
            'itemLink' => $presigned['itemLink'],
            'albumAssignments' => [
                ['albumName' => $album['album'], 'categories' => [$album['bilder']]],
            ],
        ];

        if ($medium->titel !== null && trim($medium->titel) !== '') {
            $body['title'] = trim($medium->titel);
        }

        $item = $multimedia->registerItem($schema, $entityId, $body);

        if (! isset($item['id']) || ! is_scalar($item['id'])) {
            $warnungen[] = sprintf('FLOWFACT hat für Bild "%s" keine Item-ID geliefert.', $medium->dateiname_original);

            return false;
        }

        // saveQuietly: die FLOWFACT-ID ist kein Inhalt des Inserats und darf
        // keine Änderungsmarkierung auslösen.
        $medium->flowfact_multimedia_id = (string) $item['id'];
        $medium->saveQuietly();

        return true;
    }

    /**
     * Reihenfolge über die Sortierung der Albumzuordnung, Titelbild an
     * Position 0 (flowfact-api.md Abschnitt 8, Schritt 5).
     *
     * @param  Collection<int, ListingMedia>  $medien
     * @param  array{album: string, bilder: string, dokumente: string|null}  $album
     * @param  list<string>  $warnungen
     */
    private function setzeReihenfolge($medien, string $schema, string $entityId, array $album, MultimediaService $multimedia, array &$warnungen): void
    {
        $items = [];

        foreach ($multimedia->items($entityId, 'IMAGE') as $item) {
            if (isset($item['id']) && is_scalar($item['id'])) {
                $items[(string) $item['id']] = $item;
            }
        }

        $zuordnung = [];
        $position = 0;

        foreach ($medien as $medium) {
            if ($medium->flowfact_multimedia_id === null) {
                continue;
            }

            $id = (string) $medium->flowfact_multimedia_id;
            $zuordnung[] = [
                'multimedia' => $items[$id] ?? ['id' => is_numeric($id) ? (int) $id : $id],
                'sorting' => $position++,
            ];
        }

        if ($zuordnung === []) {
            return;
        }

        try {
            $multimedia->setAssignments($schema, $entityId, $album['album'], [$album['bilder'] => $zuordnung]);
        } catch (AuthenticationException|RateLimitException $exception) {
            throw $exception;
        } catch (FlowfactException $exception) {
            $warnungen[] = 'Die Bildreihenfolge konnte nicht gesetzt werden: '.$exception->getMessage();
        }
    }

    private function dateiname(ListingMedia $medium, string $erweiterung): string
    {
        $basis = Str::slug(pathinfo($medium->dateiname_original, PATHINFO_FILENAME));

        if ($basis === '') {
            $basis = 'bild-'.$medium->id;
        }

        return mb_substr($basis, 0, 80).'.'.$erweiterung;
    }
}
