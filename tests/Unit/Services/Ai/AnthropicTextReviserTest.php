<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Domain\Settings\SettingsRepository;
use App\Models\KiUsage;
use App\Models\Listing;
use App\Services\Ai\AnthropicTextReviser;
use App\Services\Ai\TextGenerationException;
use App\Services\Ai\TextReviser;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tests\TestCase;

final class AnthropicTextReviserTest extends TestCase
{
    use RefreshDatabase;

    private const string API_KEY = 'sk-test-KENNUNG-1234567890';

    public function test_kuerzer_liefert_den_ueberarbeiteten_text_und_speichert_den_verbrauch_mit_zweck(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock('Gekürzter Text.')],
            'usage' => self::usage(80, 20),
        ]);

        $ergebnis = $this->reviser($antwort)->revise($listing, 'Ein langer Ausgangstext mit vielen Sätzen.', TextReviser::KUERZER);

        self::assertSame('Gekürzter Text.', $ergebnis);

        $this->assertDatabaseHas('ki_usages', [
            'listing_id' => $listing->id,
            'zweck' => 'ueberarbeitung',
            'input_tokens' => 80,
            'output_tokens' => 20,
            'erfolgreich' => true,
            'fehler' => null,
        ]);
    }

    public function test_sachlicher_liefert_den_ueberarbeiteten_text(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock('Die Wohnung ist gepflegt.')],
        ]);

        $ergebnis = $this->reviser($antwort)->revise($listing, 'Die Wohnung ist wirklich traumhaft schön!', TextReviser::SACHLICHER);

        self::assertSame('Die Wohnung ist gepflegt.', $ergebnis);
    }

    public function test_sprachlich_liefert_den_ueberarbeiteten_text(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock('Die Wohnung ist gepflegt und hell.')],
        ]);

        $ergebnis = $this->reviser($antwort)->revise($listing, 'die wohnung ist gepflegt und hell', TextReviser::SPRACHLICH);

        self::assertSame('Die Wohnung ist gepflegt und hell.', $ergebnis);
    }

    public function test_ein_code_zaun_um_den_text_wird_entfernt(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock("```\nÜberarbeiteter Text.\n```")],
        ]);

        $ergebnis = $this->reviser($antwort)->revise($listing, 'Text.', TextReviser::SACHLICHER);

        self::assertSame('Überarbeiteter Text.', $ergebnis);
    }

    public function test_eine_unbekannte_anweisung_wirft_eine_ausnahme_ohne_aufruf(): void
    {
        $listing = Listing::factory()->create();
        $reviser = new AnthropicTextReviser($this->settings(), null);

        $this->expectException(TextGenerationException::class);

        $reviser->revise($listing, 'Text.', 'unbekannt');
    }

    public function test_eine_ablehnung_durch_den_anbieter_wirft_eine_deutsche_ausnahme_und_wird_protokolliert(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [],
            'stop_reason' => 'refusal',
            'stop_details' => null,
        ]);

        try {
            $this->reviser($antwort)->revise($listing, 'Text.', TextReviser::KUERZER);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('Der KI-Anbieter hat die Anfrage abgelehnt.', $exception->getMessage());
        }

        $this->assertDatabaseHas('ki_usages', [
            'zweck' => 'ueberarbeitung',
            'erfolgreich' => false,
            'fehler' => 'Der KI-Anbieter hat die Anfrage abgelehnt.',
        ]);
    }

    public function test_eine_abgeschnittene_antwort_wegen_laengenbegrenzung_wirft_eine_ausnahme(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock('unvollst')],
            'stop_reason' => 'max_tokens',
        ]);

        $this->expectException(TextGenerationException::class);
        $this->expectExceptionMessage('Längenbegrenzung');

        $this->reviser($antwort)->revise($listing, 'Text.', TextReviser::KUERZER);
    }

    public function test_ein_authentifizierungsfehler_wird_uebersetzt_und_der_schluessel_taucht_nirgends_auf(): void
    {
        $listing = Listing::factory()->create();
        $antwort = new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.self::API_KEY],
        ]));

        try {
            $this->reviser($antwort)->revise($listing, 'Text.', TextReviser::KUERZER);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('API-Schlüssel ungültig.', $exception->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $eintrag = KiUsage::query()->latest('id')->first();
        self::assertNotNull($eintrag);
        self::assertSame('ueberarbeitung', $eintrag->zweck);
        self::assertFalse($eintrag->erfolgreich);
        self::assertStringNotContainsString(self::API_KEY, (string) $eintrag->fehler);
    }

    public function test_ein_anfragelimit_wird_uebersetzt(): void
    {
        $listing = Listing::factory()->create();
        $antwort = new Response(429, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => 'rate_limit_error', 'message' => 'rate limited'],
        ]));

        try {
            $this->reviser($antwort)->revise($listing, 'Text.', TextReviser::KUERZER);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('Anfragelimit erreicht, bitte später erneut versuchen.', $exception->getMessage());
        }
    }

    public function test_ein_serverfehler_wird_uebersetzt(): void
    {
        $listing = Listing::factory()->create();
        $antwort = new Response(500, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => 'api_error', 'message' => 'internal'],
        ]));

        try {
            $this->reviser($antwort)->revise($listing, 'Text.', TextReviser::KUERZER);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('KI-Anbieter derzeit nicht erreichbar.', $exception->getMessage());
        }
    }

    public function test_ein_verbindungsfehler_wird_uebersetzt(): void
    {
        $listing = Listing::factory()->create();
        $fehlerhafterClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class extends RuntimeException implements ClientExceptionInterface {};
            }
        };

        $reviser = new AnthropicTextReviser($this->settings(), $fehlerhafterClient);

        try {
            $reviser->revise($listing, 'Text.', TextReviser::KUERZER);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('KI-Anbieter derzeit nicht erreichbar.', $exception->getMessage());
        }
    }

    public function test_ohne_hinterlegten_schluessel_wird_eine_ausnahme_geworfen_ohne_aufruf(): void
    {
        $settings = $this->settings(mitSchluessel: false);
        $reviser = new AnthropicTextReviser($settings, null);

        $this->expectException(TextGenerationException::class);

        $reviser->revise(Listing::factory()->create(), 'Text.', TextReviser::KUERZER);
    }

    private function settings(bool $mitSchluessel = true): SettingsRepository
    {
        $settings = app(SettingsRepository::class);
        $settings->set('ki.provider', 'anthropic');
        $settings->set('ki.modell', 'claude-opus-5');

        if ($mitSchluessel) {
            $settings->setSecret('ki.api_key', self::API_KEY);
        }

        return $settings;
    }

    private function reviser(ResponseInterface $antwort): AnthropicTextReviser
    {
        $httpClient = new class($antwort) implements ClientInterface
        {
            public function __construct(private readonly ResponseInterface $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return new AnthropicTextReviser($this->settings(), $httpClient);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private static function responseWith(array $overrides): ResponseInterface
    {
        $daten = array_replace([
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-opus-5',
            'container' => null,
            'content' => [self::textBlock('Überarbeiteter Text.')],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => self::usage(10, 5),
        ], $overrides);

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($daten, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{type: string, text: string, citations: null}
     */
    private static function textBlock(string $text): array
    {
        return ['type' => 'text', 'text' => $text, 'citations' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function usage(int $inputTokens, int $outputTokens): array
    {
        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cache_creation_input_tokens' => null,
            'cache_read_input_tokens' => null,
            'cache_creation' => null,
            'server_tool_use' => null,
            'service_tier' => null,
            'inference_geo' => null,
            'output_tokens_details' => null,
        ];
    }
}
