<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\FlowfactClient;
use App\Flowfact\Client\SettingsTokenProvider;
use App\Flowfact\Client\TokenHeader;
use App\Flowfact\Client\TokenScrubber;
use App\Flowfact\Mapping\FieldMappingResolver;
use App\Flowfact\Services\SchemaService;
use App\Flowfact\Services\UserService;
use App\Flowfact\Sync\ListingSyncService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FlowfactSettingsRequest;
use App\Http\Requests\Admin\FlowfactTokenRequest;
use App\Models\TransferLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * FLOWFACT-Einstellungen (docs/connector.md Abschnitt 7).
 *
 * Der Token wird ausschließlich geschrieben, nie gelesen oder gerendert:
 * die Ansicht erhält nur "hinterlegt am". Das ist die einzige Stelle, an der
 * ein Administrator den Token setzt oder entfernt.
 */
class FlowfactSettingsController extends Controller
{
    public const string TOKEN_HINTERLEGT_AT = 'flowfact.token_hinterlegt_at';

    public const string VERBINDUNG_GEPRUEFT_AT = 'flowfact.verbindung_geprueft_at';

    public const string VERBINDUNG_ERGEBNIS = 'flowfact.verbindung_ergebnis';

    public const string SCHEMATA = 'flowfact.schemata';

    public const string LEERE_FELDER_AN = 'an';

    public const string LEERE_FELDER_AUS = 'aus';

    public function edit(SettingsRepository $settings, FieldMappingResolver $resolver, ListingSyncService $sync): View
    {
        $schemaMiete = $this->text($settings->get(ListingSyncService::SCHEMA_MIETE));
        $schemaKauf = $this->text($settings->get(ListingSyncService::SCHEMA_KAUF));

        return view('admin.flowfact.edit', [
            'tokenHinterlegt' => $settings->hasSecret(SettingsTokenProvider::TOKEN_KEY),
            'tokenHinterlegtAt' => $this->datum($settings->get(self::TOKEN_HINTERLEGT_AT)),
            'tokenFormat' => $this->tokenFormat($settings),
            'companyId' => $this->text($settings->get(SettingsTokenProvider::COMPANY_KEY)),
            'tokenHeader' => app(SettingsTokenProvider::class)->tokenHeader(),
            'tokenHeaderFormen' => TokenHeader::alle(),
            'schemaMiete' => $schemaMiete,
            'schemaKauf' => $schemaKauf,
            'schemata' => $this->liste($settings->get(self::SCHEMATA)),
            'verbindungGeprueftAt' => $this->datum($settings->get(self::VERBINDUNG_GEPRUEFT_AT)),
            'verbindungErgebnis' => $this->text($settings->get(self::VERBINDUNG_ERGEBNIS)),
            'konfliktverhalten' => $sync->konfliktverhalten(),
            'leereFelderLoeschen' => $sync->leereFelderLoeschen() ? self::LEERE_FELDER_AN : self::LEERE_FELDER_AUS,
            'felder' => $resolver->felder(),
            'codes' => $resolver->codes(),
            'schemaFelder' => $this->schemaFelder($settings, array_filter([$schemaMiete, $schemaKauf])),
            'logs' => TransferLog::query()->latest()->limit(30)->get(),
        ]);
    }

    public function storeToken(FlowfactTokenRequest $request, SettingsRepository $settings): RedirectResponse
    {
        $settings->setSecret(SettingsTokenProvider::TOKEN_KEY, trim($request->string('api_token')->value()));
        $settings->set(self::TOKEN_HINTERLEGT_AT, Carbon::now()->toIso8601String());
        $settings->forget(self::VERBINDUNG_GEPRUEFT_AT);
        $settings->forget(self::VERBINDUNG_ERGEBNIS);

        return redirect()->route('admin.flowfact.edit')
            ->with('status', 'Der API-Token wurde hinterlegt. Bitte prüfen Sie die Verbindung.');
    }

    public function destroyToken(SettingsRepository $settings): RedirectResponse
    {
        $settings->forget(SettingsTokenProvider::TOKEN_KEY);
        $settings->forget(self::TOKEN_HINTERLEGT_AT);
        $settings->forget(self::VERBINDUNG_GEPRUEFT_AT);
        $settings->forget(self::VERBINDUNG_ERGEBNIS);

        return redirect()->route('admin.flowfact.edit')
            ->with('status', 'Der API-Token wurde entfernt. Übertragungen sind bis zur erneuten Hinterlegung nicht möglich.');
    }

    public function updateSettings(FlowfactSettingsRequest $request, SettingsRepository $settings): RedirectResponse
    {
        $this->setzeOderVergesse($settings, SettingsTokenProvider::COMPANY_KEY, $request->input('company_id'));

        if ($request->filled('token_header')) {
            $settings->set(SettingsTokenProvider::HEADER_KEY, $request->string('token_header')->value());
        }
        $this->setzeOderVergesse($settings, ListingSyncService::SCHEMA_MIETE, $request->input('schema_miete'));
        $this->setzeOderVergesse($settings, ListingSyncService::SCHEMA_KAUF, $request->input('schema_kauf'));

        // Prüfbericht 2026-09-12, Befund 14: Konfliktverhalten und Löschsemantik
        // sind im Adminbereich einstellbar (docs/connector.md Abschnitt 7).
        if ($request->filled('konfliktverhalten')) {
            $settings->set(ListingSyncService::KONFLIKTVERHALTEN, $request->string('konfliktverhalten')->value());
        }

        if ($request->filled('leere_felder_loeschen')) {
            $settings->set(ListingSyncService::LEERE_FELDER_LOESCHEN, $request->string('leere_felder_loeschen')->value() === self::LEERE_FELDER_AN);
        }

        return redirect()->route('admin.flowfact.edit')
            ->with('status', 'Die FLOWFACT-Einstellungen wurden gespeichert.');
    }

    /**
     * Diagnose am echten Konto: mehrere lesende Aufrufe mit unterschiedlichen
     * Kopfzeilen, damit sich bei HTTP 401/403 unterscheiden lässt, ob der
     * Token, eine Kopfzeile oder eine Berechtigung fehlt. Es wird nichts
     * geschrieben. Antworten werden gekürzt und bereinigt angezeigt, der
     * Token erscheint nie.
     */
    public function diagnose(SettingsRepository $settings, FlowfactClient $client, TokenScrubber $scrubber): RedirectResponse
    {
        if (! $settings->hasSecret(SettingsTokenProvider::TOKEN_KEY)) {
            return redirect()->route('admin.flowfact.edit')
                ->with('error', 'Es ist kein API-Token hinterlegt.');
        }

        $sonden = [];

        foreach (TokenHeader::alle() as $form) {
            $sonden[] = ['user-service', '/users/currentUser', [], ['x-ff-version' => '2'], 'Aktueller Benutzer, '.TokenHeader::label($form), $form];
        }

        $sonden[] = ['user-service', '/users/currentUser', [], [], 'Aktueller Benutzer, ohne x-ff-version', null];
        $sonden[] = ['schema-service', '/v2/schemas', ['group' => 'estates'], [], 'Schemata der Gruppe estates', null];
        $sonden[] = ['schema-service', '/stats', ['groups' => 'true'], [], 'Schema-Statistik', null];
        $sonden[] = ['portal-management-service', '/portals', [], [], 'Portale', null];
        $sonden[] = ['entity-service', '/schemas/estates/entities', ['size' => '1'], ['x-ff-version' => '2'], 'Objekte (1 Datensatz, x-ff-version 2)', null];

        $ergebnisse = [];

        foreach ($sonden as [$service, $pfad, $query, $headers, $beschreibung, $form]) {
            $eintrag = ['beschreibung' => $beschreibung, 'aufruf' => 'GET '.$service.$pfad, 'status' => null, 'antwort' => ''];

            try {
                $daten = $client->usingTokenHeader($form)->get($service, $pfad, [], $query, $headers);
                $eintrag['status'] = $client->lastStatus();
                $eintrag['antwort'] = $this->kurz($scrubber, is_string($daten) ? $daten : json_encode($daten, JSON_UNESCAPED_UNICODE));
            } catch (FlowfactException $exception) {
                $eintrag['status'] = $client->lastStatus();
                $protokoll = TransferLog::query()->latest('id')->first();
                $antwort = is_array($protokoll?->details) ? (string) ($protokoll->details['response'] ?? '') : '';
                $eintrag['antwort'] = $this->kurz($scrubber, $antwort !== '' ? $antwort : $exception->getMessage());
            } catch (Throwable $exception) {
                $eintrag['antwort'] = $this->kurz($scrubber, $exception->getMessage());
            }

            $ergebnisse[] = $eintrag;
        }

        $client->usingTokenHeader(null);

        return redirect()->route('admin.flowfact.edit')->with('diagnose', $ergebnisse);
    }

    /**
     * Formatangabe zum hinterlegten Token ohne dessen Wert: Länge, ob er der
     * UUID-Form der FLOWFACT-Zugangsschlüssel entspricht und ob er
     * Leerzeichen oder Zeilenumbrüche enthält (Kopierfehler).
     *
     * @return array{laenge: int, uuid: bool, whitespace: bool}|null
     */
    private function tokenFormat(SettingsRepository $settings): ?array
    {
        $token = $settings->getSecret(SettingsTokenProvider::TOKEN_KEY);

        if (! is_string($token) || $token === '') {
            return null;
        }

        return [
            'laenge' => mb_strlen($token),
            'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($token)) === 1,
            'whitespace' => preg_match('/\s/', $token) === 1,
        ];
    }

    private function kurz(TokenScrubber $scrubber, ?string $text): string
    {
        return mb_substr(trim((string) $scrubber->scrub($text ?? '')), 0, 400);
    }

    /**
     * Verbindungstest über GET user-service/users/currentUser. Das Ergebnis
     * enthält nie den Token, Fehlermeldungen sind bereinigt.
     */
    public function testConnection(SettingsRepository $settings, UserService $users, TokenScrubber $scrubber): RedirectResponse
    {
        if (! $settings->hasSecret(SettingsTokenProvider::TOKEN_KEY)) {
            return redirect()->route('admin.flowfact.edit')
                ->with('error', 'Es ist kein API-Token hinterlegt.');
        }

        try {
            $user = $users->currentUser();
            $ergebnis = sprintf(
                'Verbunden als %s (Typ %s), Company %s.',
                (string) ($user['loginRelatedMailAddress'] ?? $user['businessMailAddress'] ?? $user['loginName'] ?? $user['id'] ?? 'unbekannt'),
                (string) ($user['type'] ?? 'unbekannt'),
                (string) ($user['companyId'] ?? 'unbekannt'),
            );
            $erfolgreich = true;
        } catch (FlowfactException $exception) {
            $ergebnis = 'Fehler: '.$exception->getMessage();
            $erfolgreich = false;
        } catch (Throwable $exception) {
            $ergebnis = 'Fehler: '.$scrubber->scrub($exception->getMessage());
            $erfolgreich = false;
        }

        $settings->set(self::VERBINDUNG_GEPRUEFT_AT, Carbon::now()->toIso8601String());
        $settings->set(self::VERBINDUNG_ERGEBNIS, mb_substr((string) $scrubber->scrub($ergebnis), 0, 1000));

        return redirect()->route('admin.flowfact.edit')
            ->with($erfolgreich ? 'status' : 'error', $erfolgreich ? 'Verbindungstest erfolgreich: '.$ergebnis : 'Verbindungstest fehlgeschlagen. '.$ergebnis);
    }

    /**
     * Lädt die Estate-Schemata des Kontos und die Properties der gewählten
     * Schemata in die Einstellungen (Auswahl der Zielfelder im Formular).
     */
    public function loadSchemas(SettingsRepository $settings, SchemaService $schemaService, TokenScrubber $scrubber): RedirectResponse
    {
        if (! $settings->hasSecret(SettingsTokenProvider::TOKEN_KEY)) {
            return redirect()->route('admin.flowfact.edit')
                ->with('error', 'Es ist kein API-Token hinterlegt.');
        }

        try {
            $schemata = $schemaService->estateSchemas();
            $settings->set(self::SCHEMATA, $schemata);

            foreach ($schemata as $schema) {
                $definition = $schemaService->schema($schema['name']);
                $settings->set('flowfact.schema_cache_'.$schema['name'], [
                    'name' => $schema['name'],
                    'geladen_at' => Carbon::now()->toIso8601String(),
                    'properties' => SchemaService::properties($definition),
                ]);
            }
        } catch (FlowfactException $exception) {
            return redirect()->route('admin.flowfact.edit')
                ->with('error', 'Schemata konnten nicht geladen werden: '.$exception->getMessage());
        } catch (Throwable $exception) {
            return redirect()->route('admin.flowfact.edit')
                ->with('error', 'Schemata konnten nicht geladen werden: '.$scrubber->scrub($exception->getMessage()));
        }

        return redirect()->route('admin.flowfact.edit')
            ->with('status', sprintf('%d Estate-Schema(ta) geladen.', count($schemata)));
    }

    private function setzeOderVergesse(SettingsRepository $settings, string $schluessel, mixed $wert): void
    {
        $text = is_string($wert) ? trim($wert) : '';

        if ($text === '') {
            $settings->forget($schluessel);

            return;
        }

        $settings->set($schluessel, $text);
    }

    /**
     * Zielfelder aus den zwischengespeicherten Schemata, vereinigt über
     * Miete und Kauf.
     *
     * @param  array<int, string>  $schemaNamen
     * @return array<string, array{type: string, caption: string}>
     */
    private function schemaFelder(SettingsRepository $settings, array $schemaNamen): array
    {
        $felder = [];

        foreach ($schemaNamen as $name) {
            $cache = $settings->get('flowfact.schema_cache_'.$name);

            if (is_array($cache) && is_array($cache['properties'] ?? null)) {
                foreach ($cache['properties'] as $feld => $definition) {
                    if (is_string($feld) && is_array($definition)) {
                        $felder[$feld] = ['type' => (string) ($definition['type'] ?? ''), 'caption' => (string) ($definition['caption'] ?? $feld)];
                    }
                }
            }
        }

        ksort($felder);

        return $felder;
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

    /**
     * @return list<array{name: string, caption: string}>
     */
    private function liste(mixed $wert): array
    {
        if (! is_array($wert)) {
            return [];
        }

        $liste = [];

        foreach ($wert as $eintrag) {
            if (is_array($eintrag) && isset($eintrag['name']) && is_string($eintrag['name'])) {
                $liste[] = ['name' => $eintrag['name'], 'caption' => (string) ($eintrag['caption'] ?? $eintrag['name'])];
            }
        }

        return $liste;
    }
}
