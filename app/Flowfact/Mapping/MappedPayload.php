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
     */
    public function __construct(
        public array $fields,
        public array $warnungen = [],
        public bool $showAddress = true,
        public array $leereFelder = [],
        public ?string $blockiert = null,
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
        return new self(
            fields: $this->fields,
            warnungen: $this->warnungen,
            showAddress: $this->showAddress,
            leereFelder: array_values(array_intersect($this->leereFelder, $vorherGesendeteFelder)),
            blockiert: $this->blockiert,
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
}
