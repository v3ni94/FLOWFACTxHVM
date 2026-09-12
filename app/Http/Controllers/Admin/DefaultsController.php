<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Flowfact\Sync\PublishingService;
use App\Http\Controllers\App\Support\InternalNamePattern;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DefaultsUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Vorgaben im Adminbereich (Masterprompt Abschnitt 18, Masterprompt-Abgleich
 * B.1 Schritt 7): Land, Standardansprechpartner, Portalvorauswahl je
 * Vermarktungsart, Muster für die interne Bezeichnung und neutrale
 * Textbausteine für "Beschreibung Sonstiges".
 */
class DefaultsController extends Controller
{
    public const string LAND = 'defaults.land';

    public const string ANSPRECHPARTNER = 'defaults.ansprechpartner_user_id';

    public const string PORTALE_MIETE = 'defaults.portale_miete';

    public const string PORTALE_KAUF = 'defaults.portale_kauf';

    public const string TEXTBAUSTEINE = 'defaults.textbausteine_sonstiges';

    public function edit(SettingsRepository $settings, PublishingService $publishingService): View
    {
        $portale = $publishingService->isConfigured() ? $publishingService->portals() : [];

        return view('admin.defaults.edit', [
            'land' => (string) ($settings->get(self::LAND) ?? 'DE'),
            'waehrung' => 'EUR',
            'ansprechpartnerId' => $settings->get(self::ANSPRECHPARTNER),
            'benutzer' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'portaleKonfiguriert' => $publishingService->isConfigured(),
            'portale' => $portale,
            'portaleMiete' => (array) $settings->get(self::PORTALE_MIETE, []),
            'portaleKauf' => (array) $settings->get(self::PORTALE_KAUF, []),
            'interneBezeichnungMuster' => (string) ($settings->get(InternalNamePattern::EINSTELLUNGSSCHLUESSEL) ?? InternalNamePattern::STANDARD_MUSTER),
            'platzhalter' => InternalNamePattern::platzhalter(),
            'textbausteine' => (array) $settings->get(self::TEXTBAUSTEINE, []),
        ]);
    }

    public function update(DefaultsUpdateRequest $request, SettingsRepository $settings): RedirectResponse
    {
        $settings->set(self::LAND, mb_strtoupper($request->string('land')->value()));
        $settings->set(self::ANSPRECHPARTNER, $request->input('ansprechpartner_user_id'));
        $settings->set(InternalNamePattern::EINSTELLUNGSSCHLUESSEL, $request->string('interne_bezeichnung_muster')->value());
        $settings->set(self::PORTALE_MIETE, array_values((array) $request->input('portale_miete', [])));
        $settings->set(self::PORTALE_KAUF, array_values((array) $request->input('portale_kauf', [])));

        $zeilen = preg_split('/\r\n|\r|\n/', (string) $request->input('textbausteine_sonstiges', ''));
        $textbausteine = array_values(array_filter(array_map(trim(...), $zeilen ?: []), fn (string $zeile): bool => $zeile !== ''));
        $settings->set(self::TEXTBAUSTEINE, $textbausteine);

        return redirect()->route('admin.defaults.edit')->with('status', 'Die Vorgaben wurden gespeichert.');
    }
}
