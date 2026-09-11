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
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Bildabgleich je Objekt (docs/connector.md Abschnitt 3 Schritt 7,
 * flowfact-api.md Abschnitt 8).
 *
 * Hochgeladen werden nur Medien mit im_inserat = true vom Typ Bild oder
 * Grundriss. Dokumente werden in dieser Version nicht übertragen
 * (Datenvertrag 2.6). Jedes Medium erhält genau einmal ein FLOWFACT-Item;
 * die gespeicherte flowfact_multimedia_id verhindert Doppeluploads.
 *
 * Nach Prüfbericht 2026-09-11:
 * - Befund 4: ausgeblendete Medien (im_inserat = false) und Dokumente mit
 *   FLOWFACT-ID werden gelöscht, geänderte Titel per PATCH nachgezogen, die
 *   Reihenfolge wird bei jeder Inhaltsänderung neu gesetzt.
 * - Befund 6: deterministischer Dateiname je Medium; vor dem Upload werden
 *   die vorhandenen Items gelesen und ein Treffer über den Dateinamen
 *   übernommen statt erneut hochgeladen.
 * - Befund 7: nach jedem Upload wird die Lease über den Herzschlag verlängert.
 */
final class MediaSyncService
{
    public const string DISK = 'media';

    /** @var array<string, array<string, mixed>>|null Items der Entität je ID, einmal je Lauf gelesen */
    private ?array $items = null;

    public function __construct(
        private readonly MultimediaService $multimedia,
        private readonly ImageResizer $resizer,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  bool  $inhaltGeaendert  true, wenn sich der Inhalts-Hash geändert hat; dann wird die Reihenfolge immer neu gesetzt
     * @param  Closure|null  $heartbeat  wird nach jedem übertragenen Medium aufgerufen (Lease verlängern)
     */
    public function sync(Listing $listing, string $schema, string $entityId, ?User $user = null, ?Carbon $deadline = null, bool $inhaltGeaendert = false, ?Closure $heartbeat = null): MediaSyncResult
    {
        $multimedia = $this->multimedia->scoped($listing, $user);
        $warnungen = [];
        $this->items = null;

        $geloescht = $this->verarbeiteLoeschungen($listing, $multimedia, $warnungen);
        $geloescht += $this->verarbeiteAusgeblendete($listing, $multimedia, $warnungen);

        $medien = $listing->media()
            ->whereIn('typ', [MediaTyp::Bild->value, MediaTyp::Grundriss->value])
            ->where('im_inserat', true)
            ->orderByRaw("CASE WHEN typ = 'bild' THEN 0 ELSE 1 END")
            ->orderBy('sortierung')
            ->get();

        if ($medien->isEmpty()) {
            return new MediaSyncResult($warnungen, true, 0, $geloescht);
        }

        $offen = $medien->filter(fn (ListingMedia $medium): bool => $medium->flowfact_multimedia_id === null);
        $titelGeaendert = $medien->contains(fn (ListingMedia $medium): bool => $medium->flowfact_multimedia_id !== null && $this->titelWeichtAb($medium));

        // Ohne offene Uploads, Löschungen, Titel- oder Inhaltsänderungen gibt es
        // nichts abzugleichen; das spart die Album- und Zuordnungsaufrufe.
        if ($offen->isEmpty() && $geloescht === 0 && ! $titelGeaendert && ! $inhaltGeaendert) {
            return new MediaSyncResult($warnungen, true, 0, $geloescht);
        }

        $hochgeladen = 0;
        $vollstaendig = true;
        $album = null;

        if ($offen->isNotEmpty()) {
            $album = $this->album($schema, $multimedia);

            if ($album === null) {
                $warnungen[] = sprintf('Kein Album mit Bildkategorie im Schema "%s" gefunden, Bilder wurden nicht übertragen.', $schema);
            } else {
                foreach ($offen as $medium) {
                    if ($deadline !== null && Carbon::now()->greaterThanOrEqualTo($deadline)) {
                        $vollstaendig = false;
                        break;
                    }

                    if ($this->uebernimmVorhandenesItem($listing, $medium, $entityId, $multimedia) || $this->upload($listing, $medium, $schema, $entityId, $album, $multimedia, $warnungen)) {
                        $hochgeladen++;

                        if ($heartbeat !== null) {
                            $heartbeat();
                        }
                    }
                }
            }
        }

        $this->gleicheTitelAb($medien, $multimedia, $warnungen);

        $hatUebertragene = $medien->contains(fn (ListingMedia $medium): bool => $medium->flowfact_multimedia_id !== null);

        if ($hatUebertragene && ($hochgeladen > 0 || $geloescht > 0 || $inhaltGeaendert)) {
            $album ??= $this->album($schema, $multimedia);

            if ($album === null) {
                $warnungen[] = sprintf('Kein Album mit Bildkategorie im Schema "%s" gefunden, die Bildreihenfolge wurde nicht gesetzt.', $schema);
            } else {
                $this->setzeReihenfolge($medien, $schema, $entityId, $album, $multimedia, $warnungen);
            }
        }

        return new MediaSyncResult($warnungen, $vollstaendig, $hochgeladen, $geloescht);
    }

    /**
     * Offene Arbeit: fehlende Uploads, vorgemerkte Löschungen, ausgeblendete
     * Medien mit FLOWFACT-Item oder abweichende Titel.
     */
    public function hatOffeneArbeit(Listing $listing): bool
    {
        $offeneUploads = $listing->media()
            ->whereIn('typ', [MediaTyp::Bild->value, MediaTyp::Grundriss->value])
            ->where('im_inserat', true)
            ->whereNull('flowfact_multimedia_id')
            ->exists();

        if ($offeneUploads) {
            return true;
        }

        if ($this->ausgeblendeteMitItem($listing)->exists()) {
            return true;
        }

        $titelAbweichend = $listing->media()
            ->whereNotNull('flowfact_multimedia_id')
            ->where('im_inserat', true)
            ->whereRaw("COALESCE(titel, '') <> COALESCE(flowfact_titel, '')")
            ->exists();

        return $titelAbweichend || ListingMediaDeletion::query()->where('listing_id', $listing->id)->exists();
    }

    /**
     * Deterministischer Dateiname in FLOWFACT (Befund 6):
     * "<listing uuid>-<media id>-<erste 12 Hex der SHA-256>.<ext>".
     */
    public function dateiname(Listing $listing, ListingMedia $medium, string $erweiterung): string
    {
        return $this->dateinameBasis($listing, $medium).'.'.$erweiterung;
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
     * Befund 4: Medien, die aus dem Inserat genommen wurden (oder Dokumente),
     * aber noch ein FLOWFACT-Item tragen, werden dort gelöscht und die lokale
     * ID geleert. Wird das Medium später wieder eingeblendet, wird es neu
     * hochgeladen.
     *
     * @param  list<string>  $warnungen
     */
    private function verarbeiteAusgeblendete(Listing $listing, MultimediaService $multimedia, array &$warnungen): int
    {
        $anzahl = 0;

        foreach ($this->ausgeblendeteMitItem($listing)->get() as $medium) {
            $itemId = (string) $medium->flowfact_multimedia_id;

            try {
                $multimedia->deleteItem($itemId);
            } catch (NotFoundException) {
                // Item existiert nicht mehr, lokal trotzdem bereinigen.
            } catch (AuthenticationException|RateLimitException $exception) {
                throw $exception;
            } catch (FlowfactException $exception) {
                $warnungen[] = sprintf('Ausgeblendetes Bild "%s" konnte in FLOWFACT nicht gelöscht werden: %s', $medium->dateiname_original, $exception->getMessage());

                continue;
            }

            $medium->flowfact_multimedia_id = null;
            $medium->flowfact_titel = null;
            $medium->saveQuietly();
            $anzahl++;
        }

        return $anzahl;
    }

    /**
     * @return HasMany<ListingMedia, Listing>
     */
    private function ausgeblendeteMitItem(Listing $listing)
    {
        return $listing->media()
            ->whereNotNull('flowfact_multimedia_id')
            ->where(function ($query): void {
                $query->where('im_inserat', false)->orWhere('typ', MediaTyp::Dokument->value);
            });
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
     * Items der Entität (Kategorie IMAGE), einmal je Lauf gelesen und um neu
     * registrierte Items ergänzt.
     *
     * @return array<string, array<string, mixed>>
     */
    private function items(string $entityId, MultimediaService $multimedia): array
    {
        if ($this->items === null) {
            $this->items = [];

            foreach ($multimedia->items($entityId, 'IMAGE') as $item) {
                if (isset($item['id']) && is_scalar($item['id'])) {
                    $this->items[(string) $item['id']] = $item;
                }
            }
        }

        return $this->items;
    }

    /**
     * Befund 6: Ist in FLOWFACT bereits ein Item mit dem deterministischen
     * Dateinamen dieses Mediums vorhanden (z. B. nach einer Zeitüberschreitung
     * beim Registrieren), wird dessen ID übernommen statt erneut hochzuladen.
     */
    private function uebernimmVorhandenesItem(Listing $listing, ListingMedia $medium, string $entityId, MultimediaService $multimedia): bool
    {
        $basis = $this->dateinameBasis($listing, $medium);

        foreach ($this->items($entityId, $multimedia) as $id => $item) {
            $dateiname = $item['fileName'] ?? null;

            if (! is_string($dateiname) || pathinfo($dateiname, PATHINFO_FILENAME) !== $basis) {
                continue;
            }

            $titel = $item['title'] ?? null;
            $medium->flowfact_multimedia_id = $id;
            $medium->flowfact_titel = is_string($titel) && trim($titel) !== '' ? trim($titel) : null;
            $medium->saveQuietly();

            return true;
        }

        return false;
    }

    /**
     * Upload-Kette: Presigned-URL, PUT, Item registrieren, ID speichern.
     *
     * @param  array{album: string, bilder: string, dokumente: string|null}  $album
     * @param  list<string>  $warnungen
     */
    private function upload(Listing $listing, ListingMedia $medium, string $schema, string $entityId, array $album, MultimediaService $multimedia, array &$warnungen): bool
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

        $dateiname = $this->dateiname($listing, $medium, $bild['extension']);
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

        $titel = $this->normalisierterTitel($medium->titel);

        if ($titel !== null) {
            $body['title'] = $titel;
        }

        $item = $multimedia->registerItem($schema, $entityId, $body);

        if (! isset($item['id']) || ! is_scalar($item['id'])) {
            $warnungen[] = sprintf('FLOWFACT hat für Bild "%s" keine Item-ID geliefert.', $medium->dateiname_original);

            return false;
        }

        if ($this->items !== null) {
            $this->items[(string) $item['id']] = $item;
        }

        // saveQuietly: die FLOWFACT-ID ist kein Inhalt des Inserats und darf
        // keine Änderungsmarkierung auslösen.
        $medium->flowfact_multimedia_id = (string) $item['id'];
        $medium->flowfact_titel = $titel;
        $medium->saveQuietly();

        return true;
    }

    /**
     * Befund 4: geänderte Bildtitel über PATCH /items/{id} (JSON-Patch)
     * nachziehen und den übertragenen Stand in flowfact_titel merken.
     *
     * @param  Collection<int, ListingMedia>  $medien
     * @param  list<string>  $warnungen
     */
    private function gleicheTitelAb($medien, MultimediaService $multimedia, array &$warnungen): void
    {
        foreach ($medien as $medium) {
            if ($medium->flowfact_multimedia_id === null || ! $this->titelWeichtAb($medium)) {
                continue;
            }

            $titel = $this->normalisierterTitel($medium->titel);
            $patch = $titel === null
                ? [['op' => 'remove', 'path' => '/title']]
                : [['op' => 'replace', 'path' => '/title', 'value' => $titel]];

            try {
                $multimedia->patchItem((string) $medium->flowfact_multimedia_id, $patch);
            } catch (AuthenticationException|RateLimitException $exception) {
                throw $exception;
            } catch (FlowfactException $exception) {
                $warnungen[] = sprintf('Titel von Bild "%s" konnte in FLOWFACT nicht geändert werden: %s', $medium->dateiname_original, $exception->getMessage());

                continue;
            }

            $medium->flowfact_titel = $titel;
            $medium->saveQuietly();
        }
    }

    private function titelWeichtAb(ListingMedia $medium): bool
    {
        return $this->normalisierterTitel($medium->titel) !== $this->normalisierterTitel($medium->flowfact_titel);
    }

    private function normalisierterTitel(?string $titel): ?string
    {
        if ($titel === null) {
            return null;
        }

        $titel = trim($titel);

        return $titel === '' ? null : $titel;
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
        $items = $this->items($entityId, $multimedia);

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

    private function dateinameBasis(Listing $listing, ListingMedia $medium): string
    {
        return sprintf('%s-%d-%s', $listing->uuid, $medium->id, substr((string) $medium->pruefsumme_sha256, 0, 12));
    }
}
