<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;

/**
 * Base32 nach RFC 4648 ohne externe Bibliothek.
 *
 * WARUM EIGENE UMSETZUNG
 *
 * Authenticator-Apps erwarten das TOTP-Geheimnis in Base32. PHP bringt dafuer
 * keine Funktion mit, und es darf kein zusaetzliches Paket aufgenommen werden.
 * Der Algorithmus ist klein und vollstaendig durch Testvektoren abgedeckt.
 *
 * Es wird ausschliesslich das Standardalphabet verwendet, also die
 * Grossbuchstaben A bis Z und die Ziffern 2 bis 7. Die Auffuellung mit dem
 * Zeichen "=" ist beim Kodieren abschaltbar, weil otpauth-URIs sie nicht
 * verwenden. Beim Dekodieren werden Auffuellzeichen, Leerzeichen und
 * Kleinbuchstaben toleriert, damit ein manuell abgetipptes Geheimnis nicht an
 * einer Formatie scheitert.
 */
final class Base32
{
    public const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Kodiert beliebige Bytes.
     */
    public static function encode(string $bytes, bool $padding = false): string
    {
        if ($bytes === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $ausgabe = '';

        foreach (str_split($bits, 5) as $gruppe) {
            $gruppe = str_pad($gruppe, 5, '0', STR_PAD_RIGHT);
            $ausgabe .= self::ALPHABET[bindec($gruppe)];
        }

        if ($padding) {
            $rest = strlen($ausgabe) % 8;

            if ($rest !== 0) {
                $ausgabe .= str_repeat('=', 8 - $rest);
            }
        }

        return $ausgabe;
    }

    /**
     * Dekodiert eine Base32-Zeichenkette zu Bytes.
     *
     * @throws InvalidArgumentException bei einem Zeichen ausserhalb des Alphabets
     */
    public static function decode(string $kodiert): string
    {
        $bereinigt = strtoupper(str_replace(['=', ' ', '-', "\t", "\n", "\r"], '', $kodiert));

        if ($bereinigt === '') {
            return '';
        }

        $bits = '';

        foreach (str_split($bereinigt) as $zeichen) {
            $index = strpos(self::ALPHABET, $zeichen);

            if ($index === false) {
                throw new InvalidArgumentException('Der Schlüssel enthält ein Zeichen, das nicht zu Base32 gehört.');
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $ausgabe = '';

        // Nur vollstaendige Bytes werden uebernommen. Die letzten Bits einer
        // unvollstaendigen Gruppe sind Auffuellung und werden verworfen.
        foreach (str_split($bits, 8) as $byteBits) {
            if (strlen($byteBits) < 8) {
                break;
            }

            $ausgabe .= chr((int) bindec($byteBits));
        }

        return $ausgabe;
    }

    /**
     * Prueft, ob eine Zeichenkette ausschliesslich Base32-Zeichen enthaelt.
     */
    public static function isValid(string $kodiert): bool
    {
        $bereinigt = strtoupper(str_replace(['=', ' ', '-'], '', $kodiert));

        return $bereinigt !== '' && strspn($bereinigt, self::ALPHABET) === strlen($bereinigt);
    }
}
