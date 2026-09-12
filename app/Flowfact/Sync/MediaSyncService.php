<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Domain\Listing\ListingSnapshot;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Medienabgleich je Objekt auf Basis der Freigabeversion (docs/connector.md
 * Abschnitt 3 Schritt 7, flowfact-api.md Abschnitt 8, Masterprompt Abschnitt 14
 * und 19).
 *
 * Maßgeblich ist medien_json der Freigabeversion (ListingSnapshot::medien):
 * Dort stehen ausschließlich Medien, die im Inserat und freigegeben sind, mit
 * Reihenfolge, Titel und Drehung zum Zeitpunkt der Freigabe. Die Datei selbst
 * (Pfad, MIME, Prüfsumme) und die FLOWFACT-Item-ID kommen aus listing_media.
 *
 * - Bilder und Grundrisse werden mit eingebrannter Drehung in Portalgröße
 *   hochgeladen (ImageResizer, ohne EXIF), Dokumente und Energieausweise
 *   unverändert in die Dokumentkategorie des Albums; fehlt sie, entsteht eine
 *   Warnung und das Dokument bleibt lokal.
 * - Medien mit FLOWFACT-Item, die nicht mehr in der Freigabe stehen (aus dem
 *   Inserat genommen, Freigabe entzogen, gelöscht), werden in FLOWFACT
 *   gelöscht (Befund 4). Eine geänderte Drehung gegenüber der zuletzt
 *   übertragenen Version löscht das Item ebenfalls und lädt es neu hoch.
 * - Deterministischer Dateiname je Medium; vorhandene Items werden über den
 *   Dateinamen übernommen statt erneut hochgeladen (Befund 6).
 * - Nach jedem Upload wird die Lease über den Herzschlag verlängert (Befund 7).
 */
final class MediaSyncService
{
    public const string DISK = 'media';

    public const string WARNUNG_KEIN_DOKUMENTALBUM = 'Dokument "%s" wurde nicht übertragen: das FLOWFACT-Album hat keine Kategorie für Dokumente.';

    /** @var array<string, array<string, array<string, mixed>>> Items der Entität je Kategorie und ID, einmal je Lauf gelesen */
    private array $items = [];

    public function __construct(
        private readonly MultimediaService $multimedia,
        private readonly ImageResizer $resizer,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * @param  ListingSnapshot  $snapshot  Momentaufnahme der Freigabeversion, deren Medien übertragen werden
     * @param  bool  $inhaltGeaendert  true, wenn sich der Inhalts-Hash geändert hat; dann wird die Reihenfolge immer neu gesetzt
     * @param  Closure|null  $heartbeat  wird nach jedem übertragenen Medium aufgerufen (Lease verlängern)
     * @param  ListingSnapshot|null  $vorherige  zuletzt übertragene Version (Erkennung geänderter Drehung)
     */
    public function sync(Listing $listing, ListingSnapshot $snapshot, string $schema, string $entityId, ?User $user = null, ?Carbon $deadline = null, bool $inhaltGeaendert = false, ?Closure $heartbeat = null, ?ListingSnapshot $vorherige = null): MediaSyncResult
    {
        $multimedia = $this->multimedia->scoped($listing, $user);
        $warnungen = [];
        $this->items = [];

        // Immer frisch lesen: FLOWFACT-IDs und Titel können sich seit dem
        // Laden der Relation geändert haben.
        $listing->load('media');

        $geloescht = $this->verarbeiteLoeschungen($listing, $multimedia, $warnungen);
        $geloescht += $this->verarbeiteEntfernte($listing, $snapshot, $vorherige, $multimedia, $warnungen);

        $eintraege = $this->eintraege($listing, $snapshot, $warnungen);
        $bilder = array_values(array_filter($eintraege, fn (array $e): bool => $e['istBild']));

        if ($eintraege === []) {
            return new MediaSyncResult($warnungen, true, 0, $geloescht);
        }

        $offen = array_values(array_filter($eintraege, fn (array $e): bool => $e['medium']->flowfact_multimedia_id === null));
        $titelGeaendert = array_filter($eintraege, fn (array $e): bool => $e['medium']->flowfact_multimedia_id !== null && $this->titelWeichtAb($e));

        // Ohne offene Uploads, Löschungen, Titel- oder Inhaltsänderungen gibt es
        // nichts abzugleichen; das spart die Album- und Zuordnungsaufrufe.
        if ($offen === [] && $geloescht === 0 && $titelGeaendert === [] && ! $inhaltGeaendert) {
            return new MediaSyncResult($warnungen, true, 0, $geloescht);
        }

        $hochgeladen = 0;
        $vollstaendig = true;
        $album = null;

        if ($offen !== []) {
            $album = $this->album($schema, $multimedia);

            if ($album === null) {
                $warnungen[] = sprintf('Kein Album mit Bildkategorie im Schema "%s" gefunden, Bilder wurden nicht übertragen.', $schema);
            } else {
                foreach ($offen as $eintrag) {
                    if (! $eintrag['istBild'] && $album['dokumente'] === null) {
                        $warnungen[] = sprintf(self::WARNUNG_KEIN_DOKUMENTALBUM, $eintrag['medium']->dateiname_original);

                        continue;
                    }

                    if ($deadline !== null && Carbon::now()->greaterThanOrEqualTo($deadline)) {
                        $vollstaendig = false;
                        break;
                    }

                    if ($this->uebernimmVorhandenesItem($listing, $eintrag, $entityId, $multimedia) || $this->upload($listing, $eintrag, $schema, $entityId, $album, $multimedia, $warnungen)) {
                        $hochgeladen++;

                        if ($heartbeat !== null) {
                            $heartbeat();
                        }
                    }
                }
            }
        }

        $this->gleicheTitelAb($eintraege, $multimedia, $warnungen);

        $hatUebertrageneBilder = array_filter($bilder, fn (array $e): bool => $e['medium']->flowfact_multimedia_id !== null) !== [];

        if ($hatUebertrageneBilder && ($hochgeladen > 0 || $geloescht > 0 || $inhaltGeaendert)) {
            $album ??= $this->album($schema, $multimedia);

            if ($album === null) {
                $warnungen[] = sprintf('Kein Album mit Bildkategorie im Schema "%s" gefunden, die Bildreihenfolge wurde nicht gesetzt.', $schema);
            } else {
                $this->setzeReihenfolge($bilder, $schema, $entityId, $album, $multimedia, $warnungen);
            }
        }

        return new MediaSyncResult($warnungen, $vollstaendig, $hochgeladen, $geloescht);
    }

    /**
     * Offene Arbeit gegenüber der Freigabeversion: fehlende Uploads, Medien
     * mit FLOWFACT-Item außerhalb der Freigabe, abweichende Titel oder
     * vorgemerkte Löschungen.
     */
    public function hatOffeneArbeit(Listing $listing, ListingSnapshot $snapshot): bool
    {
        $listing->load('media');
        $freigegebeneIds = $this->freigegebeneIds($snapshot);

        foreach ($listing->media as $medium) {
            $inFreigabe = in_array((int) $medium->getKey(), $freigegebeneIds, true);

            if ($inFreigabe && $medium->flowfact_multimedia_id === null) {
                return true;
            }

            if (! $inFreigabe && $medium->flowfact_multimedia_id !== null) {
                return true;
            }
        }

        foreach ($this->eintraege($listing, $snapshot) as $eintrag) {
            if ($eintrag['medium']->flowfact_multimedia_id !== null && $this->titelWeichtAb($eintrag)) {
                return true;
            }
        }

        return ListingMediaDeletion::query()->where('listing_id', $listing->id)->exists();
    }

    /**
     * Deterministischer Dateiname in FLOWFACT (Befund 6):
     * "<listing uuid>-<media id>-<erste 12 Hex der SHA-256>[-r<Drehung>].<ext>".
     * Die Drehung ist Teil des Namens, damit ein gedrehtes Bild nicht mit dem
     * ungedrehten Item verwechselt wird.
     */
    public function dateiname(Listing $listing, ListingMedia $medium, string $erweiterung, int $rotation = 0): string
    {
        return $this->dateinameBasis($listing, $medium, $rotation).'.'.$erweiterung;
    }

    /**
     * Medien der Freigabeversion mit ihrer lokalen Datei, in der Reihenfolge
     * der Freigabe (Bilder zuerst).
     *
     * @param  list<string>  $warnungen
     * @return list<array{medium: ListingMedia, titel: string|null, rotation: int, typ: string, istBild: bool, sortierung: int}>
     */
    private function eintraege(Listing $listing, ListingSnapshot $snapshot, array &$warnungen = []): array
    {
        $rows = $listing->media->keyBy(fn (ListingMedia $m): int => (int) $m->getKey());
        $eintraege = [];

        foreach ($snapshot->medien as $daten) {
            $id = isset($daten['id']) && is_numeric($daten['id']) ? (int) $daten['id'] : null;

            if ($id === null) {
                continue;
            }

            if (($daten['freigegeben'] ?? true) === false) {
                continue;
            }

            /** @var ListingMedia|null $medium */
            $medium = $rows->get($id);

            if ($medium === null) {
                $warnungen[] = sprintf('Medium %d aus der Freigabe existiert lokal nicht mehr und wurde übersprungen.', $id);

                continue;
            }

            $typ = (string) ($daten['typ'] ?? $medium->typ->value);
            $istBild = in_array($typ, [MediaTyp::Bild->value, MediaTyp::Grundriss->value], true);

            $eintraege[] = [
                'medium' => $medium,
                'titel' => $this->normalisierterTitel(isset($daten['titel']) && is_string($daten['titel']) ? $daten['titel'] : null),
                'rotation' => (int) ($daten['rotation'] ?? 0),
                'typ' => $typ,
                'istBild' => $istBild,
                'sortierung' => (int) ($daten['sortierung'] ?? 0),
            ];
        }

        usort($eintraege, function (array $a, array $b): int {
            $rangA = $a['typ'] === MediaTyp::Bild->value ? 0 : ($a['istBild'] ? 1 : 2);
            $rangB = $b['typ'] === MediaTyp::Bild->value ? 0 : ($b['istBild'] ? 1 : 2);

            return [$rangA, $a['sortierung'], (int) $a['medium']->getKey()] <=> [$rangB, $b['sortierung'], (int) $b['medium']->getKey()];
        });

        return $eintraege;
    }

    /**
     * @return list<int>
     */
    private function freigegebeneIds(ListingSnapshot $snapshot): array
    {
        $ids = [];

        foreach ($snapshot->medien as $daten) {
            if (isset($daten['id']) && is_numeric($daten['id']) && ($daten['freigegeben'] ?? true) !== false) {
                $ids[] = (int) $daten['id'];
            }
        }

        return $ids;
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
     * Medien mit FLOWFACT-Item, die nicht mehr in der Freigabe stehen (aus dem
     * Inserat genommen, Freigabe entzogen, Dokument ohne Freigabe), sowie
     * Medien, deren Drehung sich gegenüber der zuletzt übertragenen Version
     * geändert hat: Item löschen, lokale ID leeren (Befund 4, Masterprompt 14).
     *
     * @param  list<string>  $warnungen
     */
    private function verarbeiteEntfernte(Listing $listing, ListingSnapshot $snapshot, ?ListingSnapshot $vorherige, MultimediaService $multimedia, array &$warnungen): int
    {
        $anzahl = 0;
        $freigegeben = $this->freigegebeneIds($snapshot);
        $rotationJetzt = $this->rotationen($snapshot);
        $rotationVorher = $vorherige !== null ? $this->rotationen($vorherige) : [];

        foreach ($listing->media as $medium) {
            if ($medium->flowfact_multimedia_id === null) {
                continue;
            }

            $id = (int) $medium->getKey();
            $inFreigabe = in_array($id, $freigegeben, true);
            $gedreht = $inFreigabe && array_key_exists($id, $rotationVorher) && ($rotationVorher[$id] !== ($rotationJetzt[$id] ?? 0));

            if ($inFreigabe && ! $gedreht) {
                continue;
            }

            $itemId = (string) $medium->flowfact_multimedia_id;

            try {
                $multimedia->deleteItem($itemId);
            } catch (NotFoundException) {
                // Item existiert nicht mehr, lokal trotzdem bereinigen.
            } catch (AuthenticationException|RateLimitException $exception) {
                throw $exception;
            } catch (FlowfactException $exception) {
                $warnungen[] = sprintf('Medium "%s" konnte in FLOWFACT nicht gelöscht werden: %s', $medium->dateiname_original, $exception->getMessage());

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
     * @return array<int, int>
     */
    private function rotationen(ListingSnapshot $snapshot): array
    {
        $rotationen = [];

        foreach ($snapshot->medien as $daten) {
            if (isset($daten['id']) && is_numeric($daten['id'])) {
                $rotationen[(int) $daten['id']] = (int) ($daten['rotation'] ?? 0);
            }
        }

        return $rotationen;
    }

    /**
     * Album und Kategorien je Schema, einmal ermittelt und in den Einstellungen
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
                'dokumente' => isset($gespeichert['dokumente']) && (string) $gespeichert['dokumente'] !== '' ? (string) $gespeichert['dokumente'] : null,
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
     * Items der Entität je Kategorie (IMAGE oder DOCUMENT), einmal je Lauf
     * gelesen und um neu registrierte Items ergänzt.
     *
     * @return array<string, array<string, mixed>>
     */
    private function items(string $entityId, string $kategorie, MultimediaService $multimedia): array
    {
        if (! array_key_exists($kategorie, $this->items)) {
            $this->items[$kategorie] = [];

            foreach ($multimedia->items($entityId, $kategorie) as $item) {
                if (isset($item['id']) && is_scalar($item['id'])) {
                    $this->items[$kategorie][(string) $item['id']] = $item;
                }
            }
        }

        return $this->items[$kategorie];
    }

    /**
     * Befund 6: Ist in FLOWFACT bereits ein Item mit dem deterministischen
     * Dateinamen dieses Mediums vorhanden (z. B. nach einer Zeitüberschreitung
     * beim Registrieren), wird dessen ID übernommen statt erneut hochzuladen.
     *
     * @param  array{medium: ListingMedia, titel: string|null, rotation: int, typ: string, istBild: bool, sortierung: int}  $eintrag
     */
    private function uebernimmVorhandenesItem(Listing $listing, array $eintrag, string $entityId, MultimediaService $multimedia): bool
    {
        $medium = $eintrag['medium'];
        $basis = $this->dateinameBasis($listing, $medium, $eintrag['rotation']);
        $kategorie = $eintrag['istBild'] ? 'IMAGE' : 'DOCUMENT';

        foreach ($this->items($entityId, $kategorie, $multimedia) as $id => $item) {
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
     * @param  array{medium: ListingMedia, titel: string|null, rotation: int, typ: string, istBild: bool, sortierung: int}  $eintrag
     * @param  array{album: string, bilder: string, dokumente: string|null}  $album
     * @param  list<string>  $warnungen
     */
    private function upload(Listing $listing, array $eintrag, string $schema, string $entityId, array $album, MultimediaService $multimedia, array &$warnungen): bool
    {
        $medium = $eintrag['medium'];
        $inhalt = Storage::disk(self::DISK)->get($medium->pfad);

        if ($inhalt === null || $inhalt === '') {
            $warnungen[] = sprintf('Datei für Medium "%s" nicht gefunden, Upload übersprungen.', $medium->dateiname_original);

            return false;
        }

        if ($eintrag['istBild']) {
            try {
                $bild = $this->resizer->resize($inhalt, (string) $medium->mime, $eintrag['rotation']);
            } catch (Throwable $exception) {
                $warnungen[] = sprintf('Bild "%s" konnte nicht verarbeitet werden: %s', $medium->dateiname_original, $exception->getMessage());

                return false;
            }

            $content = $bild['content'];
            $mime = $bild['mime'];
            $erweiterung = $bild['extension'];
            $kategorie = $album['bilder'];
        } else {
            $content = $inhalt;
            $mime = (string) ($medium->mime ?: 'application/octet-stream');
            $erweiterung = strtolower(pathinfo((string) $medium->dateiname_original, PATHINFO_EXTENSION)) ?: 'pdf';
            $kategorie = (string) $album['dokumente'];
        }

        $dateiname = $this->dateiname($listing, $medium, $erweiterung, $eintrag['rotation']);
        $groesse = strlen($content);

        $presigned = $multimedia->presignedUrl($schema, $entityId, $mime, $dateiname, $groesse);

        if ($presigned['presignedUrl'] === '' || $presigned['itemLink'] === '') {
            $warnungen[] = sprintf('FLOWFACT hat für Medium "%s" keine Upload-URL geliefert.', $medium->dateiname_original);

            return false;
        }

        $multimedia->uploadBinary($presigned['presignedUrl'], $content, $mime);

        $body = [
            'contentType' => $mime,
            'fileName' => $dateiname,
            'fileSize' => $groesse,
            'itemLink' => $presigned['itemLink'],
            'albumAssignments' => [
                ['albumName' => $album['album'], 'categories' => [$kategorie]],
            ],
        ];

        if ($eintrag['titel'] !== null) {
            $body['title'] = $eintrag['titel'];
        }

        $item = $multimedia->registerItem($schema, $entityId, $body);

        if (! isset($item['id']) || ! is_scalar($item['id'])) {
            $warnungen[] = sprintf('FLOWFACT hat für Medium "%s" keine Item-ID geliefert.', $medium->dateiname_original);

            return false;
        }

        $kategorieSchluessel = $eintrag['istBild'] ? 'IMAGE' : 'DOCUMENT';

        if (array_key_exists($kategorieSchluessel, $this->items)) {
            $this->items[$kategorieSchluessel][(string) $item['id']] = $item;
        }

        // saveQuietly: die FLOWFACT-ID ist kein Inhalt des Inserats und darf
        // keine Änderungsmarkierung auslösen.
        $medium->flowfact_multimedia_id = (string) $item['id'];
        $medium->flowfact_titel = $eintrag['titel'];
        $medium->saveQuietly();

        return true;
    }

    /**
     * Befund 4: geänderte Titel (aus der Freigabe) über PATCH /items/{id}
     * (JSON-Patch) nachziehen und den übertragenen Stand in flowfact_titel merken.
     *
     * @param  list<array{medium: ListingMedia, titel: string|null, rotation: int, typ: string, istBild: bool, sortierung: int}>  $eintraege
     * @param  list<string>  $warnungen
     */
    private function gleicheTitelAb(array $eintraege, MultimediaService $multimedia, array &$warnungen): void
    {
        foreach ($eintraege as $eintrag) {
            $medium = $eintrag['medium'];

            if ($medium->flowfact_multimedia_id === null || ! $this->titelWeichtAb($eintrag)) {
                continue;
            }

            $titel = $eintrag['titel'];
            $patch = $titel === null
                ? [['op' => 'remove', 'path' => '/title']]
                : [['op' => 'replace', 'path' => '/title', 'value' => $titel]];

            try {
                $multimedia->patchItem((string) $medium->flowfact_multimedia_id, $patch);
            } catch (AuthenticationException|RateLimitException $exception) {
                throw $exception;
            } catch (FlowfactException $exception) {
                $warnungen[] = sprintf('Titel von Medium "%s" konnte in FLOWFACT nicht geändert werden: %s', $medium->dateiname_original, $exception->getMessage());

                continue;
            }

            $medium->flowfact_titel = $titel;
            $medium->saveQuietly();
        }
    }

    /**
     * @param  array{medium: ListingMedia, titel: string|null, rotation: int, typ: string, istBild: bool, sortierung: int}  $eintrag
     */
    private function titelWeichtAb(array $eintrag): bool
    {
        return $eintrag['titel'] !== $this->normalisierterTitel($eintrag['medium']->flowfact_titel);
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
     * @param  list<array{medium: ListingMedia, titel: string|null, rotation: int, typ: string, istBild: bool, sortierung: int}>  $bilder
     * @param  array{album: string, bilder: string, dokumente: string|null}  $album
     * @param  list<string>  $warnungen
     */
    private function setzeReihenfolge(array $bilder, string $schema, string $entityId, array $album, MultimediaService $multimedia, array &$warnungen): void
    {
        $items = $this->items($entityId, 'IMAGE', $multimedia);

        $zuordnung = [];
        $position = 0;

        foreach ($bilder as $eintrag) {
            $medium = $eintrag['medium'];

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

    private function dateinameBasis(Listing $listing, ListingMedia $medium, int $rotation = 0): string
    {
        $basis = sprintf('%s-%d-%s', $listing->uuid, $medium->id, substr((string) $medium->pruefsumme_sha256, 0, 12));

        return $rotation !== 0 ? $basis.'-r'.$rotation : $basis;
    }
}
