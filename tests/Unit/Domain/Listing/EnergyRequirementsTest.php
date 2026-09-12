<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\Befund;
use App\Domain\Listing\EnergyRequirements;
use App\Enums\EnergieausweisStatus;
use App\Enums\PruefArt;
use App\Enums\PruefEbene;
use App\Models\Listing;
use App\Models\ListingEnergy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Energieangaben nach § 87 GEG (Masterprompt-Abgleich B.5, Einschätzung).
 */
final class EnergyRequirementsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function felder(array $befunde): array
    {
        return array_map(fn (Befund $befund): string => $befund->feld, $befunde);
    }

    /**
     * @param  array<string, mixed>  $energie
     */
    private function mitEnergie(Listing $listing, array $energie): Listing
    {
        ListingEnergy::factory()->create(array_merge(['listing_id' => $listing->id], $energie));

        return $listing->fresh(['energy']);
    }

    public function test_stand_und_quelle_sind_hinterlegt(): void
    {
        self::assertSame('12.09.2026', EnergyRequirements::STAND);
        self::assertStringContainsString('§ 87 GEG', EnergyRequirements::QUELLE);
        self::assertStringContainsString('vor Livegang zu bestätigen', EnergyRequirements::QUELLE);
        $regeln = EnergyRequirements::regeln();
        self::assertNotEmpty($regeln);
        self::assertStringContainsString(EnergyRequirements::STAND, (string) end($regeln));
    }

    public function test_grundstueck_und_stellplatz_haben_keine_anforderungen(): void
    {
        foreach (['grundstueck', 'stellplatz'] as $state) {
            $listing = Listing::factory()->{$state}()->create();

            self::assertSame([], (new EnergyRequirements)->pruefe($listing->fresh(['energy'])), $state.' ohne Energieausweis');

            $listing = $this->mitEnergie($listing, ['status' => EnergieausweisStatus::NochNichtVorhanden]);

            self::assertSame([], (new EnergyRequirements)->pruefe($listing), $state.' auch mit Status noch nicht vorhanden');
        }
    }

    public function test_fehlender_status_ist_ein_interner_blockierender_befund(): void
    {
        $listing = Listing::factory()->create();

        $befunde = (new EnergyRequirements)->pruefe($listing->fresh(['energy']));

        self::assertCount(1, $befunde);
        self::assertSame('energie.status', $befunde[0]->feld);
        self::assertSame(PruefArt::Blockierend, $befunde[0]->art);
        self::assertSame(PruefEbene::Intern, $befunde[0]->ebene);
        self::assertSame(5, $befunde[0]->schritt);
    }

    public function test_vorhanden_bei_wohnobjekt_verlangt_alle_pflichtangaben(): void
    {
        $listing = Listing::factory()->create(['energietraeger' => null, 'baujahr' => null]);
        $listing = $this->mitEnergie($listing, [
            'status' => EnergieausweisStatus::Vorhanden,
            'ausweistyp' => null,
            'kennwert_kwh' => null,
            'effizienzklasse' => null,
            'baujahr_anlage' => null,
        ]);

        $befunde = (new EnergyRequirements)->pruefe($listing);

        self::assertSame(
            ['energie.ausweistyp', 'energie.kennwert_kwh', 'energietraeger', 'baujahr', 'energie.effizienzklasse'],
            $this->felder($befunde),
        );

        foreach ($befunde as $befund) {
            self::assertSame(PruefEbene::Gesetzlich, $befund->ebene);
            self::assertSame(PruefArt::Blockierend, $befund->art);
        }
    }

    public function test_vorhanden_bei_wohnobjekt_akzeptiert_baujahr_der_anlage_als_ersatz(): void
    {
        $listing = Listing::factory()->create(['baujahr' => null]);
        $listing = $this->mitEnergie($listing, ['status' => EnergieausweisStatus::Vorhanden, 'baujahr_anlage' => 2005]);

        self::assertSame([], (new EnergyRequirements)->pruefe($listing));
    }

    public function test_aelterer_status_liegt_vor_wird_wie_vorhanden_geprueft(): void
    {
        $listing = Listing::factory()->create();
        $listing = $this->mitEnergie($listing, ['status' => EnergieausweisStatus::LiegtVor, 'kennwert_kwh' => null]);

        self::assertSame(['energie.kennwert_kwh'], $this->felder((new EnergyRequirements)->pruefe($listing)));
    }

    public function test_gewerbe_verlangt_weder_baujahr_noch_klasse(): void
    {
        $listing = Listing::factory()->gewerbe()->create(['baujahr' => null]);
        $listing = $this->mitEnergie($listing, [
            'status' => EnergieausweisStatus::Vorhanden,
            'effizienzklasse' => null,
            'baujahr_anlage' => null,
        ]);

        self::assertSame([], (new EnergyRequirements)->pruefe($listing));

        $listing->energy->update(['ausweistyp' => null]);
        $listing->update(['energietraeger' => null]);

        self::assertSame(['energie.ausweistyp', 'energietraeger'], $this->felder((new EnergyRequirements)->pruefe($listing->fresh(['energy']))));
    }

    public function test_noch_nicht_vorhanden_und_beauftragt_blockieren_gesetzlich(): void
    {
        foreach ([EnergieausweisStatus::NochNichtVorhanden, EnergieausweisStatus::Beauftragt, EnergieausweisStatus::InErstellung] as $status) {
            $listing = Listing::factory()->create();
            $listing = $this->mitEnergie($listing, ['status' => $status]);

            $befunde = (new EnergyRequirements)->pruefe($listing);

            self::assertCount(1, $befunde, $status->value);
            self::assertSame('energie.status', $befunde[0]->feld);
            self::assertSame(PruefEbene::Gesetzlich, $befunde[0]->ebene);
            self::assertSame(PruefArt::Blockierend, $befunde[0]->art);
            self::assertSame(EnergyRequirements::MELDUNG_NOCH_NICHT_VORHANDEN, $befunde[0]->meldung);
            self::assertStringContainsString('wird nachgereicht', $befunde[0]->meldung);
        }
    }

    public function test_ausnahme_blockiert_bis_zur_bestaetigung_mit_begruendung(): void
    {
        $listing = Listing::factory()->create();
        $listing = $this->mitEnergie($listing, ['status' => EnergieausweisStatus::AusnahmeZuPruefen]);

        $befunde = (new EnergyRequirements)->pruefe($listing);
        self::assertSame(['energie.ausnahme'], $this->felder($befunde));
        self::assertSame(PruefEbene::Gesetzlich, $befunde[0]->ebene);

        // Nur der Zeitpunkt ohne Begründung reicht nicht.
        $listing->energy->update(['ausnahme_bestaetigt_at' => now(), 'ausnahme_begruendung' => '  ']);
        self::assertSame(['energie.ausnahme'], $this->felder((new EnergyRequirements)->pruefe($listing->fresh(['energy']))));

        $admin = User::factory()->admin()->create();
        $listing->energy->update([
            'ausnahme_begruendung' => 'Baudenkmal',
            'ausnahme_bestaetigt_von_user_id' => $admin->id,
            'ausnahme_bestaetigt_at' => now(),
        ]);

        self::assertSame([], (new EnergyRequirements)->pruefe($listing->fresh(['energy'])));
        self::assertTrue($listing->energy->fresh()->ausnahmeBestaetigtVon->is($admin));
    }

    public function test_aelterer_status_nicht_erforderlich_gilt_als_zu_pruefende_ausnahme(): void
    {
        $listing = Listing::factory()->create();
        $listing = $this->mitEnergie($listing, ['status' => EnergieausweisStatus::NichtErforderlich]);

        self::assertSame(['energie.ausnahme'], $this->felder((new EnergyRequirements)->pruefe($listing)));
    }
}
