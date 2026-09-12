<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PortalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nachweis eines Portalstatuswechsels (Masterprompt-Abgleich B.6): Quelle,
 * Zeitpunkt und Freigabeversion je Wechsel. Einträge sind unveränderlich.
 *
 * @property int $listing_id
 * @property int|null $publication_id
 * @property string $portal_id
 * @property PortalStatus|null $von_status
 * @property PortalStatus $nach_status
 * @property string $nachweis_quelle
 */
class ListingPortalStatusLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'von_status' => PortalStatus::class,
            'nach_status' => PortalStatus::class,
            'nachweis_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<ListingPortalPublication, $this>
     */
    public function publication(): BelongsTo
    {
        return $this->belongsTo(ListingPortalPublication::class, 'publication_id');
    }

    /**
     * @return BelongsTo<ListingRelease, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(ListingRelease::class, 'release_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
