<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\ListingStatus;
use App\Models\Listing;

/**
 * Zustandsautomat des Bearbeitungsstatus (Datenvertrag Abschnitt 4.1, ADR-004).
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
        'veroeffentlicht' => ['zurueckgezogen'],
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

        $listing->status = $to;
        $listing->save();
    }
}
