<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\IllegalStatusTransitionException;
use App\Domain\Listing\ListingStatusMachine;
use App\Enums\ListingStatus;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Übergänge des Bearbeitungsstatus (Datenvertrag Abschnitt 4.1).
 */
final class ListingStatusMachineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{ListingStatus, ListingStatus, bool}>
     */
    public static function uebergaenge(): array
    {
        return [
            'entwurf nach bereit erlaubt' => [ListingStatus::Entwurf, ListingStatus::Bereit, true],
            'entwurf nach archiviert erlaubt' => [ListingStatus::Entwurf, ListingStatus::Archiviert, true],
            'entwurf nach veroeffentlicht verboten' => [ListingStatus::Entwurf, ListingStatus::Veroeffentlicht, false],
            'entwurf nach zurueckgezogen verboten' => [ListingStatus::Entwurf, ListingStatus::Zurueckgezogen, false],
            'bereit nach entwurf erlaubt' => [ListingStatus::Bereit, ListingStatus::Entwurf, true],
            'bereit nach veroeffentlicht erlaubt' => [ListingStatus::Bereit, ListingStatus::Veroeffentlicht, true],
            'bereit nach archiviert erlaubt' => [ListingStatus::Bereit, ListingStatus::Archiviert, true],
            'bereit nach zurueckgezogen verboten' => [ListingStatus::Bereit, ListingStatus::Zurueckgezogen, false],
            'veroeffentlicht nach zurueckgezogen erlaubt' => [ListingStatus::Veroeffentlicht, ListingStatus::Zurueckgezogen, true],
            'veroeffentlicht nach archiviert verboten' => [ListingStatus::Veroeffentlicht, ListingStatus::Archiviert, false],
            'veroeffentlicht nach entwurf verboten' => [ListingStatus::Veroeffentlicht, ListingStatus::Entwurf, false],
            'veroeffentlicht nach bereit verboten' => [ListingStatus::Veroeffentlicht, ListingStatus::Bereit, false],
            'zurueckgezogen nach bereit erlaubt' => [ListingStatus::Zurueckgezogen, ListingStatus::Bereit, true],
            'zurueckgezogen nach archiviert erlaubt' => [ListingStatus::Zurueckgezogen, ListingStatus::Archiviert, true],
            'zurueckgezogen nach veroeffentlicht verboten' => [ListingStatus::Zurueckgezogen, ListingStatus::Veroeffentlicht, false],
            'zurueckgezogen nach entwurf verboten' => [ListingStatus::Zurueckgezogen, ListingStatus::Entwurf, false],
            'archiviert nach entwurf verboten' => [ListingStatus::Archiviert, ListingStatus::Entwurf, false],
            'archiviert nach bereit verboten' => [ListingStatus::Archiviert, ListingStatus::Bereit, false],
            'archiviert nach veroeffentlicht verboten' => [ListingStatus::Archiviert, ListingStatus::Veroeffentlicht, false],
            'archiviert nach zurueckgezogen verboten' => [ListingStatus::Archiviert, ListingStatus::Zurueckgezogen, false],
        ];
    }

    #[DataProvider('uebergaenge')]
    public function test_can_transition_folgt_der_tabelle_aus_dem_datenvertrag(
        ListingStatus $von,
        ListingStatus $nach,
        bool $erlaubt,
    ): void {
        $machine = app(ListingStatusMachine::class);

        $this->assertSame($erlaubt, $machine->canTransition($von, $nach));
    }

    public function test_entwurf_nach_bereit_wird_bei_unvollstaendigem_objekt_blockiert(): void
    {
        $listing = Listing::factory()->create();

        $this->expectException(IllegalStatusTransitionException::class);

        app(ListingStatusMachine::class)->transition($listing, ListingStatus::Bereit);
    }

    public function test_entwurf_nach_bereit_gelingt_bei_vollstaendigem_objekt(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->load(['price', 'energy', 'media']);

        app(ListingStatusMachine::class)->transition($listing, ListingStatus::Bereit);

        $this->assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_ein_verbotener_uebergang_wirft_und_speichert_nicht(): void
    {
        $listing = Listing::factory()->create(['status' => ListingStatus::Veroeffentlicht]);

        try {
            app(ListingStatusMachine::class)->transition($listing, ListingStatus::Bereit);
            $this->fail('Es wurde keine Ausnahme geworfen.');
        } catch (IllegalStatusTransitionException) {
            // erwartet
        }

        $this->assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
    }
}
