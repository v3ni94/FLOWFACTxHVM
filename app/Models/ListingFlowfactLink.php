<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncStatus;
use Database\Factories\ListingFlowfactLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FLOWFACT-Verknüpfung, 1:1 zu Listing (Datenvertrag Abschnitt 2.8).
 */
class ListingFlowfactLink extends Model
{
    /** @use HasFactory<ListingFlowfactLinkFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sync_status' => SyncStatus::class,
            'letzte_uebertragung_at' => 'datetime',
            'sperre_bis' => 'datetime',
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
