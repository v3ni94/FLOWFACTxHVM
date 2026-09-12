<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use Database\Factories\ListingEnergyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Energieausweis, 1:1 zu Listing (Datenvertrag Abschnitt 2.4, Masterprompt-Abgleich B.2, B.5).
 *
 * @property EnergieausweisStatus|null $status
 * @property Ausweistyp|null $ausweistyp
 * @property string|null $kennwert_kwh
 * @property string|null $kennwert_strom_kwh
 * @property Effizienzklasse|null $effizienzklasse
 * @property int|null $baujahr_anlage
 * @property string|null $ausnahme_begruendung
 * @property Carbon|null $ausnahme_bestaetigt_at
 */
class ListingEnergy extends Model
{
    /** @use HasFactory<ListingEnergyFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EnergieausweisStatus::class,
            'ausweistyp' => Ausweistyp::class,
            'ausstellungsdatum' => 'date',
            'kennwert_kwh' => 'decimal:1',
            'kennwert_strom_kwh' => 'decimal:1',
            'effizienzklasse' => Effizienzklasse::class,
            'baujahr_anlage' => 'integer',
            'gueltig_bis' => 'date',
            'enthaelt_warmwasser' => 'boolean',
            'ausnahme_bestaetigt_at' => 'datetime',
        ];
    }

    /**
     * Maßgeblicher Status, ältere Werte auf die neuen abgebildet.
     */
    public function statusNormalisiert(): ?EnergieausweisStatus
    {
        return $this->status?->normalisiert();
    }

    /**
     * Ob die Ausnahme von der Ausweispflicht bestätigt ist: Zeitpunkt und
     * Begründung liegen vor (Masterprompt-Abgleich B.5).
     */
    public function ausnahmeBestaetigt(): bool
    {
        return $this->ausnahme_bestaetigt_at !== null && trim((string) $this->ausnahme_begruendung) !== '';
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ausnahmeBestaetigtVon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ausnahme_bestaetigt_von_user_id');
    }
}
