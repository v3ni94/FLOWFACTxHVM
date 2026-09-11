<?php

declare(strict_types=1);

namespace App\Domain\Security;

/**
 * Wiederherstellungscodes fuer den Zweitfaktor.
 *
 * Acht Einmalcodes, je zwei Gruppen aus fuenf Zeichen. Das Alphabet laesst die
 * leicht verwechselbaren Zeichen 0, O, 1, I und L weg, weil die Codes von Hand
 * abgeschrieben und wieder eingegeben werden.
 *
 * Die Codes entstehen ausschliesslich aus random_bytes. Die Speicherung erfolgt
 * einzeln gehasht in der Anwendungsschicht, siehe
 * App\Application\Account\TwoFactorAuthentication. Der Klartext verlaesst diese
 * Klasse genau einmal und wird nie gespeichert und nie protokolliert.
 */
final class RecoveryCodeGenerator
{
    public const string ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const int ANZAHL = 8;

    public const int GRUPPENLAENGE = 5;

    public const int GRUPPEN = 2;

    /**
     * @return list<string>
     */
    public static function generate(int $anzahl = self::ANZAHL): array
    {
        $codes = [];

        for ($i = 0; $i < $anzahl; $i++) {
            $codes[] = self::single();
        }

        return $codes;
    }

    public static function single(): string
    {
        $gruppen = [];

        for ($g = 0; $g < self::GRUPPEN; $g++) {
            $gruppe = '';

            for ($z = 0; $z < self::GRUPPENLAENGE; $z++) {
                $gruppe .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }

            $gruppen[] = $gruppe;
        }

        return implode('-', $gruppen);
    }

    /**
     * Vereinheitlicht eine Eingabe, damit Klein- und Grossschreibung sowie
     * fehlende oder zusaetzliche Trennzeichen nicht zum Fehlschlag fuehren.
     */
    public static function normalize(string $eingabe): string
    {
        $zeichen = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $eingabe) ?? '');

        if (strlen($zeichen) !== self::GRUPPEN * self::GRUPPENLAENGE) {
            return $zeichen;
        }

        return implode('-', str_split($zeichen, self::GRUPPENLAENGE));
    }
}
