<?php

declare(strict_types=1);

namespace App\Flowfact\Services;

use RuntimeException;

/**
 * Bringt Bilder mit GD in Portalgröße (ADR-012): längste Seite höchstens
 * 2000 Pixel, JPEG Qualität 85. PNG mit Transparenz bleibt PNG, alles
 * andere wird JPEG.
 *
 * Masterprompt Abschnitt 14: Die im Assistenten erfasste Drehung (0, 90, 180,
 * 270 Grad im Uhrzeigersinn) wird vor dem Verkleinern eingebrannt. Weil GD
 * das Bild vollständig neu kodiert, enthält die Ausgabe keine Metadaten der
 * Quelle (kein EXIF-APP1-Segment, keine GPS- oder Kameradaten); ein Test
 * belegt das durch Suche nach der Kennung "Exif\0\0" in den Bytes.
 */
final class ImageResizer
{
    public function __construct(
        private readonly int $maxSide = 2000,
        private readonly int $jpegQuality = 85,
    ) {}

    /**
     * @param  int  $rotation  Drehung im Uhrzeigersinn in Grad (0, 90, 180, 270)
     * @return array{content: string, mime: string, extension: string, width: int, height: int}
     */
    public function resize(string $binary, string $mime, int $rotation = 0): array
    {
        // GD meldet unlesbare Daten als Warnung; hier soll eine Ausnahme entstehen.
        set_error_handler(static fn (): bool => true);

        try {
            $bild = imagecreatefromstring($binary);
        } finally {
            restore_error_handler();
        }

        if ($bild === false) {
            throw new RuntimeException('Die Bilddatei konnte nicht gelesen werden.');
        }

        $transparent = $mime === 'image/png' && $this->hatTransparenz($binary);
        $bild = $this->drehen($bild, $rotation, $transparent);

        $breite = imagesx($bild);
        $hoehe = imagesy($bild);
        $laengste = max($breite, $hoehe);

        if ($laengste > $this->maxSide) {
            $faktor = $this->maxSide / $laengste;
            $neueBreite = max(1, (int) round($breite * $faktor));
            $neueHoehe = max(1, (int) round($hoehe * $faktor));

            $skaliert = imagecreatetruecolor($neueBreite, $neueHoehe);

            if ($transparent) {
                imagealphablending($skaliert, false);
                imagesavealpha($skaliert, true);
                $durchsichtig = imagecolorallocatealpha($skaliert, 0, 0, 0, 127);
                imagefilledrectangle($skaliert, 0, 0, $neueBreite, $neueHoehe, $durchsichtig);
            } else {
                $weiss = imagecolorallocate($skaliert, 255, 255, 255);
                imagefilledrectangle($skaliert, 0, 0, $neueBreite, $neueHoehe, $weiss);
            }

            imagecopyresampled($skaliert, $bild, 0, 0, 0, 0, $neueBreite, $neueHoehe, $breite, $hoehe);
            imagedestroy($bild);
            $bild = $skaliert;
            $breite = $neueBreite;
            $hoehe = $neueHoehe;
        }

        ob_start();

        if ($transparent) {
            imagesavealpha($bild, true);
            imagepng($bild, null, 6);
            $ausgabe = ['mime' => 'image/png', 'extension' => 'png'];
        } else {
            imageinterlace($bild, true);
            imagejpeg($bild, null, $this->jpegQuality);
            $ausgabe = ['mime' => 'image/jpeg', 'extension' => 'jpg'];
        }

        $inhalt = (string) ob_get_clean();
        imagedestroy($bild);

        return $ausgabe + ['content' => $inhalt, 'width' => $breite, 'height' => $hoehe];
    }

    /**
     * Drehung im Uhrzeigersinn. imagerotate() dreht gegen den Uhrzeigersinn,
     * daher wird der Gegenwinkel übergeben. Unbekannte Werte gelten als 0.
     */
    private function drehen(\GdImage $bild, int $rotation, bool $transparent): \GdImage
    {
        $rotation = (($rotation % 360) + 360) % 360;

        if (! in_array($rotation, [90, 180, 270], true)) {
            return $bild;
        }

        $hintergrund = $transparent
            ? imagecolorallocatealpha($bild, 0, 0, 0, 127)
            : imagecolorallocate($bild, 255, 255, 255);

        $gedreht = imagerotate($bild, (float) (360 - $rotation), (int) $hintergrund);

        if ($gedreht === false) {
            throw new RuntimeException('Die Bilddatei konnte nicht gedreht werden.');
        }

        imagedestroy($bild);

        if ($transparent) {
            imagealphablending($gedreht, false);
            imagesavealpha($gedreht, true);
        }

        return $gedreht;
    }

    /**
     * PNG-Header: Farbtyp 4 (Graustufen mit Alpha) oder 6 (RGBA) oder ein
     * tRNS-Chunk bedeuten Transparenz. Das ist billig und braucht kein
     * Pixel-Scanning.
     */
    private function hatTransparenz(string $png): bool
    {
        if (strlen($png) < 26 || substr($png, 1, 3) !== 'PNG') {
            return false;
        }

        $farbtyp = ord($png[25]);

        if ($farbtyp === 4 || $farbtyp === 6) {
            return true;
        }

        return str_contains($png, 'tRNS');
    }
}
