<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\ErrorType;
use Anthropic\Messages\Message;
use App\Domain\Settings\SettingsRepository;
use App\Models\KiUsage;
use App\Models\Listing;
use GuzzleHttp\Client as GuzzleHttpClient;
use Illuminate\Support\Facades\Auth;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Gezielte Überarbeitung eines vorhandenen Textes über die Anthropic Messages
 * API (Masterprompt Abschnitt 16, ADR-009). Der Systemprompt verbietet
 * ausdrücklich, Tatsachen hinzuzufügen oder Zahlen und Aussagen zu ändern:
 * nur die Form ändert sich, je nach Anweisung. Aufbau, Schlüsselauflösung,
 * Fehlerabbildung und Verbrauchsprotokoll folgen AnthropicTextGenerator.
 */
final class AnthropicTextReviser implements TextReviser
{
    public function __construct(
        private readonly SettingsRepository $settings,
        /**
         * Nur in Tests gesetzt: ersetzt den vom SDK automatisch ermittelten
         * PSR-18-Client, damit kein Test einen echten Netzwerkaufruf auslöst.
         */
        private readonly ?ClientInterface $httpClient = null,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->get('ki.provider') === 'anthropic' && $this->apiKey() !== null;
    }

    public function modell(): string
    {
        $modell = $this->settings->get('ki.modell');

        if (is_string($modell) && trim($modell) !== '') {
            return trim($modell);
        }

        $konfiguriert = config('ai.model');

        return is_string($konfiguriert) && $konfiguriert !== '' ? $konfiguriert : 'claude-opus-5';
    }

    public function revise(Listing $listing, string $text, string $anweisung): string
    {
        if (! in_array($anweisung, [self::KUERZER, self::SACHLICHER, self::SPRACHLICH], true)) {
            throw new TextGenerationException('Unbekannte Überarbeitungsanweisung.');
        }

        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new TextGenerationException('Es ist kein API-Schlüssel für den KI-Anbieter hinterlegt.');
        }

        $modell = $this->modell();
        $system = $this->systemPrompt($anweisung);
        $nachricht = $this->userMessage($text);

        $begonnenAt = microtime(true);

        try {
            $message = $this->buildClient($apiKey)->messages->create(
                model: $modell,
                maxTokens: 1500,
                system: $system,
                messages: [['role' => 'user', 'content' => $nachricht]],
                // Effort "low": eine Formüberarbeitung eines vorhandenen
                // Textes benötigt keine tiefe Analyse.
                outputConfig: ['effort' => 'low'],
                requestOptions: $this->requestOptions(),
            );
        } catch (Throwable $exception) {
            $dauerMs = $this->dauerMs($begonnenAt);
            $fehler = $this->mapException($exception);
            $this->recordUsage($listing, $modell, 0, 0, $dauerMs, false, $fehler->getMessage());

            throw $fehler;
        }

        $dauerMs = $this->dauerMs($begonnenAt);
        $inputTokens = $message->usage->inputTokens;
        $outputTokens = $message->usage->outputTokens;

        if ($message->stopReason === 'refusal') {
            $fehler = 'Der KI-Anbieter hat die Anfrage abgelehnt.';
            $this->recordUsage($listing, $modell, $inputTokens, $outputTokens, $dauerMs, false, $fehler);

            throw new TextGenerationException($fehler);
        }

        if ($message->stopReason === 'max_tokens') {
            $fehler = 'Die Antwort des KI-Anbieters wurde wegen der Längenbegrenzung abgeschnitten.';
            $this->recordUsage($listing, $modell, $inputTokens, $outputTokens, $dauerMs, false, $fehler);

            throw new TextGenerationException($fehler);
        }

        $ergebnis = $this->entferneCodeZaun($this->ersterTextblock($message));

        if ($ergebnis === null || trim($ergebnis) === '') {
            $fehler = 'Die Antwort des KI-Anbieters enthielt keinen Text.';
            $this->recordUsage($listing, $modell, $inputTokens, $outputTokens, $dauerMs, false, $fehler);

            throw new TextGenerationException($fehler);
        }

        $this->recordUsage($listing, $modell, $inputTokens, $outputTokens, $dauerMs, true, null);

        return trim($ergebnis);
    }

    private function systemPrompt(string $anweisung): string
    {
        $anweisungstext = match ($anweisung) {
            self::KUERZER => 'Kürzen Sie den Text auf etwa 60 Prozent seiner ursprünglichen Länge. '
                .'Alle Tatsachen, Zahlen und Aussagen des Ausgangstexts müssen erhalten bleiben, '
                .'gestrichen werden nur Wiederholungen und Füllwörter.',
            self::SACHLICHER => 'Formulieren Sie den Text sachlicher: entfernen Sie Superlative und '
                .'werbliche Adjektive (zum Beispiel "traumhaft", "einzigartig", "perfekt"). Die '
                .'Tatsachen, Zahlen und Aussagen bleiben unverändert.',
            self::SPRACHLICH => 'Verbessern Sie Grammatik, Zeichensetzung und Lesbarkeit des Textes. '
                .'Die Tatsachen, Zahlen und Aussagen bleiben unverändert, es ändert sich nur die '
                .'sprachliche Form.',
            default => '',
        };

        return <<<PROMPT
            Sie überarbeiten einen vorhandenen Immobilienanzeigentext der Hausverwaltung
            Müller GmbH ausschließlich in der Form, niemals im Inhalt.

            Zwingende Regeln:
            - Fügen Sie keine Tatsachen, Zahlen oder Eigenschaften hinzu, die im Ausgangstext
              nicht bereits enthalten sind.
            - Ändern Sie keine Zahlen, Maße, Preise, Adressangaben oder sonstigen Aussagen des
              Ausgangstexts inhaltlich.
            - Schreiben Sie deutsch, sachlich und in der formellen Anrede "Sie", sofern der
              Ausgangstext eine Anrede verwendet.
            - Verwenden Sie keine Gedankenstriche, sondern Kommas oder eine andere Formulierung.
            - Verwenden Sie keine Umgangssprache und keine Emojis.

            Anweisung für diese Überarbeitung: {$anweisungstext}

            Geben Sie ausschließlich den überarbeiteten Text zurück: kein Fließtext davor oder
            danach, keine Anführungszeichen um den Text, keine Code-Zäune, keine Erklärung.
            PROMPT;
    }

    private function userMessage(string $text): string
    {
        return "Zu überarbeitender Text:\n".$text;
    }

    private function apiKey(): ?string
    {
        $schluessel = $this->settings->getSecret('ki.api_key');

        if (is_string($schluessel) && trim($schluessel) !== '') {
            return trim($schluessel);
        }

        $konfiguriert = config('ai.anthropic.api_key');

        return is_string($konfiguriert) && trim($konfiguriert) !== '' ? trim($konfiguriert) : null;
    }

    private function buildClient(string $apiKey): Client
    {
        return new Client(
            apiKey: $apiKey,
            requestOptions: [
                'transporter' => $this->httpClient ?? new GuzzleHttpClient(['timeout' => 60.0]),
            ],
        );
    }

    /**
     * @return array{timeout: float, maxRetries: int}
     */
    private function requestOptions(): array
    {
        return ['timeout' => 60.0, 'maxRetries' => 0];
    }

    private function ersterTextblock(Message $message): ?string
    {
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        return null;
    }

    private function entferneCodeZaun(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = trim($text);

        if (! str_starts_with($text, '```')) {
            return $text;
        }

        $text = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
        $text = (string) preg_replace('/```\s*$/', '', $text);

        return trim($text);
    }

    private function mapException(Throwable $exception): TextGenerationException
    {
        $nachricht = match (true) {
            $exception instanceof AuthenticationException => 'API-Schlüssel ungültig.',
            $exception instanceof RateLimitException => 'Anfragelimit erreicht, bitte später erneut versuchen.',
            $exception instanceof APIStatusException && $exception->type === ErrorType::OVERLOADED_ERROR => 'KI-Anbieter derzeit nicht erreichbar.',
            $exception instanceof InternalServerException => 'KI-Anbieter derzeit nicht erreichbar.',
            $exception instanceof APIConnectionException => 'KI-Anbieter derzeit nicht erreichbar.',
            default => 'Der KI-Anbieter konnte nicht erreicht werden.',
        };

        return new TextGenerationException($nachricht, previous: $exception);
    }

    private function dauerMs(float $begonnenAt): int
    {
        return (int) round((microtime(true) - $begonnenAt) * 1000);
    }

    private function recordUsage(
        Listing $listing,
        string $modell,
        int $inputTokens,
        int $outputTokens,
        int $dauerMs,
        bool $erfolgreich,
        ?string $fehler,
    ): void {
        KiUsage::create([
            'user_id' => Auth::id(),
            'listing_id' => $listing->id,
            'modell' => $modell,
            'zweck' => 'ueberarbeitung',
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'dauer_ms' => $dauerMs,
            'erfolgreich' => $erfolgreich,
            'fehler' => $fehler !== null ? mb_substr($fehler, 0, 255) : null,
        ]);
    }
}
