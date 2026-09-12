<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\PruefArt;
use App\Enums\PruefEbene;

/**
 * Einzelbefund der Prüfung vor der Veröffentlichung (Masterprompt-Abgleich B.4).
 */
final readonly class Befund
{
    /**
     * @param  string  $feld  Feldschlüssel, z. B. "titel", "preis.kaltmiete_cent", "energie.status"
     * @param  string  $label  Deutsche Bezeichnung des Feldes
     * @param  int  $schritt  Schritt des Assistenten (1 bis 8, Masterprompt-Abgleich B.1)
     * @param  bool  $bestaetigungspflichtig  Hinweis, der vor der Veröffentlichung ausdrücklich
     *                                        bestätigt werden muss (Datenvertrag Abschnitt 3);
     *                                        rein informative Hinweise (B.4) sind es nicht.
     */
    public function __construct(
        public string $feld,
        public string $label,
        public PruefEbene $ebene,
        public PruefArt $art,
        public string $meldung,
        public int $schritt,
        public bool $bestaetigungspflichtig = false,
    ) {}

    public static function blockierend(string $feld, string $label, int $schritt, ?string $meldung = null, PruefEbene $ebene = PruefEbene::Intern): self
    {
        return new self($feld, $label, $ebene, PruefArt::Blockierend, $meldung ?? $label.' fehlt.', $schritt);
    }

    public static function hinweis(string $feld, string $label, int $schritt, string $meldung, PruefEbene $ebene = PruefEbene::Intern, bool $bestaetigungspflichtig = false): self
    {
        return new self($feld, $label, $ebene, PruefArt::Hinweis, $meldung, $schritt, $bestaetigungspflichtig);
    }

    public function istBlockierend(): bool
    {
        return $this->art === PruefArt::Blockierend;
    }

    /**
     * @return array{feld: string, label: string, ebene: string, art: string, meldung: string, schritt: int, bestaetigungspflichtig: bool}
     */
    public function toArray(): array
    {
        return [
            'feld' => $this->feld,
            'label' => $this->label,
            'ebene' => $this->ebene->value,
            'art' => $this->art->value,
            'meldung' => $this->meldung,
            'schritt' => $this->schritt,
            'bestaetigungspflichtig' => $this->bestaetigungspflichtig,
        ];
    }
}
