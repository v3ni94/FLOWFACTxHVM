<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PortalStatus;
use Database\Factories\ListingPortalPublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Portalveröffentlichung (Datenvertrag Abschnitt 2.9, 4.3).
 */
class ListingPortalPublication extends Model
{
    /** @use HasFactory<ListingPortalPublicationFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PortalStatus::class,
            'angefordert_at' => 'datetime',
            'bestaetigt_at' => 'datetime',
            'zurueckgezogen_at' => 'datetime',
            'letzte_pruefung_at' => 'datetime',
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
