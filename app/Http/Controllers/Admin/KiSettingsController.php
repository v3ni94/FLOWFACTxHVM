<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KiApiKeyRequest;
use App\Http\Requests\Admin\KiSettingsRequest;
use App\Models\KiUsage;
use App\Services\Ai\AnthropicTextGenerator;
use App\Services\Ai\TextGenerationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * Anbieter, Modell, Schlüssel und Verbrauch der KI-Textvorschläge
 * (Datenvertrag Abschnitt 2.11, ADR-009).
 *
 * Der Schlüssel wird ausschließlich geschrieben, nie gelesen oder gerendert:
 * die Ansicht erhält nur "hinterlegt am", wie beim FLOWFACT-Token.
 */
class KiSettingsController extends Controller
{
    public const string API_KEY_HINTERLEGT_AT = 'ki.api_key_hinterlegt_at';

    public const string VERBINDUNG_GEPRUEFT_AT = 'ki.verbindung_geprueft_at';

    public const string VERBINDUNG_ERGEBNIS = 'ki.verbindung_ergebnis';

    public function edit(SettingsRepository $settings): View
    {
        $modelle = (array) config('ai.models');
        $provider = $this->text($settings->get('ki.provider')) ?? 'fake';

        return view('admin.ki.edit', [
            'provider' => $provider,
            'modell' => $this->text($settings->get('ki.modell')) ?? (string) config('ai.model'),
            'modelle' => $modelle,
            'apiKeyHinterlegt' => $settings->hasSecret('ki.api_key'),
            'apiKeyHinterlegtAt' => $this->datum($settings->get(self::API_KEY_HINTERLEGT_AT)),
            'verbindungGeprueftAt' => $this->datum($settings->get(self::VERBINDUNG_GEPRUEFT_AT)),
            'verbindungErgebnis' => $this->text($settings->get(self::VERBINDUNG_ERGEBNIS)),
            // Masterprompt Abschnitt 16: solange der Anbieter nicht "anthropic"
            // ist oder kein Schlüssel hinterlegt ist, erzeugt der
            // Vorlagenmodus (FakeTextGenerator/FakeTextReviser) alle Texte
            // ohne externen Aufruf.
            'vorlagenmodusAktiv' => $provider !== 'anthropic' || ! $settings->hasSecret('ki.api_key'),
            'nutzungProModell' => $this->nutzungProModell($modelle),
            'nutzungProZweck' => $this->nutzungProZweck(),
            'letzteNutzungen' => KiUsage::query()->latest('id')->limit(20)->get(),
        ]);
    }

    public function storeApiKey(KiApiKeyRequest $request, SettingsRepository $settings): RedirectResponse
    {
        $settings->setSecret('ki.api_key', trim($request->string('api_key')->value()));
        $settings->set(self::API_KEY_HINTERLEGT_AT, Carbon::now()->toIso8601String());
        $settings->forget(self::VERBINDUNG_GEPRUEFT_AT);
        $settings->forget(self::VERBINDUNG_ERGEBNIS);

        return redirect()->route('admin.ki.edit')
            ->with('status', 'Der API-Schlüssel wurde hinterlegt. Bitte prüfen Sie die Verbindung.');
    }

    public function destroyApiKey(SettingsRepository $settings): RedirectResponse
    {
        $settings->forget('ki.api_key');
        $settings->forget(self::API_KEY_HINTERLEGT_AT);
        $settings->forget(self::VERBINDUNG_GEPRUEFT_AT);
        $settings->forget(self::VERBINDUNG_ERGEBNIS);

        return redirect()->route('admin.ki.edit')
            ->with('status', 'Der API-Schlüssel wurde entfernt. Solange kein neuer Schlüssel hinterlegt ist, werden nur Platzhaltertexte erzeugt.');
    }

    public function updateSettings(KiSettingsRequest $request, SettingsRepository $settings): RedirectResponse
    {
        $settings->set('ki.provider', $request->string('provider')->value());
        $settings->set('ki.modell', $request->string('modell')->value());

        return redirect()->route('admin.ki.edit')
            ->with('status', 'Die KI-Einstellungen wurden gespeichert.');
    }

    /**
     * Minimaler Aufruf mit kurzer Antwort, um Schlüssel und Modell zu prüfen.
     * Das Ergebnis enthält nie den Schlüssel: TextGenerationException liefert
     * ausschließlich feste, unverfängliche deutsche Meldungen.
     */
    public function testConnection(SettingsRepository $settings, AnthropicTextGenerator $generator): RedirectResponse
    {
        if (! $settings->hasSecret('ki.api_key')) {
            return redirect()->route('admin.ki.edit')
                ->with('error', 'Es ist kein API-Schlüssel hinterlegt.');
        }

        try {
            $ergebnis = $generator->testConnection();
            $meldung = sprintf(
                'Verbunden mit Modell %s (%d Eingabetoken, %d Ausgabetoken).',
                $ergebnis['modell'],
                $ergebnis['input_tokens'],
                $ergebnis['output_tokens'],
            );
            $erfolgreich = true;
        } catch (TextGenerationException $exception) {
            $meldung = 'Fehler: '.$exception->getMessage();
            $erfolgreich = false;
        } catch (Throwable) {
            $meldung = 'Fehler: Der KI-Anbieter konnte nicht erreicht werden.';
            $erfolgreich = false;
        }

        $settings->set(self::VERBINDUNG_GEPRUEFT_AT, Carbon::now()->toIso8601String());
        $settings->set(self::VERBINDUNG_ERGEBNIS, mb_substr($meldung, 0, 1000));

        return redirect()->route('admin.ki.edit')
            ->with($erfolgreich ? 'status' : 'error', $erfolgreich ? 'Verbindungstest erfolgreich: '.$meldung : 'Verbindungstest fehlgeschlagen. '.$meldung);
    }

    /**
     * Verbrauch der letzten 30 Tage je Modell mit Tokensummen und einer
     * Kostenschätzung nach Listenpreis (config/ai.php).
     *
     * @param  array<string, array{label: string, preis_input_je_million_usd: float, preis_output_je_million_usd: float}>  $modelle
     * @return list<array{modell: string, aufrufe: int, input_tokens: int, output_tokens: int, geschaetzte_kosten_usd: float}>
     */
    private function nutzungProModell(array $modelle): array
    {
        $zeilen = KiUsage::query()
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->selectRaw('modell, count(*) as aufrufe, sum(input_tokens) as summe_input, sum(output_tokens) as summe_output')
            ->groupBy('modell')
            ->orderBy('modell')
            ->get();

        return $zeilen->map(function (KiUsage $zeile) use ($modelle): array {
            $preise = $modelle[$zeile->modell] ?? null;
            $inputTokens = (int) $zeile->getAttribute('summe_input');
            $outputTokens = (int) $zeile->getAttribute('summe_output');

            $kosten = $preise !== null
                ? ($inputTokens / 1_000_000 * (float) $preise['preis_input_je_million_usd'])
                    + ($outputTokens / 1_000_000 * (float) $preise['preis_output_je_million_usd'])
                : 0.0;

            return [
                'modell' => $zeile->modell,
                'aufrufe' => (int) $zeile->getAttribute('aufrufe'),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'geschaetzte_kosten_usd' => round($kosten, 2),
            ];
        })->all();
    }

    /**
     * Verbrauch der letzten 30 Tage je Zweck (Masterprompt Abschnitt 16):
     * "entwurf" (Texterzeugung) und "ueberarbeitung" (kürzer, sachlicher,
     * sprachlich). Ältere Einträge und der Verbindungstest tragen keinen
     * Zweck und erscheinen als "ohne Zuordnung".
     *
     * @return list<array{zweck: string, label: string, aufrufe: int, input_tokens: int, output_tokens: int}>
     */
    private function nutzungProZweck(): array
    {
        $zeilen = KiUsage::query()
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->selectRaw('zweck, count(*) as aufrufe, sum(input_tokens) as summe_input, sum(output_tokens) as summe_output')
            ->groupBy('zweck')
            ->orderBy('zweck')
            ->get();

        $label = static fn (?string $zweck): string => match ($zweck) {
            'entwurf' => 'Entwurf',
            'ueberarbeitung' => 'Überarbeitung',
            default => 'Ohne Zuordnung',
        };

        return $zeilen->map(function (KiUsage $zeile) use ($label): array {
            $zweck = $zeile->getAttribute('zweck');

            return [
                'zweck' => $zweck ?? '',
                'label' => $label($zweck),
                'aufrufe' => (int) $zeile->getAttribute('aufrufe'),
                'input_tokens' => (int) $zeile->getAttribute('summe_input'),
                'output_tokens' => (int) $zeile->getAttribute('summe_output'),
            ];
        })->all();
    }

    private function text(mixed $wert): ?string
    {
        return is_string($wert) && trim($wert) !== '' ? trim($wert) : null;
    }

    private function datum(mixed $wert): ?Carbon
    {
        if (! is_string($wert) || $wert === '') {
            return null;
        }

        try {
            return Carbon::parse($wert);
        } catch (Throwable) {
            return null;
        }
    }
}
