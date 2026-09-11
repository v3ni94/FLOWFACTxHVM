<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\InvalidRentInputException;
use App\Domain\Listing\RentCalculator;
use App\Domain\Listing\RentHinweis;
use App\Enums\HeizkostenVersorgung;
use PHPUnit\Framework\TestCase;

/**
 * Prüffälle der Warmmietenberechnung, wörtlich aus Datenvertrag Abschnitt 3.
 */
final class RentCalculatorTest extends TestCase
{
    private RentCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new RentCalculator;
    }

    public function test_fall_a_zentral_ohne_enthalten_addiert_heizkosten(): void
    {
        $ergebnis = $this->calculator->calculate(80_000, 20_000, 10_000, false, HeizkostenVersorgung::Zentral);

        $this->assertSame(110_000, $ergebnis->warmmieteCent);
        $this->assertSame([], $ergebnis->hinweise);
    }

    public function test_fall_b_zentral_mit_enthalten_addiert_heizkosten_nicht_erneut(): void
    {
        $ergebnis = $this->calculator->calculate(80_000, 30_000, 10_000, true, HeizkostenVersorgung::Zentral);

        $this->assertSame(110_000, $ergebnis->warmmieteCent);
        $this->assertSame([], $ergebnis->hinweise);
    }

    public function test_fall_c_zentral_ohne_heizkosten_gibt_hinweis(): void
    {
        $ergebnis = $this->calculator->calculate(80_000, 20_000, null, false, HeizkostenVersorgung::Zentral);

        $this->assertSame(100_000, $ergebnis->warmmieteCent);
        $this->assertTrue($ergebnis->hatHinweis(RentHinweis::HeizkostenNichtAngegeben));
    }

    public function test_fall_d_dezentral_gibt_versorgerhinweis(): void
    {
        $ergebnis = $this->calculator->calculate(80_000, 15_000, null, false, HeizkostenVersorgung::Dezentral);

        $this->assertSame(95_000, $ergebnis->warmmieteCent);
        $this->assertTrue($ergebnis->hatHinweis(RentHinweis::HeizkostenBeimVersorger));
    }

    public function test_fall_e_dezentral_mit_heizkosten_ist_ungueltig(): void
    {
        $this->expectException(InvalidRentInputException::class);

        $this->calculator->calculate(80_000, 15_000, 5_000, false, HeizkostenVersorgung::Dezentral);
    }

    public function test_fall_f_enthalten_mit_zu_hohen_heizkosten_ist_ungueltig(): void
    {
        $this->expectException(InvalidRentInputException::class);

        $this->calculator->calculate(80_000, 20_000, 25_000, true, HeizkostenVersorgung::Zentral);
    }

    public function test_negative_kaltmiete_ist_ungueltig(): void
    {
        $this->expectException(InvalidRentInputException::class);

        $this->calculator->calculate(-1, 20_000, 10_000, false, HeizkostenVersorgung::Zentral);
    }

    public function test_negative_nebenkosten_ist_ungueltig(): void
    {
        $this->expectException(InvalidRentInputException::class);

        $this->calculator->calculate(80_000, -1, 10_000, false, HeizkostenVersorgung::Zentral);
    }

    public function test_negative_heizkosten_ist_ungueltig(): void
    {
        $this->expectException(InvalidRentInputException::class);

        $this->calculator->calculate(80_000, 20_000, -1, false, HeizkostenVersorgung::Zentral);
    }
}
