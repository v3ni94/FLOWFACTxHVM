<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

final readonly class PublishResult
{
    /**
     * @param  list<string>  $warnungen
     */
    public function __construct(
        public bool $ok,
        public string $meldung,
        public array $warnungen = [],
    ) {}
}
