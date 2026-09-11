<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_format_gibt_das_deutsche_format_mit_einheit_zurueck(): void
    {
        $this->assertSame('1.234,56 EUR', Money::format(123_456));
    }

    public function test_format_plain_gibt_das_deutsche_format_ohne_einheit_zurueck(): void
    {
        $this->assertSame('1.234,56', Money::formatPlain(123_456));
    }

    public function test_format_rundet_auf_zwei_nachkommastellen(): void
    {
        $this->assertSame('0,00 EUR', Money::format(0));
        $this->assertSame('100.000,00 EUR', Money::format(10_000_000));
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function parseFaelle(): array
    {
        return [
            'punkt als tausendertrennzeichen mit komma' => ['1.234,56', 123_456],
            'ohne punkt mit komma' => ['1234,56', 123_456],
            'nur ganzzahl' => ['1234', 123_400],
            'ganzzahl mit tausenderpunkt' => ['1.234', 123_400],
            'einstellige nachkommastelle' => ['5,5', 550],
            'null' => ['0', 0],
            'mehrere tausenderpunkte' => ['1.234.567', 123_456_700],
            'ambiger punkt mit zwei nachkommastellen ist ungueltig' => ['12.34', null],
            'leerer text ist ungueltig' => ['', null],
            'text ist ungueltig' => ['abc', null],
            'zu viele nachkommastellen sind ungueltig' => ['12,345', null],
        ];
    }

    #[DataProvider('parseFaelle')]
    public function test_parse_wandelt_deutsche_eingaben_in_cent_um(string $eingabe, ?int $erwartet): void
    {
        $this->assertSame($erwartet, Money::parse($eingabe));
    }
}
