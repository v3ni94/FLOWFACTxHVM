<?php

declare(strict_types=1);

namespace App\Domain\Security;

use InvalidArgumentException;

/**
 * Zeitbasiertes Einmalpasswort nach RFC 6238 (TOTP), aufbauend auf RFC 4226
 * (HOTP), ohne Framework- und ohne Paketabhaengigkeit.
 *
 * PARAMETER, verbindlich (Masterprompt 8.1)
 *
 *   Algorithmus     HMAC-SHA1, der Standard der Authenticator-Apps
 *   Stellen         6
 *   Zeitfenster     30 Sekunden
 *   Toleranz        ein Fenster vor und ein Fenster nach der aktuellen Zeit,
 *                   um Uhrabweichungen zwischen Server und Telefon
 *                   auszugleichen. Damit ist ein Code hoechstens 90 Sekunden
 *                   gueltig.
 *
 * KORREKTHEITSNACHWEIS
 *
 * Die Umsetzung ist gegen die Testvektoren aus RFC 6238, Anhang B geprueft
 * (Schluessel "12345678901234567890", SHA1). Der Test liegt in
 * tests/Unit/Domain/Security/TimeBasedOneTimePasswordTest.php. Nur damit ist
 * belegt, dass die Klasse nicht bloss zu sich selbst konsistent ist.
 *
 * SICHERHEIT
 *
 *  - Der Vergleich des Codes erfolgt mit hash_equals, also ohne
 *    Laufzeitunterschied je Ziffer.
 *  - Das Geheimnis wird nie protokolliert und nie in eine Ausnahme geschrieben.
 *  - Die Klasse enthaelt bewusst keine Ratenbegrenzung. Diese gehoert in die
 *    Anwendungsschicht, weil sie Sitzung und Konto kennt.
 */
final class TimeBasedOneTimePassword
{
    public const string ALGORITHMUS = 'sha1';

    public const int STELLEN = 6;

    public const int ZEITFENSTER_SEKUNDEN = 30;

    /**
     * Toleranz in Zeitfenstern in jede Richtung.
     */
    public const int TOLERANZ_SCHRITTE = 1;

    /**
     * Laenge des Geheimnisses in Bytes. RFC 4226 empfiehlt mindestens 128 Bit
     * und bevorzugt 160 Bit, also 20 Bytes. Das entspricht 32 Base32-Zeichen.
     */
    public const int GEHEIMNIS_BYTES = 20;

    /**
     * Erzeugt ein neues Geheimnis in Base32.
     */
    public static function generateSecret(int $bytes = self::GEHEIMNIS_BYTES): string
    {
        if ($bytes < 16) {
            throw new InvalidArgumentException('Ein TOTP-Geheimnis muss mindestens 16 Bytes lang sein.');
        }

        return Base32::encode(random_bytes($bytes));
    }

    /**
     * Code zu einem Zeitpunkt in Sekunden seit dem 01.01.1970.
     *
     * @param  string  $secret  Geheimnis in Base32
     */
    public function codeAt(string $secret, int $timestamp, int $stellen = self::STELLEN): string
    {
        return $this->codeForCounter($secret, intdiv($timestamp, self::ZEITFENSTER_SEKUNDEN), $stellen);
    }

    /**
     * HOTP-Code zu einem Zaehlerstand nach RFC 4226.
     */
    public function codeForCounter(string $secret, int $counter, int $stellen = self::STELLEN): string
    {
        if ($stellen < 6 || $stellen > 10) {
            throw new InvalidArgumentException('Die Anzahl der Stellen muss zwischen 6 und 10 liegen.');
        }

        $schluessel = Base32::decode($secret);

        if ($schluessel === '') {
            throw new InvalidArgumentException('Das Geheimnis ist leer.');
        }

        // Zaehler als 8 Byte in Netzwerkbyteordnung.
        $zaehler = pack('J', $counter);

        $hmac = hash_hmac(self::ALGORITHMUS, $zaehler, $schluessel, true);

        // Dynamic Truncation nach RFC 4226, Abschnitt 5.3.
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;

        $wert = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        $modulo = 10 ** $stellen;

        return str_pad((string) ($wert % $modulo), $stellen, '0', STR_PAD_LEFT);
    }

    /**
     * Aktueller Code.
     */
    public function currentCode(string $secret, ?int $timestamp = null): string
    {
        return $this->codeAt($secret, $timestamp ?? time());
    }

    /**
     * Prueft einen eingegebenen Code gegen das Geheimnis.
     *
     * Geprueft werden das aktuelle Zeitfenster sowie je $toleranz Fenster davor
     * und danach. Ein Code aus dem uebernaechsten Fenster wird abgelehnt.
     */
    public function verify(
        string $secret,
        string $code,
        ?int $timestamp = null,
        int $toleranz = self::TOLERANZ_SCHRITTE,
    ): bool {
        $eingabe = preg_replace('/\s+/', '', $code) ?? '';

        if ($eingabe === '' || preg_match('/^\d+$/', $eingabe) !== 1) {
            return false;
        }

        if (strlen($eingabe) !== self::STELLEN) {
            return false;
        }

        if (! Base32::isValid($secret)) {
            return false;
        }

        $zaehler = intdiv($timestamp ?? time(), self::ZEITFENSTER_SEKUNDEN);
        $toleranz = max(0, $toleranz);

        $treffer = false;

        // Bewusst ohne vorzeitigen Abbruch, damit die Laufzeit nicht verraet,
        // welches Fenster gepasst hat.
        for ($schritt = -$toleranz; $schritt <= $toleranz; $schritt++) {
            $erwartet = $this->codeForCounter($secret, $zaehler + $schritt);

            if (hash_equals($erwartet, $eingabe)) {
                $treffer = true;
            }
        }

        return $treffer;
    }

    /**
     * otpauth-URI zum Einlesen in eine Authenticator-App.
     *
     * Es wird bewusst KEIN QR-Code-Bild erzeugt. Dafuer waere ein zusaetzliches
     * Paket noetig. Die URI und der abtippbare Schluessel genuegen, jede
     * verbreitete App kann beides verarbeiten.
     */
    public function otpauthUri(string $issuer, string $account, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        $parameter = [
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHMUS),
            'digits' => (string) self::STELLEN,
            'period' => (string) self::ZEITFENSTER_SEKUNDEN,
        ];

        return 'otpauth://totp/'.$label.'?'.http_build_query($parameter, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Geheimnis in Vierergruppen, damit es fehlerfrei abgetippt werden kann.
     */
    public function formatSecret(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }
}
