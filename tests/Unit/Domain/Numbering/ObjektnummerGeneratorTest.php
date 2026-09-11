<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Numbering;

use App\Domain\Numbering\ObjektnummerGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ObjektnummerGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_erste_nummer_eines_jahres_hat_das_format_mf_jjjj_0001(): void
    {
        $nummer = (new ObjektnummerGenerator)->next(2026);

        $this->assertSame('MF-2026-0001', $nummer);
    }

    public function test_die_zweite_nummer_eines_jahres_zaehlt_hoch(): void
    {
        $generator = new ObjektnummerGenerator;

        $generator->next(2026);
        $zweite = $generator->next(2026);

        $this->assertSame('MF-2026-0002', $zweite);
    }

    public function test_jedes_jahr_hat_einen_eigenen_zaehler(): void
    {
        $generator = new ObjektnummerGenerator;

        $generator->next(2026);
        $generator->next(2026);
        $ersteDesFolgejahres = $generator->next(2027);

        $this->assertSame('MF-2027-0001', $ersteDesFolgejahres);
    }

    public function test_ohne_angabe_wird_das_aktuelle_jahr_verwendet(): void
    {
        $nummer = (new ObjektnummerGenerator)->next();

        $this->assertStringStartsWith('MF-'.now()->format('Y').'-', $nummer);
    }
}
