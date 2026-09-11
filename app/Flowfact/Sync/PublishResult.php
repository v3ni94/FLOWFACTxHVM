<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use Throwable;

final readonly class PublishResult
{
    /**
     * @param  list<string>  $warnungen
     * @param  Throwable|null  $ausnahme  Ursache eines Fehlers für die Wiederholungslogik der Jobs
     */
    public function __construct(
        public bool $ok,
        public string $meldung,
        public array $warnungen = [],
        public ?Throwable $ausnahme = null,
    ) {}
}
