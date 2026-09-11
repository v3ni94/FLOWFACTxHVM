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

/**
 * Energieausweis, 1:1 zu Listing (Datenvertrag Abschnitt 2.4).
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
            'kennwert_kwh' => 'decimal:1',
            'effizienzklasse' => Effizienzklasse::class,
            'baujahr_anlage' => 'integer',
            'gueltig_bis' => 'date',
            'enthaelt_warmwasser' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
