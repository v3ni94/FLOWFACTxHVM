<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Security;

use App\Domain\Security\Base32;
use App\Domain\Security\TimeBasedOneTimePassword;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * TOTP nach RFC 6238.
 *
 * NACHWEIS DER KORREKTHEIT
 *
 * Geprueft wird gegen die Testvektoren aus RFC 6238, Anhang B: Schluessel
 * "12345678901234567890" (20 ASCII-Zeichen, also 160 Bit), Algorithmus SHA1,
 * Zeitfenster 30 Sekunden. Die dort abgedruckten Codes haben acht Stellen, der
 * sechsstellige Code ist deren letzte Stellen, weil die Ziffernzahl allein die
 * Modulooperation am Ende bestimmt. Beides wird geprueft.
 *
 * Damit ist belegt, dass die Umsetzung dem Standard folgt und nicht nur zu sich
 * selbst konsistent ist.
 */
final class TimeBasedOneTimePasswordTest extends TestCase
{
    /**
     * Der Schluessel der RFC in Base32.
     */
    private const string RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private TimeBasedOneTimePassword $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = new TimeBasedOneTimePassword;
    }

    public function test_der_base32_schluessel_der_rfc_entspricht_dem_ascii_schluessel(): void
    {
        self::assertSame('12345678901234567890', Base32::decode(self::RFC_SECRET));
        self::assertSame(self::RFC_SECRET, Base32::encode('12345678901234567890'));
    }

    /**
     * @return list<array{int, string, string}>
     */
    public static function rfcVektoren(): array
    {
        // Zeitpunkt, achtstelliger Code aus RFC 6238 Anhang B, sechsstellig.
        return [
            [59, '94287082', '287082'],
            [1111111109, '07081804', '081804'],
            [1111111111, '14050471', '050471'],
            [1234567890, '89005924', '005924'],
            [2000000000, '69279037', '279037'],
            [20000000000, '65353130', '353130'],
        ];
    }

    #[DataProvider('rfcVektoren')]
    public function test_die_testvektoren_aus_rfc_6238_stimmen_achtstellig(
        int $zeitpunkt,
        string $achtstellig,
    ): void {
        self::assertSame($achtstellig, $this->totp->codeAt(self::RFC_SECRET, $zeitpunkt, 8));
    }

    #[DataProvider('rfcVektoren')]
    public function test_die_testvektoren_aus_rfc_6238_stimmen_sechsstellig(
        int $zeitpunkt,
        string $achtstellig,
        string $sechsstellig,
    ): void {
        self::assertSame($sechsstellig, $this->totp->codeAt(self::RFC_SECRET, $zeitpunkt));
        self::assertSame($sechsstellig, substr($achtstellig, -6));
    }

    #[DataProvider('rfcVektoren')]
    public function test_die_pruefung_akzeptiert_den_code_der_rfc_zum_passenden_zeitpunkt(
        int $zeitpunkt,
        string $achtstellig,
        string $sechsstellig,
    ): void {
        self::assertTrue($this->totp->verify(self::RFC_SECRET, $sechsstellig, $zeitpunkt));
    }

    public function test_der_code_gilt_im_vorherigen_und_im_naechsten_zeitfenster(): void
    {
        $jetzt = 1_800_000_000;
        $fenster = TimeBasedOneTimePassword::ZEITFENSTER_SEKUNDEN;

        $vorher = $this->totp->codeAt(self::RFC_SECRET, $jetzt - $fenster);
        $aktuell = $this->totp->codeAt(self::RFC_SECRET, $jetzt);
        $nachher = $this->totp->codeAt(self::RFC_SECRET, $jetzt + $fenster);

        self::assertTrue($this->totp->verify(self::RFC_SECRET, $vorher, $jetzt));
        self::assertTrue($this->totp->verify(self::RFC_SECRET, $aktuell, $jetzt));
        self::assertTrue($this->totp->verify(self::RFC_SECRET, $nachher, $jetzt));
    }

    public function test_ein_code_aus_dem_uebernaechsten_zeitfenster_wird_abgelehnt(): void
    {
        $jetzt = 1_800_000_000;
        $fenster = TimeBasedOneTimePassword::ZEITFENSTER_SEKUNDEN;

        $zuAlt = $this->totp->codeAt(self::RFC_SECRET, $jetzt - 2 * $fenster);
        $zuNeu = $this->totp->codeAt(self::RFC_SECRET, $jetzt + 2 * $fenster);

        self::assertFalse($this->totp->verify(self::RFC_SECRET, $zuAlt, $jetzt));
        self::assertFalse($this->totp->verify(self::RFC_SECRET, $zuNeu, $jetzt));
    }

    public function test_ein_falscher_code_wird_abgelehnt(): void
    {
        $jetzt = 1_800_000_000;
        $richtig = $this->totp->codeAt(self::RFC_SECRET, $jetzt);

        self::assertFalse($this->totp->verify(self::RFC_SECRET, '000000', $jetzt));
        self::assertFalse($this->totp->verify(self::RFC_SECRET, '', $jetzt));
        self::assertFalse($this->totp->verify(self::RFC_SECRET, 'abcdef', $jetzt));
        self::assertFalse($this->totp->verify(self::RFC_SECRET, substr($richtig, 0, 5), $jetzt));
        self::assertFalse($this->totp->verify(self::RFC_SECRET, $richtig.'1', $jetzt));
    }

    public function test_ein_code_mit_leerzeichen_wird_akzeptiert(): void
    {
        $jetzt = 1_800_000_000;
        $code = $this->totp->codeAt(self::RFC_SECRET, $jetzt);

        $mitLeerzeichen = substr($code, 0, 3).' '.substr($code, 3);

        self::assertTrue($this->totp->verify(self::RFC_SECRET, $mitLeerzeichen, $jetzt));
    }

    public function test_ein_erzeugtes_geheimnis_ist_zufaellig_und_hat_die_erwartete_laenge(): void
    {
        $erstes = TimeBasedOneTimePassword::generateSecret();
        $zweites = TimeBasedOneTimePassword::generateSecret();

        self::assertNotSame($erstes, $zweites);
        self::assertSame(32, strlen($erstes));
        self::assertTrue(Base32::isValid($erstes));
        self::assertSame(20, strlen(Base32::decode($erstes)));
    }

    public function test_ein_zu_kurzes_geheimnis_wird_abgelehnt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TimeBasedOneTimePassword::generateSecret(8);
    }

    public function test_die_pruefung_scheitert_bei_einem_geheimnis_ausserhalb_von_base32(): void
    {
        self::assertFalse($this->totp->verify('kein!base32', '123456', 1_800_000_000));
    }

    public function test_die_otpauth_uri_traegt_alle_parameter(): void
    {
        $uri = $this->totp->otpauthUri('Smart Abrechnen', 'anna.muster@beispiel.invalid', self::RFC_SECRET);

        self::assertStringStartsWith('otpauth://totp/Smart%20Abrechnen:anna.muster%40beispiel.invalid?', $uri);
        self::assertStringContainsString('secret='.self::RFC_SECRET, $uri);
        self::assertStringContainsString('algorithm=SHA1', $uri);
        self::assertStringContainsString('digits=6', $uri);
        self::assertStringContainsString('period=30', $uri);
        self::assertStringContainsString('issuer=Smart%20Abrechnen', $uri);
    }

    public function test_das_geheimnis_wird_in_vierergruppen_ausgegeben(): void
    {
        self::assertSame(
            'GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ',
            $this->totp->formatSecret(self::RFC_SECRET),
        );
    }

    public function test_base32_kodiert_und_dekodiert_verlustfrei(): void
    {
        foreach (['a', 'ab', 'abc', 'abcd', 'abcde', 'Betriebskosten 2026', random_bytes(20)] as $klartext) {
            self::assertSame($klartext, Base32::decode(Base32::encode($klartext)));
        }
    }

    public function test_base32_toleriert_kleinschreibung_auffuellung_und_trennzeichen(): void
    {
        $kodiert = Base32::encode('abcde', padding: true);

        self::assertSame('abcde', Base32::decode(strtolower($kodiert)));
        self::assertSame('abcde', Base32::decode(chunk_split($kodiert, 4, ' ')));
    }

    public function test_base32_weist_ein_fremdes_zeichen_ab(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Base32::decode('ABCD1');
    }
}
