<?php

declare(strict_types=1);

namespace Tests\Unit\Flowfact;

use App\Flowfact\Services\ImageResizer;
use PHPUnit\Framework\TestCase;

final class ImageResizerTest extends TestCase
{
    public function test_grosse_bilder_werden_auf_die_laengste_seite_verkleinert_und_als_jpeg_geliefert(): void
    {
        $bild = imagecreatetruecolor(3000, 1500);
        imagefilledrectangle($bild, 0, 0, 2999, 1499, imagecolorallocate($bild, 200, 100, 50));
        ob_start();
        imagepng($bild);
        $png = (string) ob_get_clean();

        $ergebnis = (new ImageResizer(2000, 85))->resize($png, 'image/png');

        self::assertSame('image/jpeg', $ergebnis['mime']);
        self::assertSame('jpg', $ergebnis['extension']);
        self::assertSame(2000, $ergebnis['width']);
        self::assertSame(1000, $ergebnis['height']);

        $info = getimagesizefromstring($ergebnis['content']);
        self::assertSame(IMAGETYPE_JPEG, $info[2]);
        self::assertSame([2000, 1000], [$info[0], $info[1]]);
    }

    public function test_kleine_bilder_behalten_ihre_groesse(): void
    {
        $bild = imagecreatetruecolor(640, 480);
        ob_start();
        imagejpeg($bild);
        $jpeg = (string) ob_get_clean();

        $ergebnis = (new ImageResizer)->resize($jpeg, 'image/jpeg');

        self::assertSame([640, 480], [$ergebnis['width'], $ergebnis['height']]);
        self::assertSame('image/jpeg', $ergebnis['mime']);
    }

    public function test_png_mit_transparenz_bleibt_png(): void
    {
        $bild = imagecreatetruecolor(2500, 100);
        imagealphablending($bild, false);
        imagesavealpha($bild, true);
        imagefilledrectangle($bild, 0, 0, 2499, 99, imagecolorallocatealpha($bild, 0, 0, 0, 127));
        ob_start();
        imagepng($bild);
        $png = (string) ob_get_clean();

        $ergebnis = (new ImageResizer)->resize($png, 'image/png');

        self::assertSame('image/png', $ergebnis['mime']);
        self::assertSame('png', $ergebnis['extension']);
        self::assertSame(2000, $ergebnis['width']);
        self::assertSame(IMAGETYPE_PNG, getimagesizefromstring($ergebnis['content'])[2]);
    }

    public function test_unlesbare_daten_werfen_eine_ausnahme(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ImageResizer)->resize('kein bild', 'image/jpeg');
    }

    /**
     * Masterprompt Abschnitt 14: Drehung im Uhrzeigersinn wird eingebrannt.
     * Ein 40x30-Bild mit einem hellen Pixel oben links landet nach 90 Grad
     * als 30x40 mit dem Pixel oben rechts.
     */
    public function test_drehung_wird_vor_dem_verkleinern_angewendet(): void
    {
        $bild = imagecreatetruecolor(40, 30);
        imagefilledrectangle($bild, 0, 0, 39, 29, imagecolorallocate($bild, 0, 0, 0));
        imagefilledrectangle($bild, 0, 0, 7, 7, imagecolorallocate($bild, 255, 255, 255));
        ob_start();
        imagepng($bild);
        $png = (string) ob_get_clean();

        $resizer = new ImageResizer;

        $gedreht = $resizer->resize($png, 'image/png', 90);
        self::assertSame([30, 40], [$gedreht['width'], $gedreht['height']]);
        $ausgabe = imagecreatefromstring($gedreht['content']);
        self::assertGreaterThan(200, imagecolorat($ausgabe, 27, 2) & 0xFF, 'Der helle Block liegt nach 90 Grad im Uhrzeigersinn oben rechts.');
        self::assertLessThan(50, imagecolorat($ausgabe, 2, 2) & 0xFF);

        $halb = $resizer->resize($png, 'image/png', 180);
        self::assertSame([40, 30], [$halb['width'], $halb['height']]);
        $ausgabe = imagecreatefromstring($halb['content']);
        self::assertGreaterThan(200, imagecolorat($ausgabe, 37, 27) & 0xFF, 'Nach 180 Grad liegt der Block unten rechts.');

        $dreiviertel = $resizer->resize($png, 'image/png', 270);
        self::assertSame([30, 40], [$dreiviertel['width'], $dreiviertel['height']]);
        $ausgabe = imagecreatefromstring($dreiviertel['content']);
        self::assertGreaterThan(200, imagecolorat($ausgabe, 2, 37) & 0xFF, 'Nach 270 Grad liegt der Block unten links.');

        $unveraendert = $resizer->resize($png, 'image/png', 0);
        self::assertSame([40, 30], [$unveraendert['width'], $unveraendert['height']]);
    }

    /**
     * Masterprompt Abschnitt 14: Die Ausgabe enthält keine EXIF-Daten. Das
     * Eingabebild trägt ein APP1-Segment mit der Kennung "Exif\0\0"; die
     * von GD neu kodierte Ausgabe (JPEG und PNG) enthält die Kennung nicht.
     */
    public function test_exif_segment_wird_entfernt(): void
    {
        $bild = imagecreatetruecolor(120, 80);
        imagefilledrectangle($bild, 0, 0, 119, 79, imagecolorallocate($bild, 120, 160, 200));
        ob_start();
        imagejpeg($bild, null, 90);
        $jpeg = (string) ob_get_clean();

        // Minimaler TIFF-Header (little endian, IFD ohne Einträge) als EXIF-Nutzlast.
        $exif = "Exif\0\0"."II*\0\x08\0\0\0\0\0";
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;
        $mitExif = substr($jpeg, 0, 2).$app1.substr($jpeg, 2);

        self::assertStringContainsString("Exif\0\0", $mitExif);
        self::assertNotFalse(imagecreatefromstring($mitExif), 'Das Testbild mit EXIF-Segment ist gültig.');

        $ergebnis = (new ImageResizer)->resize($mitExif, 'image/jpeg');

        self::assertSame('image/jpeg', $ergebnis['mime']);
        self::assertStringNotContainsString("Exif\0\0", $ergebnis['content']);
        self::assertStringNotContainsString("\xFF\xE1", substr($ergebnis['content'], 0, 64), 'Kein APP1-Segment am Dateianfang.');

        $gedreht = (new ImageResizer)->resize($mitExif, 'image/jpeg', 90);
        self::assertStringNotContainsString("Exif\0\0", $gedreht['content']);
        self::assertSame([80, 120], [$gedreht['width'], $gedreht['height']]);
    }
}
