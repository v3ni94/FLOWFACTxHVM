<?php

declare(strict_types=1);

namespace App\Domain\Listing;

/**
 * Ergebnis der Vollständigkeitsprüfung vor "bereit" (Datenvertrag Abschnitt 4.1).
 */
final readonly class CompletenessResult
{
    /**
     * @param  array<string, string>  $fehlend  Feldschlüssel => deutsches Label
     * @param  array<int, string>  $hinweise  Vor Veröffentlichung zu bestätigende Hinweise
     */
    public function __construct(
        public array $fehlend = [],
        public array $hinweise = [],
    ) {}

    public function istVollstaendig(): bool
    {
        return $this->fehlend === [];
    }
}
