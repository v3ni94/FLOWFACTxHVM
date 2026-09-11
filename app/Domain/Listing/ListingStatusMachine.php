<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Models\Listing;

/**
 * Zustandsautomat des Bearbeitungsstatus (Datenvertrag Abschnitt 4.1, ADR-004).
 *
 * Ergänzung nach Prüfbericht 2026-09-11, Befund 1: veroeffentlicht -> bereit
 * ist zulässig, solange keine Portalveröffentlichung mehr offen ist
 * (angefordert oder aktiv). Damit findet ein Objekt, dessen Anforderungen
 * sämtlich gescheitert oder zurückgezogen sind, wieder aus "veröffentlicht"
 * heraus.
 */
final class ListingStatusMachine
{
    /**
     * Erlaubte Übergänge je Ausgangsstatus.
     *
     * @var array<string, list<string>>
     */
    private const array UEBERGAENGE = [
        'entwurf' => ['bereit', 'archiviert'],
        'bereit' => ['entwurf', 'veroeffentlicht', 'archiviert'],
        'veroeffentlicht' => ['zurueckgezogen', 'bereit'],
        'zurueckgezogen' => ['bereit', 'archiviert'],
        'archiviert' => [],
    ];

    public function __construct(
        private readonly CompletenessCheck $completenessCheck = new CompletenessCheck,
    ) {}

    public function canTransition(ListingStatus $from, ListingStatus $to): bool
    {
        return in_array($to->value, self::UEBERGAENGE[$from->value], true);
    }

    /**
     * @throws IllegalStatusTransitionException
     */
    public function transition(Listing $listing, ListingStatus $to): void
    {
        $from = $listing->status;

        if (! $this->canTransition($from, $to)) {
            throw new IllegalStatusTransitionException(sprintf(
                'Der Statuswechsel von "%s" nach "%s" ist nicht zulässig.',
                $from->label(),
                $to->label(),
            ));
        }

        if ($from === ListingStatus::Entwurf && $to === ListingStatus::Bereit) {
            $ergebnis = $this->completenessCheck->check($listing);

            if (! $ergebnis->istVollstaendig()) {
                throw new IllegalStatusTransitionException(
                    'Das Objekt ist nicht vollständig und kann daher nicht auf "bereit" gesetzt werden: '
                    .implode(', ', $ergebnis->fehlend).'.'
                );
            }
        }

        if ($from === ListingStatus::Veroeffentlicht && $to === ListingStatus::Bereit && $this->hatOffenePublikation($listing)) {
            throw new IllegalStatusTransitionException(
                'Der Statuswechsel von "Veröffentlicht" nach "Bereit" ist nicht zulässig, solange eine Portalveröffentlichung angefordert oder aktiv ist.'
            );
        }

        $listing->status = $to;
        $listing->save();
    }

    private function hatOffenePublikation(Listing $listing): bool
    {
        return $listing->portalPublications()
            ->whereIn('status', [PortalStatus::Angefordert->value, PortalStatus::Aktiv->value])
            ->exists();
    }
}
