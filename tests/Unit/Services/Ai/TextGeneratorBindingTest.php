<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Domain\Settings\SettingsRepository;
use App\Services\Ai\AnthropicTextGenerator;
use App\Services\Ai\FakeTextGenerator;
use App\Services\Ai\TextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bindung des TextGenerator-Interfaces (ADR-009, AppServiceProvider).
 */
final class TextGeneratorBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ohne_konfiguration_wird_der_platzhalter_verwendet(): void
    {
        self::assertInstanceOf(FakeTextGenerator::class, app(TextGenerator::class));
    }

    public function test_anthropic_ohne_schluessel_bleibt_beim_platzhalter(): void
    {
        app(SettingsRepository::class)->set('ki.provider', 'anthropic');

        self::assertInstanceOf(FakeTextGenerator::class, app(TextGenerator::class));
    }

    public function test_anthropic_mit_schluessel_verwendet_den_echten_generator(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('ki.provider', 'anthropic');
        $settings->setSecret('ki.api_key', 'sk-test-1234567890');

        self::assertInstanceOf(AnthropicTextGenerator::class, app(TextGenerator::class));
    }

    public function test_fake_bleibt_gewaehlt_trotz_hinterlegtem_schluessel(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('ki.provider', 'fake');
        $settings->setSecret('ki.api_key', 'sk-test-1234567890');

        self::assertInstanceOf(FakeTextGenerator::class, app(TextGenerator::class));
    }
}
