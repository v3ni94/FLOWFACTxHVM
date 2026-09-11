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
