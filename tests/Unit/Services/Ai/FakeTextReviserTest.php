<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Models\Listing;
use App\Services\Ai\FakeTextReviser;
use App\Services\Ai\TextGenerationException;
use App\Services\Ai\TextReviser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FakeTextReviserTest extends TestCase
{
    use RefreshDatabase;

    public function test_modell_heisst_vorlage(): void
    {
        self::assertSame('vorlage', (new FakeTextReviser)->modell());
    }

    public function test_kuerzer_behaelt_den_ersten_teil_der_saetze(): void
    {
        $listing = Listing::factory()->make();
        $ergebnis = (new FakeTextReviser)->revise($listing, 'Satz eins. Satz zwei. Satz drei. Satz vier. Satz fünf.', TextReviser::KUERZER);

        self::assertStringContainsString('Satz eins.', $ergebnis);
        self::assertStringNotContainsString('Satz fünf.', $ergebnis);
    }

    public function test_eine_unbekannte_anweisung_wirft_eine_ausnahme(): void
    {
        $listing = Listing::factory()->make();

        $this->expectException(TextGenerationException::class);

        (new FakeTextReviser)->revise($listing, 'Text.', 'unbekannt');
    }
}
