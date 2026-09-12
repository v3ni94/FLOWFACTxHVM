<?php

declare(strict_types=1);

namespace App\Flowfact\Mapping;

use App\Domain\Listing\ListingSnapshot;
use App\Domain\Listing\Merkmale;
use App\Domain\Listing\PublishableFields;
use App\Enums\AdressFreigabe;
use App\Enums\GewerbeUnterart;
use App\Enums\MerkmalWert;
use App\Enums\Objektart;
use App\Models\Listing;
use BackedEnum;
use DateTimeInterface;

/**
 * Freigabeversion (ListingSnapshot) -> FLOWFACT-Werteform { feld: { values: [wert] } }
 * (docs/connector.md Abschnitt 4, ADR-003, Masterprompt-Abgleich B.6).
 *
 * WARUM Positivliste: Dieser Mapper liest ausschließlich die Schlüssel aus
 * PublishableFields::LISTING, PRICE und ENERGY aus der Momentaufnahme. Die
 * Relation "internal" wird nie angefasst, interne Felder können daher
 * technisch nicht in die Übertragung gelangen. Ein Test mit Markerwerten
 * belegt das.
 *
 * WARUM Momentaufnahme: Übertragung und Veröffentlichung arbeiten mit der
 * jüngsten Freigabeversion, nie mit dem Live-Stand (Masterprompt Abschnitt 19,
 * 23). map(Listing) bleibt als dünne Hülle erhalten, die die Momentaufnahme
 * aus dem Live-Stand bildet (Vorschau, Tests).
 */
final class FlowfactPayloadMapper
{
    public const string WARNUNG_HEIZKOSTEN_ENTHALTEN = 'Heizkosten sind in den Nebenkosten enthalten und werden nicht separat übertragen';

    /** @var list<string> */
    private array $leereFelder = [];

    /** @var list<string> */
    private array $leereQuellen = [];

    /** @var array<string, string> eigenes Feld => FLOWFACT-Feld der gesendeten Werte */
    private array $zuordnung = [];

    private ?string $blockiert = null;

    public function __construct(
        private readonly FieldMappingResolver $resolver,
    ) {}

    public function map(Listing $listing): MappedPayload
    {
        $listing->loadMissing(['price', 'energy', 'media']);

        return $this->mapSnapshot(ListingSnapshot::fromListing($listing));
    }

    public function mapSnapshot(ListingSnapshot $snapshot): MappedPayload
    {
        $fields = [];
        $warnungen = [];
        $adresse = [];
        $this->leereFelder = [];
        $this->leereQuellen = [];
        $this->zuordnung = [];
        $this->blockiert = null;

        $daten = $snapshot->listing;

        foreach (PublishableFields::LISTING as $feld) {
            $wert = $daten[$feld] ?? null;

            if ($feld === 'objektart') {
                $this->objektart($wert, $daten['gewerbe_unterart'] ?? null, $fields, $warnungen);

                continue;
            }

            $this->verarbeite($feld, $wert, $fields, $warnungen, $adresse);
        }

        $price = $snapshot->price;
        $heizkostenEnthalten = (bool) ($price['heizkosten_in_nebenkosten_enthalten'] ?? false);

        foreach (PublishableFields::PRICE as $feld) {
            $wert = $price[$feld] ?? null;

            // Prüfbericht 2026-09-11, Befund 13: Sind die Heizkosten in den
            // Nebenkosten enthalten (Fall B), gehen sie nicht zusätzlich als
            // eigener Wert an FLOWFACT, sonst würde das Portal sie doppelt zählen.
            if ($feld === 'heizkosten_cent' && $heizkostenEnthalten && $wert !== null) {
                $warnungen[] = self::WARNUNG_HEIZKOSTEN_ENTHALTEN;
                $wert = null;
            }

            $this->verarbeite($feld, $wert, $fields, $warnungen, $adresse);
        }

        $energy = $snapshot->energy;

        foreach (PublishableFields::ENERGY as $feld) {
            $this->verarbeite('energie.'.$feld, $energy[$feld] ?? null, $fields, $warnungen, $adresse);
        }

        $this->adresse($adresse, $fields, $warnungen);

        // Status ist active: Entwürfe kommen nie bis hierher, weil der
        // Sync-Service sie vorher ablehnt. Fehlt dem handelnden Benutzer das
        // Veröffentlichungsrecht, setzt der Sync-Service inactive
        // (MappedPayload::mitStatus, Prüfbericht 2026-09-12, Befund 10).
        $fields['status'] = ['values' => ['active']];
        $this->zuordnung['status'] = 'status';

        $leereFelder = array_values(array_unique(array_filter(
            $this->leereFelder,
            static fn (string $ziel): bool => ! array_key_exists($ziel, $fields),
        )));

        return new MappedPayload(
            fields: $fields,
            warnungen: array_values(array_unique($warnungen)),
            showAddress: $this->showAddress($daten),
            leereFelder: $leereFelder,
            blockiert: $this->blockiert,
            zuordnung: array_filter($this->zuordnung, static fn (string $ziel): bool => array_key_exists($ziel, $fields)),
            leereQuellen: array_values(array_unique($this->leereQuellen)),
        );
    }

    /**
     * Adressfreigabe steuert showAddress im Publish-Request (Masterprompt-
     * Abgleich B.2, B.7). Die Adresse selbst wird immer übertragen; bei
     * "nur PLZ und Ort" verbirgt FLOWFACT die Straße über showAddress
     * (am Konto zu verifizieren, docs/flowfact-api.md Abschnitt 9).
     *
     * @param  array<string, mixed>  $daten
     */
    private function showAddress(array $daten): bool
    {
        $freigabe = $daten['adress_freigabe'] ?? null;

        if ($freigabe instanceof AdressFreigabe) {
            return $freigabe->adresseAnzeigen();
        }

        if (is_string($freigabe) && AdressFreigabe::tryFrom($freigabe) !== null) {
            return AdressFreigabe::from($freigabe)->adresseAnzeigen();
        }

        return (bool) ($daten['adresse_im_inserat_anzeigen'] ?? true);
    }

    /**
     * estatetype aus Objektart und Gewerbe-Unterart (docs/connector.md 4.3,
     * Masterprompt-Abgleich B.2). Gewerbe: Unterart bestimmt den Code (Büro
     * 06B, Laden 05L, Lager und Sonstiges offen). Stellplatz ohne Code sperrt
     * die Übertragung, weil FLOWFACT ohne estatetype kein sinnvolles Objekt
     * erhält; über flowfact.codezuordnung (objektart.stellplatz) wird sie
     * möglich.
     *
     * @param  array<string, array{values: list<mixed>}>  $fields
     * @param  list<string>  $warnungen
     */
    private function objektart(mixed $objektart, mixed $unterart, array &$fields, array &$warnungen): void
    {
        $definition = FieldCatalog::FELDER['objektart'];
        $ziel = $this->resolver->zielfeld('objektart');
        $objektartWert = $objektart instanceof BackedEnum ? (string) $objektart->value : (is_scalar($objektart) ? (string) $objektart : '');

        if ($objektartWert === '') {
            if ($ziel !== null) {
                $this->leereFelder[] = $ziel;
                $this->leereQuellen[] = 'objektart';
            }

            return;
        }

        if ($ziel === null) {
            $warnungen[] = 'Keine FLOWFACT-Zuordnung für '.$definition['label'];

            return;
        }

        $unterartWert = $unterart instanceof BackedEnum ? (string) $unterart->value : (is_scalar($unterart) ? (string) $unterart : '');

        if ($objektartWert === Objektart::Gewerbe->value && $unterartWert !== '') {
            $code = $this->resolver->code('gewerbe_unterart', $unterartWert);

            if ($code === null) {
                $warnungen[] = $unterartWert === GewerbeUnterart::Lager->value
                    ? FieldCatalog::WARNUNG_LAGER
                    : sprintf('Kein FLOWFACT-Code für %s (%s)', FieldCatalog::label('gewerbe_unterart'), FieldCatalog::codeLabel('gewerbe_unterart', $unterartWert));

                return;
            }

            $fields[$ziel] = ['values' => [$code]];
            $this->zuordnung['objektart'] = $ziel;

            return;
        }

        $code = $this->resolver->code('objektart', $objektartWert);

        if ($code === null) {
            $warnungen[] = sprintf('Kein FLOWFACT-Code für %s (%s)', $definition['label'], FieldCatalog::codeLabel('objektart', $objektartWert));

            if ($objektartWert === Objektart::Stellplatz->value) {
                $this->blockiert = FieldCatalog::MELDUNG_STELLPLATZ;
            }

            return;
        }

        $fields[$ziel] = ['values' => [$code]];
        $this->zuordnung['objektart'] = $ziel;
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
                    // Dreiwertig (Masterprompt-Abgleich B.2, B.8): ältere boolesche
                    // Werte bleiben lesbar, "unbekannt" wird weder als ja noch
                    // als nein übertragen (null, wie ein leeres Feld). Umbenannte
                    // Merkmale lesen den älteren Schlüssel (Merkmale::ALIASE).
                    $this->verarbeite('ausstattung.'.$schluessel, $this->merkmal($merkmale, $schluessel)->alsBool(), $fields, $warnungen, $adresse);
                }

                return;

            case FieldCatalog::VERMIETET_FLAG:
                $this->vermietetFlag($feld, $wert, $fields);

                return;
        }

        if ($wert === null || $wert === '') {
            // Prüfbericht 2026-09-11, Befund 5: leere, aber zugeordnete Felder
            // werden gemerkt, damit der PATCH sie in FLOWFACT löschen kann.
            $ziel = $this->resolver->zielfeld($feld);

            if ($ziel !== null) {
                $this->leereFelder[] = $ziel;
                $this->leereQuellen[] = $feld;
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
        $this->zuordnung[$feld] = $ziel;
    }

    /**
     * @param  array<string, mixed>  $merkmale
     */
    private function merkmal(array $merkmale, string $schluessel): MerkmalWert
    {
        if (array_key_exists($schluessel, $merkmale)) {
            return MerkmalWert::aus($merkmale[$schluessel]);
        }

        $alias = Merkmale::ALIASE[$schluessel] ?? null;

        if ($alias !== null && array_key_exists($alias, $merkmale)) {
            return MerkmalWert::aus($merkmale[$alias]);
        }

        return MerkmalWert::Unbekannt;
    }

    /**
     * Nutzungsstatus: vermietet -> let true, leerstehend -> let false, alles
     * andere (anderweitig belegt, unbekannt) wird nicht gesendet und nicht
     * gelöscht, weil daraus kein boolescher Wert folgt.
     *
     * @param  array<string, array{values: list<mixed>}>  $fields
     */
    private function vermietetFlag(string $feld, mixed $wert, array &$fields): void
    {
        $ziel = $this->resolver->zielfeld($feld);

        if ($ziel === null) {
            return;
        }

        $status = $wert instanceof BackedEnum ? (string) $wert->value : (is_scalar($wert) ? (string) $wert : '');

        if (! array_key_exists($status, FieldCatalog::NUTZUNGSSTATUS_LET)) {
            return;
        }

        $fields[$ziel] = ['values' => [FieldCatalog::NUTZUNGSSTATUS_LET[$status]]];
        $this->zuordnung[$feld] = $ziel;
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
            FieldCatalog::DATUM => $this->datum($wert),
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

    /**
     * Datum als Y-m-d. Die Momentaufnahme speichert Datumswerte im ATOM-Format
     * (ListingSnapshot::normalisiert), daher genügt der Datumsanteil.
     */
    private function datum(mixed $wert): ?string
    {
        if ($wert instanceof DateTimeInterface) {
            return $wert->format('Y-m-d');
        }

        $text = $this->text($wert);

        if ($text !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1) {
            return substr($text, 0, 10);
        }

        return $text;
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
                $this->leereQuellen[] = FieldCatalog::ADRESSFELD;
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
        $this->zuordnung[FieldCatalog::ADRESSFELD] = $ziel;
    }
}
