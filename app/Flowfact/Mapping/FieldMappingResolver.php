<?php

declare(strict_types=1);

namespace App\Flowfact\Mapping;

use App\Domain\Settings\SettingsRepository;

/**
 * Verbindet die Standardzuordnung aus FieldCatalog mit den Überschreibungen
 * aus den Einstellungen (docs/connector.md Abschnitt 4.2).
 *
 * flowfact.feldzuordnung: { "eigenes_feld": "flowfact_feld" | null }, null
 * schaltet ein Feld ab. flowfact.codezuordnung: { "gruppe.wert": "CODE" | null }.
 */
final class FieldMappingResolver
{
    public const string FELDZUORDNUNG = 'flowfact.feldzuordnung';

    public const string CODEZUORDNUNG = 'flowfact.codezuordnung';

    public const string STATUS_ZUGEORDNET = 'zugeordnet';

    public const string STATUS_FEHLT = 'Zuordnung fehlt';

    public const string STATUS_NICHT_IM_SCHEMA = 'Zielfeld nicht im Schema';

    /** @var array<string, string|null>|null */
    private ?array $feldzuordnung = null;

    /** @var array<string, string|null>|null */
    private ?array $codezuordnung = null;

    public function __construct(
        private readonly SettingsRepository $settings,
    ) {}

    public function zielfeld(string $feld): ?string
    {
        $ueberschreibung = $this->feldzuordnung();

        if (array_key_exists($feld, $ueberschreibung)) {
            return $ueberschreibung[$feld];
        }

        return FieldCatalog::FELDER[$feld]['ziel'] ?? null;
    }

    public function code(string $gruppe, string $wert): ?string
    {
        $ueberschreibung = $this->codezuordnung();
        $schluessel = $gruppe.'.'.$wert;

        if (array_key_exists($schluessel, $ueberschreibung)) {
            return $ueberschreibung[$schluessel];
        }

        return FieldCatalog::codeGruppen()[$gruppe][$wert] ?? null;
    }

    /**
     * Vollständige Feldtabelle für Adminbereich und flow:flowfact:schema.
     *
     * @return array<string, array{label: string, art: string, bereich: string, standard: string|null, ziel: string|null, ueberschrieben: bool}>
     */
    public function felder(): array
    {
        $tabelle = [];
        $ueberschreibung = $this->feldzuordnung();

        foreach (FieldCatalog::FELDER as $feld => $definition) {
            if (in_array($definition['art'], [FieldCatalog::ADRESSTEIL, FieldCatalog::AUSSTATTUNG, FieldCatalog::ABSICHTLICH], true)) {
                continue;
            }

            $tabelle[$feld] = [
                'label' => $definition['label'],
                'art' => $definition['art'],
                'bereich' => $definition['bereich'],
                'standard' => $definition['ziel'],
                'ziel' => $this->zielfeld($feld),
                'ueberschrieben' => array_key_exists($feld, $ueberschreibung),
            ];
        }

        return $tabelle;
    }

    /**
     * Vollständige Codetabelle für den Adminbereich.
     *
     * @return array<string, array<string, array{label: string, standard: string|null, code: string|null, bestaetigt: bool}>>
     */
    public function codes(): array
    {
        $tabelle = [];

        foreach (FieldCatalog::codeGruppen() as $gruppe => $werte) {
            foreach ($werte as $wert => $standard) {
                $tabelle[$gruppe][$wert] = [
                    'label' => FieldCatalog::codeLabel($gruppe, (string) $wert),
                    'standard' => $standard,
                    'code' => $this->code($gruppe, (string) $wert),
                    'bestaetigt' => $standard !== null && ! in_array($gruppe.'.'.$wert, FieldCatalog::UNBESTAETIGTE_CODES, true),
                ];
            }
        }

        return $tabelle;
    }

    /**
     * Status einer Zuordnung gegen die Properties eines Kontoschemas
     * (docs/connector.md 4.2): zugeordnet, Zuordnung fehlt, Zielfeld nicht im
     * Schema. Ohne geladene Properties kann nur "fehlt" erkannt werden.
     *
     * @param  array<string, array{type: string, caption: string}>  $properties
     */
    public static function zuordnungsstatus(?string $ziel, array $properties): string
    {
        if ($ziel === null || $ziel === '') {
            return self::STATUS_FEHLT;
        }

        if ($properties !== [] && ! array_key_exists($ziel, $properties)) {
            return self::STATUS_NICHT_IM_SCHEMA;
        }

        return self::STATUS_ZUGEORDNET;
    }

    /**
     * @return array<string, string|null>
     */
    private function feldzuordnung(): array
    {
        return $this->feldzuordnung ??= $this->leseJsonObjekt(self::FELDZUORDNUNG);
    }

    /**
     * @return array<string, string|null>
     */
    private function codezuordnung(): array
    {
        return $this->codezuordnung ??= $this->leseJsonObjekt(self::CODEZUORDNUNG);
    }

    /**
     * @return array<string, string|null>
     */
    private function leseJsonObjekt(string $schluessel): array
    {
        $wert = $this->settings->get($schluessel);

        if (is_string($wert)) {
            $wert = json_decode($wert, true);
        }

        if (! is_array($wert)) {
            return [];
        }

        $ergebnis = [];

        foreach ($wert as $feld => $ziel) {
            if (! is_string($feld)) {
                continue;
            }

            $ergebnis[$feld] = is_string($ziel) && trim($ziel) !== '' ? trim($ziel) : null;
        }

        return $ergebnis;
    }
}
