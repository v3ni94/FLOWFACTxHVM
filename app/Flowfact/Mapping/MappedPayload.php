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
     */
    public function __construct(
        public array $fields,
        public array $warnungen = [],
        public bool $showAddress = true,
    ) {}
}
