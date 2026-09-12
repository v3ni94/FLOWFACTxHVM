<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaTyp;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Nimmt eine hochgeladene Datei entgegen, prüft sie serverseitig und legt sie
 * außerhalb des Webroots auf der Disk "media" ab (Datenvertrag Abschnitt 2.6,
 * ADR-012). Typprüfung erfolgt über den Inhalt (finfo), nicht über die
 * Dateiendung. Bilder erhalten zusätzlich eine Vorschauvariante.
 */
final class MediaUploadService
{
    /**
     * @var array<string, string>
     */
    private const array ENDUNGEN = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * @var list<string>
     */
    private const array BILD_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly ImagePreviewGenerator $previewGenerator = new ImagePreviewGenerator,
    ) {}

    /**
     * @throws MediaUploadException
     */
    public function store(Listing $listing, UploadedFile $file, MediaTyp $typ): ListingMedia
    {
        $inhalt = file_get_contents($file->getRealPath());

        if ($inhalt === false) {
            throw new MediaUploadException('Die Datei konnte nicht gelesen werden.');
        }

        $groesse = strlen($inhalt);
        $maxBytes = (int) config('media.max_file_bytes');

        if ($groesse > $maxBytes) {
            throw new MediaUploadException(sprintf(
                'Die Datei "%s" ist zu groß (maximal %d MB je Datei).',
                $file->getClientOriginalName(),
                (int) round($maxBytes / 1024 / 1024),
            ));
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($inhalt) ?: null;

        /** @var list<string> $erlaubteMimes */
        $erlaubteMimes = config('media.allowed_mimes');

        if ($mime === null || ! in_array($mime, $erlaubteMimes, true)) {
            throw new MediaUploadException(sprintf(
                'Die Datei "%s" hat einen nicht unterstützten Dateityp.',
                $file->getClientOriginalName(),
            ));
        }

        $maxDateien = (int) config('media.max_files_per_listing');

        if ($listing->media()->count() >= $maxDateien) {
            throw new MediaUploadException(sprintf(
                'Es sind maximal %d Dateien je Objekt erlaubt.',
                $maxDateien,
            ));
        }

        $pruefsumme = hash('sha256', $inhalt);

        $vorhanden = ListingMedia::query()
            ->where('listing_id', $listing->id)
            ->where('pruefsumme_sha256', $pruefsumme)
            ->exists();

        if ($vorhanden) {
            throw new MediaUploadException('Diese Datei ist bereits vorhanden');
        }

        $breite = null;
        $hoehe = null;

        if (in_array($mime, self::BILD_MIMES, true)) {
            $abmessungen = @getimagesizefromstring($inhalt);

            if ($abmessungen === false) {
                throw new MediaUploadException(sprintf(
                    'Die Datei "%s" konnte nicht als Bild gelesen werden.',
                    $file->getClientOriginalName(),
                ));
            }

            [$breite, $hoehe] = $abmessungen;

            // Prüfbericht 2026-09-11, Befund 9: Pixelgrenze anhand der reinen
            // Kopfdaten prüfen, bevor irgendetwas dekodiert wird (getimagesizefromstring
            // liest nur den Header). Ohne diese Grenze allokieren
            // ImagePreviewGenerator und ImageResizer beim Dekodieren mehrere
            // GB RAM je Datei, unabhängig vom PHP-Speicherlimit.
            $this->pruefePixelgrenze($breite, $hoehe);
        }

        $endung = self::ENDUNGEN[$mime] ?? 'bin';
        $verzeichnis = 'listings/'.$listing->uuid;
        $dateiname = Str::random(32).'.'.$endung;
        $pfad = $verzeichnis.'/'.$dateiname;

        Storage::disk('media')->put($pfad, $inhalt);

        if (in_array($mime, self::BILD_MIMES, true)) {
            $vorschau = $this->previewGenerator->generate($inhalt);

            if ($vorschau !== null) {
                $vorschauPfad = $verzeichnis.'/'.pathinfo($dateiname, PATHINFO_FILENAME).'_vorschau.jpg';
                Storage::disk('media')->put($vorschauPfad, $vorschau);
            }
        }

        $naechsteSortierung = (int) ($listing->media()->max('sortierung') ?? -1) + 1;

        if ($listing->media()->count() === 0) {
            $naechsteSortierung = 0;
        }

        return $listing->media()->create([
            'typ' => $typ,
            'dateiname_original' => $file->getClientOriginalName(),
            'pfad' => $pfad,
            'mime' => $mime,
            'groesse_bytes' => $groesse,
            'breite' => $breite,
            'hoehe' => $hoehe,
            'sortierung' => $naechsteSortierung,
            'titel' => null,
            'im_inserat' => true,
            'pruefsumme_sha256' => $pruefsumme,
        ]);
    }

    /**
     * @throws MediaUploadException
     */
    private function pruefePixelgrenze(int $breite, int $hoehe): void
    {
        $maxSeite = (int) config('media.max_side');
        $maxPixel = (int) config('media.max_pixels');

        if ($breite > $maxSeite || $hoehe > $maxSeite || $breite * $hoehe > $maxPixel) {
            throw new MediaUploadException(sprintf(
                'Das Bild ist zu groß (maximal %s Pixel Kantenlänge und %s Millionen Pixel).',
                number_format($maxSeite, 0, ',', '.'),
                number_format($maxPixel / 1_000_000, 0, ',', '.'),
            ));
        }
    }

    public function delete(ListingMedia $media): void
    {
        Storage::disk('media')->delete($media->pfad);

        $vorschauPfad = $this->vorschauPfad($media);

        if ($vorschauPfad !== null) {
            Storage::disk('media')->delete($vorschauPfad);
        }

        $media->delete();
    }

    /**
     * Dreht die Vorschau eines Mediums um 90 Grad (Masterprompt-Abgleich B.1
     * Schritt 6, B.2). Nur ein Metadatum (0, 90, 180, 270); die Pixeldaten
     * selbst bleiben unverändert, die Vorschau dreht sich über die CSS-Klasse
     * .rot-90 usw. (docs/ui-klassen.md). Die Pixeldrehung beim Export in
     * FLOWFACT übernimmt der Connector.
     */
    public function rotate(ListingMedia $media, int $gradVorzeichen): void
    {
        $neu = (($media->rotation + $gradVorzeichen) % 360 + 360) % 360;

        $media->update(['rotation' => $neu]);
    }

    public function vorschauPfad(ListingMedia $media): ?string
    {
        if (! in_array($media->mime, self::BILD_MIMES, true)) {
            return null;
        }

        $verzeichnis = pathinfo($media->pfad, PATHINFO_DIRNAME);
        $dateiname = pathinfo($media->pfad, PATHINFO_FILENAME);
        $vorschauPfad = $verzeichnis.'/'.$dateiname.'_vorschau.jpg';

        return Storage::disk('media')->exists($vorschauPfad) ? $vorschauPfad : null;
    }
}
