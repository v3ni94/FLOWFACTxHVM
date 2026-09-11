<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProvisionTyp;
use Database\Factories\ListingPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preise, 1:1 zu Listing (Datenvertrag Abschnitt 2.3).
 */
class ListingPrice extends Model
{
    /** @use HasFactory<ListingPriceFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kaltmiete_cent' => 'integer',
            'nebenkosten_cent' => 'integer',
            'heizkosten_cent' => 'integer',
            'heizkosten_in_nebenkosten_enthalten' => 'boolean',
            'warmmiete_cent' => 'integer',
            'kaution_cent' => 'integer',
            'stellplatz_miete_cent' => 'integer',
            'kaufpreis_cent' => 'integer',
            'hausgeld_cent' => 'integer',
            'stellplatz_kaufpreis_cent' => 'integer',
            'mieteinnahmen_ist_cent' => 'integer',
            'provision_typ' => ProvisionTyp::class,
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
