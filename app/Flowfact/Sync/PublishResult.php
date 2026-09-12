<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use Throwable;

final readonly class PublishResult
{
    /**
     * @param  list<string>  $warnungen
     * @param  Throwable|null  $ausnahme  Ursache eines Fehlers für die Wiederholungslogik der Jobs
     * @param  int  $angefordert  Anzahl der Portale, für die FLOWFACT die Veröffentlichung angenommen hat
     * @param  list<string>  $manuelleFreigabe  Portalnamen im Fall B (manuelle Freigabe in FLOWFACT erforderlich,
     *                                          Masterprompt Abschnitt 20): weder Fehler noch Erfolg
     * @param  array<string, string>  $jePortal  Ergebnis je Portalname (angefordert, manuelle Freigabe erforderlich, Fehler: ...)
     */
    public function __construct(
        public bool $ok,
        public string $meldung,
        public array $warnungen = [],
        public ?Throwable $ausnahme = null,
        public int $angefordert = 0,
        public array $manuelleFreigabe = [],
        public array $jePortal = [],
    ) {}

    /**
     * Fall B ohne erfolgreich angefordertes Portal: die Oberfläche darf den
     * Bearbeitungsstatus nicht auf veröffentlicht setzen.
     */
    public function nurManuelleFreigabe(): bool
    {
        return $this->ok && $this->angefordert === 0 && $this->manuelleFreigabe !== [];
    }
}
