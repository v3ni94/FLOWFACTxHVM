<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Media;

use App\Enums\MediaTyp;
use App\Models\Listing;
use App\Services\Media\MediaUploadException;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MediaUploadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_gueltiges_bild_wird_gespeichert_und_erhaelt_eine_vorschau(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $medium = $service->store($listing, UploadedFile::fake()->image('titelbild.jpg', 1200, 800), MediaTyp::Bild);

        $this->assertSame('image/jpeg', $medium->mime);
        $this->assertSame(0, $medium->sortierung);
        $this->assertSame(1200, $medium->breite);
        $this->assertSame(800, $medium->hoehe);
        Storage::disk('media')->assertExists($medium->pfad);
        $this->assertNotNull($service->vorschauPfad($medium));
    }

    public function test_eine_textdatei_mit_jpg_endung_wird_anhand_des_inhalts_abgelehnt(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $pfad = tempnam(sys_get_temp_dir(), 'flow');
        file_put_contents($pfad, str_repeat('Das ist keine Bilddatei.', 20));
        $datei = new UploadedFile($pfad, 'fake.jpg', 'image/jpeg', null, true);

        $this->expectException(MediaUploadException::class);

        $service->store($listing, $datei, MediaTyp::Bild);
    }

    public function test_eine_dublette_wird_abgelehnt(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $service->store($listing, UploadedFile::fake()->image('bild.png', 400, 300), MediaTyp::Bild);

        $this->expectException(MediaUploadException::class);
        $this->expectExceptionMessage('Diese Datei ist bereits vorhanden');

        $service->store($listing, UploadedFile::fake()->image('bild.png', 400, 300), MediaTyp::Bild);
    }

    public function test_die_maximale_anzahl_je_objekt_wird_durchgesetzt(): void
    {
        Storage::fake('media');
        config(['media.max_files_per_listing' => 2]);

        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $service->store($listing, UploadedFile::fake()->image('a.jpg', 100, 100), MediaTyp::Bild);
        $service->store($listing, UploadedFile::fake()->image('b.jpg', 120, 90), MediaTyp::Bild);

        $this->expectException(MediaUploadException::class);

        $service->store($listing, UploadedFile::fake()->image('c.jpg', 130, 95), MediaTyp::Bild);
    }

    public function test_eine_zu_grosse_datei_wird_abgelehnt(): void
    {
        Storage::fake('media');
        config(['media.max_file_bytes' => 1024]);

        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $this->expectException(MediaUploadException::class);

        $service->store($listing, UploadedFile::fake()->image('gross.jpg', 2000, 2000), MediaTyp::Bild);
    }

    /**
     * Prüfbericht 2026-09-11, Befund 9: Ohne Pixelgrenze passiert ein
     * präpariertes PNG mit vorgetäuschten Abmessungen finfo und
     * getimagesizefromstring; ImagePreviewGenerator und ImageResizer
     * dekodieren es danach vollständig und allokieren mehrere GB RAM,
     * unabhängig vom PHP-Speicherlimit. Die Grenze muss anhand der reinen
     * Kopfdaten greifen, vor jeder Dekodierung.
     */
    public function test_ein_bild_mit_zu_grosser_kantenlaenge_wird_ohne_dekodierung_abgelehnt(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $pfad = tempnam(sys_get_temp_dir(), 'flowbombe');
        file_put_contents($pfad, self::bombePng(11_000, 10));
        $datei = new UploadedFile($pfad, 'kantenlaenge.png', 'image/png', null, true);

        $this->expectException(MediaUploadException::class);
        $this->expectExceptionMessage('Das Bild ist zu groß (maximal 10.000 Pixel Kantenlänge und 40 Millionen Pixel).');

        $service->store($listing, $datei, MediaTyp::Bild);
    }

    public function test_ein_bild_mit_zu_vielen_pixeln_wird_ohne_dekodierung_abgelehnt(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $pfad = tempnam(sys_get_temp_dir(), 'flowbombe');
        file_put_contents($pfad, self::bombePng(7_000, 7_000));
        $datei = new UploadedFile($pfad, 'pixelbombe.png', 'image/png', null, true);

        $this->expectException(MediaUploadException::class);
        $this->expectExceptionMessage('Das Bild ist zu groß (maximal 10.000 Pixel Kantenlänge und 40 Millionen Pixel).');

        $service->store($listing, $datei, MediaTyp::Bild);
    }

    public function test_die_pixelgrenze_ist_ueber_die_konfiguration_einstellbar(): void
    {
        Storage::fake('media');
        config(['media.max_side' => 500, 'media.max_pixels' => 100_000]);

        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $pfad = tempnam(sys_get_temp_dir(), 'flowbombe');
        file_put_contents($pfad, self::bombePng(600, 10));
        $datei = new UploadedFile($pfad, 'ueber_konfiguriertem_limit.png', 'image/png', null, true);

        $this->expectException(MediaUploadException::class);

        $service->store($listing, $datei, MediaTyp::Bild);
    }

    /**
     * Erzeugt ein gültiges, aber winziges PNG, dessen IHDR beliebige
     * Abmessungen vortäuscht (wie poc10_bombe.php aus dem Prüfbericht).
     */
    private static function bombePng(int $breite, int $hoehe): string
    {
        $zeile = "\0".str_repeat("\0", $breite * 4);
        $deflate = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
        $raw = '';

        for ($y = 0; $y < $hoehe; $y++) {
            $raw .= deflate_add($deflate, $zeile, ZLIB_NO_FLUSH);
        }

        $raw .= deflate_add($deflate, '', ZLIB_FINISH);

        $chunk = static fn (string $typ, string $daten): string => pack('N', strlen($daten)).$typ.$daten.pack('N', crc32($typ.$daten));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $breite, $hoehe, 8, 6, 0, 0, 0))
            .$chunk('IDAT', $raw)
            .$chunk('IEND', '');
    }

    public function test_ein_pdf_dokument_erhaelt_keine_vorschau(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $pfad = tempnam(sys_get_temp_dir(), 'flowpdf');
        file_put_contents($pfad, "%PDF-1.4\n%Testinhalt für die Erkennung über finfo.\n%%EOF");
        $datei = new UploadedFile($pfad, 'grundriss.pdf', 'application/pdf', null, true);

        $medium = $service->store($listing, $datei, MediaTyp::Dokument);

        $this->assertSame('application/pdf', $medium->mime);
        $this->assertNull($service->vorschauPfad($medium));
    }

    /**
     * Masterprompt Abschnitt 14: SVG und andere Vektor- oder sonstige
     * Bildformate sind über die Mime-Positivliste bereits ausgeschlossen; die
     * Ablehnung erfolgt mit einer klaren deutschen Meldung.
     */
    public function test_eine_svg_datei_wird_abgelehnt(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $pfad = tempnam(sys_get_temp_dir(), 'flowsvg');
        file_put_contents($pfad, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>');
        $datei = new UploadedFile($pfad, 'grundriss.svg', 'image/svg+xml', null, true);

        $this->expectException(MediaUploadException::class);
        $this->expectExceptionMessage('nicht unterstützten Dateityp');

        $service->store($listing, $datei, MediaTyp::Bild);
    }

    /**
     * Masterprompt Abschnitt 14: enthält ein hochgeladenes JPEG einen
     * GPS-EXIF-Block, wird das Flag enthaelt_standortdaten gesetzt, damit die
     * Oberfläche darauf hinweisen kann, dass die Angaben beim Export entfernt
     * werden.
     */
    public function test_ein_jpeg_mit_gps_exif_wird_als_standortdaten_enthaltend_markiert(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $pfad = tempnam(sys_get_temp_dir(), 'flowgps').'.jpg';
        file_put_contents($pfad, self::jpegMitGpsExif());
        $datei = new UploadedFile($pfad, 'standort.jpg', 'image/jpeg', null, true);

        $medium = $service->store($listing, $datei, MediaTyp::Bild);

        self::assertTrue($medium->enthaelt_standortdaten);
    }

    public function test_ein_jpeg_ohne_exif_wird_nicht_als_standortdaten_enthaltend_markiert(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $medium = $service->store($listing, UploadedFile::fake()->image('ohne-exif.jpg', 400, 300), MediaTyp::Bild);

        self::assertFalse($medium->enthaelt_standortdaten);
    }

    /**
     * Erzeugt ein gültiges JPEG (über GD) und fügt unmittelbar nach dem
     * SOI-Marker einen minimalen APP1-EXIF-Block mit GPS-IFD ein
     * (GPSLatitudeRef "N", GPSLatitude 51/1 0/1 0/1), damit exif_read_data
     * einen GPS-Abschnitt liefert.
     */
    private static function jpegMitGpsExif(): string
    {
        $bild = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($bild, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($bild);

        // TIFF-Header (Intel/little-endian), IFD0 mit einem Verweis auf die
        // GPS-IFD (Tag 0x8825), GPS-IFD mit GPSLatitudeRef und GPSLatitude.
        $tiffHeader = 'II'.pack('v', 42).pack('V', 8);

        $ifd0 = pack('v', 1)
            .pack('v', 0x8825).pack('v', 4).pack('V', 1).pack('V', 26)
            .pack('V', 0);

        $gpsLatitudeRefWert = "N\x00\x00\x00";
        $gpsIfd = pack('v', 2)
            .pack('v', 1).pack('v', 2).pack('V', 2).$gpsLatitudeRefWert
            .pack('v', 2).pack('v', 5).pack('V', 3).pack('V', 56)
            .pack('V', 0);

        $gpsLatitudeDaten = pack('V', 51).pack('V', 1).pack('V', 0).pack('V', 1).pack('V', 0).pack('V', 1);

        $tiff = $tiffHeader.$ifd0.$gpsIfd.$gpsLatitudeDaten;
        $exifPayload = "Exif\x00\x00".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($exifPayload) + 2).$exifPayload;

        // Direkt nach dem SOI-Marker (erste zwei Byte) einfügen, vor allen
        // vom GD-Encoder erzeugten Markern.
        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    public function test_delete_entfernt_original_und_vorschau_und_den_datensatz(): void
    {
        Storage::fake('media');
        $listing = Listing::factory()->create();
        $service = new MediaUploadService;

        $medium = $service->store($listing, UploadedFile::fake()->image('d.jpg', 500, 500), MediaTyp::Bild);
        $vorschauPfad = $service->vorschauPfad($medium);
        $this->assertNotNull($vorschauPfad);

        $service->delete($medium);

        Storage::disk('media')->assertMissing($medium->pfad);
        Storage::disk('media')->assertMissing($vorschauPfad);
        $this->assertDatabaseMissing('listing_media', ['id' => $medium->id]);
    }
}
