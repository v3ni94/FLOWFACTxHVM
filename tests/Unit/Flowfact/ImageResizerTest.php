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
}
