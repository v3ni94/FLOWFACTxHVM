<?php

declare(strict_types=1);

namespace App\Domain\Listing;

/**
 * Ergebnis der Warmmietenberechnung (Datenvertrag Abschnitt 3).
 */
final readonly class RentResult
{
    /**
     * @param  array<int, RentHinweis>  $hinweise
     */
    public function __construct(
        public int $warmmieteCent,
        public array $hinweise = [],
    ) {}

    public function hatHinweis(RentHinweis $hinweis): bool
    {
        return in_array($hinweis, $this->hinweise, true);
    }
}
