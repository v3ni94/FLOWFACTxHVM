<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

final readonly class SyncResult
{
    /**
     * @param  list<string>  $warnungen
     */
    public function __construct(
        public bool $ok,
        public string $meldung,
        public ?string $entityId = null,
        public array $warnungen = [],
        public bool $busy = false,
    ) {}

    public static function busy(): self
    {
        return new self(false, 'Für dieses Objekt läuft bereits eine Übertragung.', busy: true);
    }

    public static function failed(string $meldung, array $warnungen = []): self
    {
        return new self(false, $meldung, warnungen: $warnungen);
    }
}
