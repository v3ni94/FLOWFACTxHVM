<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Flowfact\Mapping\FieldCatalog;
use App\Flowfact\Mapping\FieldMappingResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FlowfactMappingRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Pflege der Feld- und Codezuordnung (docs/connector.md Abschnitt 4.2, 4.3).
 *
 * Gespeichert werden nur Abweichungen vom Standard: ein leeres Zielfeld
 * bedeutet "Feld abgeschaltet" (null), sofern der Standard ein Ziel hat.
 * Es werden ausschließlich Schlüssel aus dem FieldCatalog akzeptiert, damit
 * kein internes Feld eine Zuordnung erhalten kann.
 */
class FlowfactMappingController extends Controller
{
    public function update(FlowfactMappingRequest $request, SettingsRepository $settings): RedirectResponse
    {
        $felder = (array) $request->input('felder', []);
        $codes = (array) $request->input('codes', []);

        $feldzuordnung = [];

        foreach (FieldCatalog::FELDER as $feld => $definition) {
            if (in_array($definition['art'], [FieldCatalog::ADRESSTEIL, FieldCatalog::AUSSTATTUNG, FieldCatalog::ABSICHTLICH], true)) {
                continue;
            }

            if (! array_key_exists($feld, $felder)) {
                continue;
            }

            $ziel = is_string($felder[$feld]) ? trim($felder[$feld]) : '';
            $standard = $definition['ziel'];

            if ($ziel === '' && $standard === null) {
                continue;
            }

            if ($ziel === $standard) {
                continue;
            }

            $feldzuordnung[$feld] = $ziel === '' ? null : $ziel;
        }

        $codezuordnung = [];

        foreach (FieldCatalog::codeGruppen() as $gruppe => $werte) {
            foreach ($werte as $wert => $standard) {
                $schluessel = $gruppe.'.'.$wert;

                if (! array_key_exists($schluessel, $codes)) {
                    continue;
                }

                $code = is_string($codes[$schluessel]) ? trim($codes[$schluessel]) : '';

                if ($code === '' && $standard === null) {
                    continue;
                }

                if ($code === $standard) {
                    continue;
                }

                $codezuordnung[$schluessel] = $code === '' ? null : $code;
            }
        }

        $settings->set(FieldMappingResolver::FELDZUORDNUNG, $feldzuordnung);
        $settings->set(FieldMappingResolver::CODEZUORDNUNG, $codezuordnung);

        return redirect()->route('admin.flowfact.edit')
            ->with('status', 'Die Feld- und Codezuordnung wurde gespeichert.');
    }
}
