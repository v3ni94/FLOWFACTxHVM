<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use Throwable;

final readonly class SyncResult
{
    /**
     * @param  list<string>  $warnungen
     * @param  Throwable|null  $ausnahme  Ursache eines Fehlers, damit Jobs zwischen
     *                                    Ratenbegrenzung, Auth-Fehler und Transportfehler unterscheiden können
     * @param  int|null  $releaseId  Freigabeversion, die übertragen wurde (Masterprompt-Abgleich B.6)
     */
    public function __construct(
        public bool $ok,
        public string $meldung,
        public ?string $entityId = null,
        public array $warnungen = [],
        public bool $busy = false,
        public ?Throwable $ausnahme = null,
        public ?int $releaseId = null,
    ) {}

    public static function busy(): self
    {
        return new self(false, 'Für dieses Objekt läuft bereits eine Übertragung.', busy: true);
    }

    /**
     * @param  list<string>  $warnungen
     */
    public static function failed(string $meldung, array $warnungen = [], ?Throwable $ausnahme = null): self
    {
        return new self(false, $meldung, warnungen: $warnungen, ausnahme: $ausnahme);
    }
}
