<?php

declare(strict_types=1);

namespace App\Flowfact\Mapping;

use App\Domain\Listing\PublishableFields;
use App\Models\Listing;
use BackedEnum;
use DateTimeInterface;

/**
 * Listing -> FLOWFACT-Werteform { feld: { values: [wert] } }
 * (docs/connector.md Abschnitt 4, ADR-003).
 *
 * WARUM Positivliste: Dieser Mapper liest ausschließlich die Attribute aus
 * PublishableFields::LISTING, PRICE und ENERGY über getAttribute(). Die
 * Relation "internal" wird hier nie angefasst, interne Felder können daher
 * technisch nicht in die Übertragung gelangen. Ein Test mit Markerwerten
 * belegt das.
 */
final class FlowfactPayloadMapper
{
    public const string WARNUNG_HEIZKOSTEN_ENTHALTEN = 'Heizkosten sind in den Nebenkosten enthalten und werden nicht separat übertragen';

    /** @var list<string> */
    private array $leereFelder = [];

    public function __construct(
        private readonly FieldMappingResolver $resolver,
    ) {}

    public function map(Listing $listing): MappedPayload
    {
        $fields = [];
        $warnungen = [];
        $adresse = [];
        $this->leereFelder = [];

        foreach (PublishableFields::LISTING as $feld) {
            $this->verarbeite($feld, $listing->getAttribute($feld), $fields, $warnungen, $adresse);
        }

        $price = $listing->price;
        $heizkostenEnthalten = (bool) $price?->getAttribute('heizkosten_in_nebenkosten_enthalten');

        foreach (PublishableFields::PRICE as $feld) {
            $wert = $price?->getAttribute($feld);

            // Prüfbericht 2026-09-11, Befund 13: Sind die Heizkosten in den
            // Nebenkosten enthalten (Fall B), gehen sie nicht zusätzlich als
            // eigener Wert an FLOWFACT, sonst würde das Portal sie doppelt zählen.
            if ($feld === 'heizkosten_cent' && $heizkostenEnthalten && $wert !== null) {
                $warnungen[] = self::WARNUNG_HEIZKOSTEN_ENTHALTEN;
                $wert = null;
            }

            $this->verarbeite($feld, $wert, $fields, $warnungen, $adresse);
        }

        $energy = $listing->energy;

        foreach (PublishableFields::ENERGY as $feld) {
            $this->verarbeite('energie.'.$feld, $energy?->getAttribute($feld), $fields, $warnungen, $adresse);
        }

        $this->adresse($adresse, $fields, $warnungen);

        // Status ist immer active: Entwürfe kommen nie bis hierher, weil der
        // Sync-Service sie vorher ablehnt.
        $fields['status'] = ['values' => ['active']];

        $leereFelder = array_values(array_unique(array_filter(
            $this->leereFelder,
            static fn (string $ziel): bool => ! array_key_exists($ziel, $fields),
        )));

        return new MappedPayload(
            fields: $fields,
            warnungen: array_values(array_unique($warnungen)),
            showAddress: (bool) $listing->getAttribute('adresse_im_inserat_anzeigen'),
            leereFelder: $leereFelder,
        );
    }

    /**
     * @param  array<string, array{values: list<mixed>}>  $fields
     * @param  list<string>  $warnungen
     * @param  array<string, mixed>  $adresse
     */
    private function verarbeite(string $feld, mixed $wert, array &$fields, array &$warnungen, array &$adresse): void
    {
        $definition = FieldCatalog::FELDER[$feld] ?? null;

        if ($definition === null) {
            if ($wert !== null) {
                $warnungen[] = 'Keine FLOWFACT-Zuordnung für '.$feld;
            }

            return;
        }

        switch ($definition['art']) {
            case FieldCatalog::ABSICHTLICH:
                return;

            case FieldCatalog::ADRESSTEIL:
                $adresse[$feld] = $wert;

                return;

            case FieldCatalog::AUSSTATTUNG:
                $merkmale = is_array($wert) ? $wert : [];

                foreach (FieldCatalog::AUSSTATTUNG_SCHLUESSEL as $schluessel) {
                    $this->verarbeite('ausstattung.'.$schluessel, (bool) ($merkmale[$schluessel] ?? false), $fields, $warnungen, $adresse);
                }

                return;
        }

        if ($wert === null || $wert === '') {
            // Prüfbericht 2026-09-11, Befund 5: leere, aber zugeordnete Felder
            // werden gemerkt, damit der PATCH sie in FLOWFACT löschen kann.
            $ziel = $this->resolver->zielfeld($feld);

            if ($ziel !== null) {
                $this->leereFelder[] = $ziel;
            }

            return;
        }

        $ziel = $this->resolver->zielfeld($feld);

        if ($ziel === null) {
            // Ein nicht gesetztes Merkmal ohne Zuordnung ist kein Informationsverlust.
            if (! ($definition['art'] === FieldCatalog::BOOL && $wert === false)) {
                $warnungen[] = 'Keine FLOWFACT-Zuordnung für '.$definition['label'];
            }

            return;
        }

        $konvertiert = $this->konvertiere($definition, $wert, $warnungen);

        if ($konvertiert === null) {
            return;
        }

        $fields[$ziel] = ['values' => [$konvertiert]];
    }

    /**
     * @param  array{ziel: string|null, label: string, art: string, gruppe?: string, bereich: string}  $definition
     * @param  list<string>  $warnungen
     */
    private function konvertiere(array $definition, mixed $wert, array &$warnungen): mixed
    {
        return match ($definition['art']) {
            FieldCatalog::TEXT => $this->text($wert),
            FieldCatalog::ZAHL => $this->zahl($wert),
            FieldCatalog::EURO => round(((int) $wert) / 100, 2),
            FieldCatalog::BOOL => (bool) $wert,
            FieldCatalog::DATUM => $wert instanceof DateTimeInterface ? $wert->format('Y-m-d') : $this->text($wert),
            FieldCatalog::CODE => $this->code($definition, $wert, $warnungen),
            default => null,
        };
    }

    private function text(mixed $wert): ?string
    {
        if ($wert instanceof BackedEnum) {
            $wert = $wert->value;
        }

        $text = trim((string) $wert);

        return $text === '' ? null : $text;
    }

    private function zahl(mixed $wert): int|float
    {
        if (is_int($wert)) {
            return $wert;
        }

        $zahl = (float) $wert;

        return floor($zahl) === $zahl && abs($zahl) < PHP_INT_MAX ? (int) $zahl : round($zahl, 2);
    }

    /**
     * @param  array{ziel: string|null, label: string, art: string, gruppe?: string, bereich: string}  $definition
     * @param  list<string>  $warnungen
     */
    private function code(array $definition, mixed $wert, array &$warnungen): ?string
    {
        $gruppe = $definition['gruppe'] ?? null;
        $enumWert = $wert instanceof BackedEnum ? (string) $wert->value : (string) $wert;

        if ($gruppe === null) {
            return $enumWert;
        }

        $code = $this->resolver->code($gruppe, $enumWert);

        if ($code === null) {
            $warnungen[] = sprintf('Kein FLOWFACT-Code für %s (%s)', $definition['label'], FieldCatalog::codeLabel($gruppe, $enumWert));

            return null;
        }

        return $code;
    }

    /**
     * Adresse als Objekt (flowfact-api.md Abschnitt 4.1). Die Adresse wird
     * immer übertragen; ob das Portal sie zeigt, steuert showAddress.
     *
     * @param  array<string, mixed>  $adresse
     * @param  array<string, array{values: list<mixed>}>  $fields
     * @param  list<string>  $warnungen
     */
    private function adresse(array $adresse, array &$fields, array &$warnungen): void
    {
        $strasse = trim(sprintf('%s %s', (string) ($adresse['strasse'] ?? ''), (string) ($adresse['hausnummer'] ?? '')));
        $plz = trim((string) ($adresse['plz'] ?? ''));
        $ort = trim((string) ($adresse['ort'] ?? ''));

        $ziel = $this->resolver->zielfeld(FieldCatalog::ADRESSFELD);

        if ($strasse === '' && $plz === '' && $ort === '') {
            if ($ziel !== null) {
                $this->leereFelder[] = $ziel;
            }

            return;
        }

        if ($ziel === null) {
            $warnungen[] = 'Keine FLOWFACT-Zuordnung für '.FieldCatalog::label(FieldCatalog::ADRESSFELD);

            return;
        }

        $land = strtoupper(trim((string) ($adresse['land'] ?? 'DE')));

        $fields[$ziel] = ['values' => [[
            'type' => 'private',
            'street' => $strasse,
            'zipcode' => $plz,
            'city' => $ort,
            'country' => $land === 'DE' || $land === '' ? 'Deutschland' : $land,
        ]]];
    }
}
