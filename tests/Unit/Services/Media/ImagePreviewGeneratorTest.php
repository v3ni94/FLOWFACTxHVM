<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Media;

use App\Services\Media\ImagePreviewGenerator;
use Tests\TestCase;

/**
 * Prüfbericht 2026-09-11, Befund 9: ImagePreviewGenerator ist die zweite
 * Verteidigungslinie gegen Dekompressionsbomben, falls generate() ohne die
 * vorgelagerte Prüfung aus MediaUploadService aufgerufen wird (wie in
 * poc10_bombe.php). Ohne eigene Pixelgrenze dekodiert imagecreatefromstring
 * das Bild vollständig und allokiert mehrere GB RAM, unabhängig vom
 * PHP-Speicherlimit.
 */
final class ImagePreviewGeneratorTest extends TestCase
{
    public function test_ein_bild_ueber_der_pixelgrenze_wird_nicht_dekodiert_und_liefert_null(): void
    {
        $generator = new ImagePreviewGenerator;

        $ergebnis = $generator->generate(self::bombePng(7_000, 7_000));

        $this->assertNull($ergebnis);
    }

    public function test_ein_bild_innerhalb_der_grenze_erhaelt_weiterhin_eine_vorschau(): void
    {
        $generator = new ImagePreviewGenerator;

        $ergebnis = $generator->generate(self::bombePng(100, 100));

        $this->assertNotNull($ergebnis);
    }

    /**
     * Masterprompt Abschnitt 14: die Vorschau entsteht über GD (imagejpeg),
     * das grundsätzlich keine Metadaten aus der Quelle übernimmt. Ein Bild
     * mit GPS-EXIF darf daher in der Vorschau keine EXIF-Daten mehr tragen.
     */
    public function test_die_vorschau_enthaelt_keine_exif_daten(): void
    {
        if (! extension_loaded('exif')) {
            self::markTestSkipped('Die exif-Erweiterung ist nicht geladen.');
        }

        $generator = new ImagePreviewGenerator;

        $vorschau = $generator->generate(self::jpegMitGpsExif());
        self::assertNotNull($vorschau);

        $pfad = tempnam(sys_get_temp_dir(), 'flowvorschau').'.jpg';
        file_put_contents($pfad, $vorschau);

        $exif = @exif_read_data($pfad, 'GPS', true);

        self::assertTrue($exif === false || empty($exif['GPS']));
    }

    /**
     * Baut ein gültiges JPEG mit einem minimalen APP1-EXIF-Block samt
     * GPS-IFD (GPSLatitudeRef "N", GPSLatitude 51/1 0/1 0/1), unmittelbar
     * nach dem SOI-Marker eingefügt.
     */
    private static function jpegMitGpsExif(): string
    {
        $bild = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($bild, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($bild);

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

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
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
}
