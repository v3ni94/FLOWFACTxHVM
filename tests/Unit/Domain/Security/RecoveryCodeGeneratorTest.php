<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Security;

use App\Domain\Security\RecoveryCodeGenerator;
use PHPUnit\Framework\TestCase;

final class RecoveryCodeGeneratorTest extends TestCase
{
    public function test_generate_liefert_acht_codes_im_erwarteten_format(): void
    {
        $codes = RecoveryCodeGenerator::generate();

        self::assertCount(8, $codes);

        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $code);
            self::assertStringNotContainsString('0', $code);
            self::assertStringNotContainsString('O', $code);
            self::assertStringNotContainsString('1', $code);
            self::assertStringNotContainsString('I', $code);
            self::assertStringNotContainsString('L', $code);
        }
    }

    public function test_generate_liefert_keine_duplikate_in_der_praxis(): void
    {
        $codes = RecoveryCodeGenerator::generate(8);

        self::assertCount(8, array_unique($codes));
    }

    public function test_normalize_vereinheitlicht_klein_und_grossschreibung_sowie_trennzeichen(): void
    {
        self::assertSame('ABCDE-FGHJK', RecoveryCodeGenerator::normalize('abcde-fghjk'));
        self::assertSame('ABCDE-FGHJK', RecoveryCodeGenerator::normalize('abcdefghjk'));
        self::assertSame('ABCDE-FGHJK', RecoveryCodeGenerator::normalize(' abcde fghjk '));
    }

    public function test_single_liefert_ein_einzelnes_gueltiges_code_format(): void
    {
        self::assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', RecoveryCodeGenerator::single());
    }
}
