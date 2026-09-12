<?php

declare(strict_types=1);

namespace App\Flowfact\Mapping;

/**
 * Ergebnis des Mappers: Werteform für FLOWFACT plus Warnungen.
 */
final readonly class MappedPayload
{
    /**
     * @param  array<string, array{values: list<mixed>}>  $fields
     * @param  list<string>  $warnungen
     * @param  list<string>  $leereFelder  FLOWFACT-Feldnamen zugeordneter Felder, deren lokaler Wert leer ist
     *                                     (Prüfbericht 2026-09-11, Befund 5). Beim PATCH werden sie als
     *                                     { "values": [] } gesendet, damit FLOWFACT den alten Wert löscht.
     *                                     Der Sync beschränkt die Liste auf Felder, die mit einer früheren
     *                                     Freigabe tatsächlich gesendet wurden (Masterprompt Abschnitt 23).
     * @param  string|null  $blockiert  Grund, weshalb der Payload nicht übertragen werden darf (z. B. Objektart
     *                                  Stellplatz ohne FLOWFACT-Code); null, wenn die Übertragung möglich ist
     * @param  array<string, string>  $zuordnung  eigenes Feld => FLOWFACT-Feld für jedes mit Wert gesendete Feld
     *                                            (Prüfbericht 2026-09-12, Befund 5: wird je Übertragung gespeichert)
     * @param  list<string>  $leereQuellen  eigene Feldnamen zugeordneter Felder, deren Wert leer ist
     */
    public function __construct(
        public array $fields,
        public array $warnungen = [],
        public bool $showAddress = true,
        public array $leereFelder = [],
        public ?string $blockiert = null,
        public array $zuordnung = [],
        public array $leereQuellen = [],
    ) {}

    /**
     * Felder für den PATCH: Werte plus Löschbefehle für geleerte Felder.
     *
     * @return array<string, array{values: list<mixed>}>
     */
    public function fieldsMitLoeschungen(): array
    {
        $fields = $this->fields;

        foreach ($this->leereFelder as $ziel) {
            if (! array_key_exists($ziel, $fields)) {
                $fields[$ziel] = ['values' => []];
            }
        }

        return $fields;
    }

    /**
     * Löschsemantik mit Absicht (Masterprompt Abschnitt 23): Nur Felder, die
     * Müller FLOW mit einer früheren Freigabe tatsächlich gesendet hat, dürfen
     * geleert werden. Felder, die nie gesendet wurden, bleiben in FLOWFACT
     * unangetastet.
     *
     * @param  list<string>  $vorherGesendeteFelder  FLOWFACT-Feldnamen des zuletzt übertragenen Payloads
     */
    public function loeschungenBeschraenktAuf(array $vorherGesendeteFelder): self
    {
        return $this->mitLeerenFeldern(array_values(array_intersect($this->leereFelder, $vorherGesendeteFelder)));
    }

    /**
     * Löschliste aus der tatsächlich gesendeten Zuordnung der letzten
     * Übertragung (Prüfbericht 2026-09-12, Befund 5). Geleert wird ein
     * FLOWFACT-Feld nur, wenn Müller FLOW es zuvor selbst mit Wert gesendet
     * hat und
     * - es in der aktuellen Freigabe leer ist (gleiche Zuordnung), oder
     * - sein eigenes Feld jetzt leer ist, auch wenn die Zuordnung inzwischen
     *   auf ein anderes Zielfeld zeigt (das neue Zielfeld wurde nie gesendet
     *   und erhält keine leere Werteliste), oder
     * - sein eigenes Feld jetzt mit Wert an ein anderes Zielfeld geht (der
     *   alte Wert bliebe sonst veraltet im alten Feld stehen).
     * Ein abgeschaltetes Feld (keine Zuordnung mehr) wird nie geleert.
     * Zielfelder, die aktuell mit Wert gesendet werden, stehen nie in der
     * Löschliste.
     *
     * @param  array<string, string>  $vorherigeZuordnung  eigenes Feld => FLOWFACT-Feld der letzten Übertragung
     * @param  list<string>  $vorherGesendeteFelder  FLOWFACT-Feldnamen der letzten Übertragung (auch ohne Zuordnung bekannt)
     */
    public function loeschungenAus(array $vorherigeZuordnung, array $vorherGesendeteFelder): self
    {
        $loeschungen = array_intersect($this->leereFelder, $vorherGesendeteFelder);

        foreach ($vorherigeZuordnung as $quelle => $altesZiel) {
            $quelle = (string) $quelle;
            $altesZiel = (string) $altesZiel;

            if (array_key_exists($altesZiel, $this->fields)) {
                continue;
            }

            $jetztLeer = in_array($quelle, $this->leereQuellen, true);
            $verschoben = array_key_exists($quelle, $this->zuordnung) && $this->zuordnung[$quelle] !== $altesZiel;

            if ($jetztLeer || $verschoben) {
                $loeschungen[] = $altesZiel;
            }
        }

        return $this->mitLeerenFeldern(array_values(array_unique($loeschungen)));
    }

    /**
     * @param  list<string>  $leereFelder
     */
    private function mitLeerenFeldern(array $leereFelder): self
    {
        return new self(
            fields: $this->fields,
            warnungen: $this->warnungen,
            showAddress: $this->showAddress,
            leereFelder: $leereFelder,
            blockiert: $this->blockiert,
            zuordnung: $this->zuordnung,
            leereQuellen: $this->leereQuellen,
        );
    }

    /**
     * FLOWFACT-Feldnamen, die dieser Payload mit Wert sendet.
     *
     * @return list<string>
     */
    public function gesendeteFelder(): array
    {
        return array_keys($this->fields);
    }

    /**
     * Entitätsstatus überschreiben (Prüfbericht 2026-09-12, Befund 10): ohne
     * Veröffentlichungsrecht wird die Entität inaktiv gesendet, mit Warnung.
     */
    public function mitStatus(string $status, ?string $warnung = null): self
    {
        $fields = $this->fields;
        $fields['status'] = ['values' => [$status]];

        $warnungen = $this->warnungen;

        if ($warnung !== null && ! in_array($warnung, $warnungen, true)) {
            $warnungen[] = $warnung;
        }

        $zuordnung = $this->zuordnung;
        $zuordnung['status'] = 'status';

        return new self(
            fields: $fields,
            warnungen: $warnungen,
            showAddress: $this->showAddress,
            leereFelder: $this->leereFelder,
            blockiert: $this->blockiert,
            zuordnung: $zuordnung,
            leereQuellen: $this->leereQuellen,
        );
    }

    /**
     * Gesendeter Entitätsstatus (active, inactive).
     */
    public function status(): ?string
    {
        $wert = $this->fields['status']['values'][0] ?? null;

        return is_string($wert) ? $wert : null;
    }
}
