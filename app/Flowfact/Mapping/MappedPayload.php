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
     */
    public function __construct(
        public array $fields,
        public array $warnungen = [],
        public bool $showAddress = true,
        public array $leereFelder = [],
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
}
