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
use App\Enums\TextFeld;
use App\Models\KiUsage;
use App\Models\Listing;
use GuzzleHttp\Client as GuzzleHttpClient;
use Illuminate\Support\Facades\Auth;
use JsonException;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Textvorschläge über die Anthropic Messages API (ADR-009).
 *
 * Der API-Schlüssel wird ausschließlich aus SettingsRepository ('ki.api_key')
 * oder, hilfsweise, aus der Konfiguration (ANTHROPIC_API_KEY) gelesen, nie
 * entgegengenommen oder protokolliert. Jeder Aufruf erzeugt einen Verbrauchs-
 * eintrag (KiUsage), auch bei Fehlschlag.
 */
final class AnthropicTextGenerator implements TextGenerator
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly PromptBuilder $promptBuilder,
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

    public function generate(Listing $listing, array $felder): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new TextGenerationException('Es ist kein API-Schlüssel für den KI-Anbieter hinterlegt.');
        }

        $modell = $this->modell();
        $system = $this->promptBuilder->systemPrompt();
        $nachricht = $this->promptBuilder->userMessage($listing, $felder);

        $begonnenAt = microtime(true);

        try {
            $message = $this->buildClient($apiKey)->messages->create(
                model: $modell,
                maxTokens: 2000,
                system: $system,
                messages: [['role' => 'user', 'content' => $nachricht]],
                // Effort "low": kurze, längenbegrenzte Marketingtexte
                // benötigen keine tiefe Analyse (opus-5 und sonnet-5
                // akzeptieren den Parameter).
                outputConfig: ['effort' => 'low'],
                requestOptions: $this->requestOptions(),
            );
        } catch (Throwable $exception) {
            $dauerMs = $this->dauerMs($begonnenAt);
            $fehler = $this->mapException($exception);
            $this->recordUsage($listing, $modell, 0, 0, $dauerMs, false, $fehler->getMessage(), 'entwurf');

            throw $fehler;
        }

        $dauerMs = $this->dauerMs($begonnenAt);
        $inputTokens = $message->usage->inputTokens;
        $outputTokens = $message->usage->outputTokens;

        if ($message->stopReason === 'refusal') {
            $fehler = 'Der KI-Anbieter hat die Anfrage abgelehnt.';
            $this->recordUsage($listing, $modell, $inputTokens, $outputTokens, $dauerMs, false, $fehler, 'entwurf');

            throw new TextGenerationException($fehler);
        }

        if ($message->stopReason === 'max_tokens') {
            $fehler = 'Die Antwort des KI-Anbieters wurde wegen der Längenbegrenzung abgeschnitten.';
            $this->recordUsage($listing, $modell, $inputTokens, $outputTokens, $dauerMs, false, $fehler, 'entwurf');

            throw new TextGenerationException($fehler);
        }

        try {
            $ergebnis = $this->parseAntwort($this->ersterTextblock($message), $felder);
            // Masterprompt-Abgleich B.7: letzte Sicherung, falls der Anbieter
            // trotz ausgeblendeter Adresse Straße oder Hausnummer erfindet
            // oder aus dem Kontext übernimmt.
            HiddenAddressGuard::pruefe($listing, $ergebnis);
        } catch (TextGenerationException $exception) {
            $this->recordUsage($listing, $modell, $inputTokens, $outputTokens, $dauerMs, false, $exception->getMessage(), 'entwurf');

            throw $exception;
        }

        $this->recordUsage($listing, $modell, $inputTokens, $outputTokens, $dauerMs, true, null, 'entwurf');

        return $ergebnis;
    }

    /**
     * Minimaler Aufruf für den Verbindungstest im Adminbereich. Erzeugt
     * ebenfalls einen Verbrauchseintrag, aber ohne Objektbezug.
     *
     * @return array{modell: string, input_tokens: int, output_tokens: int}
     *
     * @throws TextGenerationException
     */
    public function testConnection(): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new TextGenerationException('Es ist kein API-Schlüssel hinterlegt.');
        }

        $modell = $this->modell();
        $begonnenAt = microtime(true);

        try {
            $message = $this->buildClient($apiKey)->messages->create(
                model: $modell,
                maxTokens: 32,
                messages: [['role' => 'user', 'content' => 'Antworten Sie nur mit OK.']],
                requestOptions: $this->requestOptions(),
            );
        } catch (Throwable $exception) {
            $dauerMs = $this->dauerMs($begonnenAt);
            $fehler = $this->mapException($exception);
            $this->recordUsage(null, $modell, 0, 0, $dauerMs, false, $fehler->getMessage());

            throw $fehler;
        }

        $dauerMs = $this->dauerMs($begonnenAt);
        $inputTokens = $message->usage->inputTokens;
        $outputTokens = $message->usage->outputTokens;

        if ($message->stopReason === 'refusal') {
            $fehler = 'Der KI-Anbieter hat die Anfrage abgelehnt.';
            $this->recordUsage(null, $modell, $inputTokens, $outputTokens, $dauerMs, false, $fehler);

            throw new TextGenerationException($fehler);
        }

        $this->recordUsage(null, $modell, $inputTokens, $outputTokens, $dauerMs, true, null);

        return ['modell' => $modell, 'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens];
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
            // Ohne injizierten Test-Transporter erzwingt ein eigens
            // konfigurierter Guzzle-Client die 60-Sekunden-Zeitgrenze; das
            // timeout-Feld in RequestOptions ist laut SDK rein informativ und
            // wird nur zusätzlich (siehe requestOptions()) mitgegeben. Der
            // Transporter wird ausschließlich hier, nicht je Aufruf, gesetzt:
            // ein "transporter" => null je Aufruf würde beim Zusammenführen
            // mit den Client-Optionen den hier gesetzten Transporter
            // überschreiben.
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
        // Kein SDK-eigenes Wiederholen: Der Aufruf läuft synchron in einer
        // Benutzeranfrage, ein einzelner rascher Fehlschlag mit klarer
        // deutscher Meldung ist einer mehrfachen Verzögerung vorzuziehen.
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

    /**
     * @param  list<TextFeld>  $felder
     * @return array<string,string>
     */
    private function parseAntwort(?string $text, array $felder): array
    {
        if ($text === null || trim($text) === '') {
            throw new TextGenerationException('Die Antwort des KI-Anbieters enthielt keinen Text.');
        }

        $bereinigt = $this->entferneCodeZaun($text);

        try {
            $daten = json_decode($bereinigt, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new TextGenerationException('Die Antwort des KI-Anbieters konnte nicht als JSON gelesen werden.');
        }

        if (! is_array($daten)) {
            throw new TextGenerationException('Die Antwort des KI-Anbieters hatte kein erwartetes Format.');
        }

        $ergebnis = [];

        foreach ($felder as $feld) {
            $wert = $daten[$feld->value] ?? null;

            if (! is_string($wert) || trim($wert) === '') {
                throw new TextGenerationException(sprintf('Die Antwort des KI-Anbieters enthielt keinen Text für "%s".', $feld->label()));
            }

            $wert = trim($wert);

            if ($feld === TextFeld::Titel) {
                $wert = $this->kuerzeAufWortgrenze($wert, 100);
            }

            $ergebnis[$feld->value] = $wert;
        }

        return $ergebnis;
    }

    private function entferneCodeZaun(string $text): string
    {
        $text = trim($text);

        if (! str_starts_with($text, '```')) {
            return $text;
        }

        $text = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
        $text = (string) preg_replace('/```\s*$/', '', $text);

        return trim($text);
    }

    private function kuerzeAufWortgrenze(string $text, int $maxLaenge): string
    {
        if (mb_strlen($text) <= $maxLaenge) {
            return $text;
        }

        $gekuerzt = mb_substr($text, 0, $maxLaenge);
        $letztesLeerzeichen = mb_strrpos($gekuerzt, ' ');

        if ($letztesLeerzeichen !== false) {
            $gekuerzt = mb_substr($gekuerzt, 0, $letztesLeerzeichen);
        }

        return rtrim($gekuerzt);
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
        ?Listing $listing,
        string $modell,
        int $inputTokens,
        int $outputTokens,
        int $dauerMs,
        bool $erfolgreich,
        ?string $fehler,
        ?string $zweck = null,
    ): void {
        KiUsage::create([
            'user_id' => Auth::id(),
            'listing_id' => $listing?->id,
            'modell' => $modell,
            'zweck' => $zweck,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'dauer_ms' => $dauerMs,
            'erfolgreich' => $erfolgreich,
            'fehler' => $fehler !== null ? mb_substr($fehler, 0, 255) : null,
        ]);
    }
}
