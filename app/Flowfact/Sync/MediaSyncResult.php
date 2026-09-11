<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

final readonly class MediaSyncResult
{
    /**
     * @param  list<string>  $warnungen
     * @param  bool  $vollstaendig  false, wenn das Zeitlimit Uploads offen gelassen hat
     */
    public function __construct(
        public array $warnungen = [],
        public bool $vollstaendig = true,
        public int $hochgeladen = 0,
        public int $geloescht = 0,
    ) {}
}
