<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Domain\Settings\SettingsRepository;
use App\Enums\AdressFreigabe;
use App\Enums\TextFeld;
use App\Models\KiUsage;
use App\Models\Listing;
use App\Services\Ai\AnthropicTextGenerator;
use App\Services\Ai\PromptBuilder;
use App\Services\Ai\TextGenerationException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Tests\TestCase;

final class AnthropicTextGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const string API_KEY = 'sk-test-KENNUNG-1234567890';

    public function test_eine_erfolgreiche_antwort_wird_geparst_und_der_verbrauch_gespeichert(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock('{"titel":"Gepflegte Wohnung in Erkelenz","beschreibung_objekt":"Sachliche Beschreibung."}')],
            'usage' => self::usage(120, 45),
        ]);

        $generator = $this->generator($antwort);

        $ergebnis = $generator->generate($listing, [TextFeld::Titel, TextFeld::BeschreibungObjekt]);

        self::assertSame([
            'titel' => 'Gepflegte Wohnung in Erkelenz',
            'beschreibung_objekt' => 'Sachliche Beschreibung.',
        ], $ergebnis);

        $this->assertDatabaseHas('ki_usages', [
            'listing_id' => $listing->id,
            'zweck' => 'entwurf',
            'input_tokens' => 120,
            'output_tokens' => 45,
            'erfolgreich' => true,
            'fehler' => null,
        ]);
    }

    public function test_ein_code_zaun_um_das_json_wird_entfernt(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock("```json\n{\"titel\":\"Helle Wohnung\"}\n```")],
        ]);

        $ergebnis = $this->generator($antwort)->generate($listing, [TextFeld::Titel]);

        self::assertSame(['titel' => 'Helle Wohnung'], $ergebnis);
    }

    public function test_ein_fehlendes_angefordertes_feld_in_der_antwort_wirft_eine_ausnahme(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock('{"titel":"Nur der Titel"}')],
        ]);

        $this->expectException(TextGenerationException::class);

        $this->generator($antwort)->generate($listing, [TextFeld::Titel, TextFeld::BeschreibungObjekt]);
    }

    public function test_ein_zu_langer_titel_wird_an_einer_wortgrenze_gekuerzt(): void
    {
        $listing = Listing::factory()->create();
        $langerTitel = str_repeat('Wohnung ', 20); // deutlich über 100 Zeichen
        $antwort = self::responseWith([
            'content' => [self::textBlock(json_encode(['titel' => trim($langerTitel)]))],
        ]);

        $ergebnis = $this->generator($antwort)->generate($listing, [TextFeld::Titel]);

        self::assertLessThanOrEqual(100, mb_strlen($ergebnis['titel']));
        self::assertStringEndsNotWith(' ', $ergebnis['titel']);
    }

    public function test_ungueltiges_json_in_der_antwort_wirft_eine_ausnahme(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock('Das ist kein JSON.')],
        ]);

        $this->expectException(TextGenerationException::class);

        $this->generator($antwort)->generate($listing, [TextFeld::Titel]);
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
            $this->generator($antwort)->generate($listing, [TextFeld::Titel]);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('Der KI-Anbieter hat die Anfrage abgelehnt.', $exception->getMessage());
        }

        $this->assertDatabaseHas('ki_usages', ['erfolgreich' => false, 'fehler' => 'Der KI-Anbieter hat die Anfrage abgelehnt.']);
    }

    public function test_eine_abgeschnittene_antwort_wegen_laengenbegrenzung_wirft_eine_ausnahme(): void
    {
        $listing = Listing::factory()->create();
        $antwort = self::responseWith([
            'content' => [self::textBlock('{"titel":"unvollst')],
            'stop_reason' => 'max_tokens',
        ]);

        $this->expectException(TextGenerationException::class);
        $this->expectExceptionMessage('Längenbegrenzung');

        $this->generator($antwort)->generate($listing, [TextFeld::Titel]);
    }

    public function test_ein_authentifizierungsfehler_wird_uebersetzt_und_der_schluessel_taucht_nirgends_auf(): void
    {
        $listing = Listing::factory()->create();
        $antwort = new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.self::API_KEY],
        ]));

        try {
            $this->generator($antwort)->generate($listing, [TextFeld::Titel]);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('API-Schlüssel ungültig.', $exception->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $eintrag = KiUsage::query()->latest('id')->first();
        self::assertNotNull($eintrag);
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
            $this->generator($antwort)->generate($listing, [TextFeld::Titel]);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('Anfragelimit erreicht, bitte später erneut versuchen.', $exception->getMessage());
        }
    }

    public function test_eine_ueberlastung_des_anbieters_wird_uebersetzt(): void
    {
        $listing = Listing::factory()->create();
        $antwort = new Response(529, ['Content-Type' => 'application/json'], (string) json_encode([
            'type' => 'error',
            'error' => ['type' => 'overloaded_error', 'message' => 'overloaded'],
        ]));

        try {
            $this->generator($antwort)->generate($listing, [TextFeld::Titel]);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('KI-Anbieter derzeit nicht erreichbar.', $exception->getMessage());
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
            $this->generator($antwort)->generate($listing, [TextFeld::Titel]);
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

        $generator = new AnthropicTextGenerator($this->settings(), new PromptBuilder, $fehlerhafterClient);

        try {
            $generator->generate($listing, [TextFeld::Titel]);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertSame('KI-Anbieter derzeit nicht erreichbar.', $exception->getMessage());
        }
    }

    /**
     * Masterprompt-Abgleich B.7: erfindet oder übernimmt der Anbieter trotz
     * ausgeblendeter Adresse die Straße, wird der gesamte Vorschlag
     * verworfen und der Verbrauch als Fehlschlag protokolliert.
     */
    public function test_eine_ausgeblendete_strasse_in_der_antwort_verwirft_den_vorschlag(): void
    {
        $listing = Listing::factory()->create([
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'strasse' => 'Geheimstraße',
            'hausnummer' => '12',
        ]);
        $antwort = self::responseWith([
            'content' => [self::textBlock('{"beschreibung_lage":"Das Objekt liegt in der Geheimstraße."}')],
        ]);

        try {
            $this->generator($antwort)->generate($listing, [TextFeld::BeschreibungLage]);
            self::fail('Es wurde keine Ausnahme geworfen.');
        } catch (TextGenerationException $exception) {
            self::assertStringContainsString('ausgeblendete Straße', $exception->getMessage());
        }

        $this->assertDatabaseHas('ki_usages', ['zweck' => 'entwurf', 'erfolgreich' => false]);
    }

    public function test_ohne_hinterlegten_schluessel_wird_eine_ausnahme_geworfen_ohne_aufruf(): void
    {
        $settings = $this->settings(mitSchluessel: false);
        $generator = new AnthropicTextGenerator($settings, new PromptBuilder, null);

        $this->expectException(TextGenerationException::class);

        $generator->generate(Listing::factory()->create(), [TextFeld::Titel]);
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

    private function generator(ResponseInterface $antwort): AnthropicTextGenerator
    {
        $httpClient = new class($antwort) implements ClientInterface
        {
            public function __construct(private readonly ResponseInterface $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return new AnthropicTextGenerator($this->settings(), new PromptBuilder, $httpClient);
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
            'content' => [self::textBlock('{"titel":"Test"}')],
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
