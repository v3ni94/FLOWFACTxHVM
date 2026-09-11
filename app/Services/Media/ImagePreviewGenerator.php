<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Erzeugt eine Vorschauvariante eines Bildes mit GD (ADR-012): längste Seite
 * höchstens 480 Pixel, JPEG-Qualität 80. Läuft rein serverseitig ohne externe
 * Abhängigkeit, GD ist Teil der PHP-Standarderweiterungen.
 */
final class ImagePreviewGenerator
{
    private const int MAX_LAENGSTE_SEITE = 480;

    private const int JPEG_QUALITAET = 80;

    /**
     * @return string|null Binärinhalt des JPEG-Vorschaubilds, oder null wenn
     *                     aus dem Inhalt kein Bild erzeugt werden konnte.
     */
    public function generate(string $binaryContent): ?string
    {
        $quelle = @imagecreatefromstring($binaryContent);

        if ($quelle === false) {
            return null;
        }

        try {
            $breite = imagesx($quelle);
            $hoehe = imagesy($quelle);

            if ($breite < 1 || $hoehe < 1) {
                return null;
            }

            $faktor = min(1.0, self::MAX_LAENGSTE_SEITE / max($breite, $hoehe));
            $zielBreite = max(1, (int) round($breite * $faktor));
            $zielHoehe = max(1, (int) round($hoehe * $faktor));

            $ziel = imagecreatetruecolor($zielBreite, $zielHoehe);

            if ($ziel === false) {
                return null;
            }

            try {
                $weiss = imagecolorallocate($ziel, 255, 255, 255);
                imagefill($ziel, 0, 0, $weiss);

                imagecopyresampled(
                    $ziel,
                    $quelle,
                    0,
                    0,
                    0,
                    0,
                    $zielBreite,
                    $zielHoehe,
                    $breite,
                    $hoehe,
                );

                ob_start();
                $erfolgreich = imagejpeg($ziel, null, self::JPEG_QUALITAET);
                $inhalt = ob_get_clean();

                if (! $erfolgreich || $inhalt === false) {
                    return null;
                }

                return $inhalt;
            } finally {
                imagedestroy($ziel);
            }
        } finally {
            imagedestroy($quelle);
        }
    }
}
