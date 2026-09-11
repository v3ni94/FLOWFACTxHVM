<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Models\Listing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vormerkung: lokal gelöschtes Medium, dessen FLOWFACT-Item noch zu löschen
 * ist (docs/connector.md Abschnitt 3, Schritt 7). Wird vom
 * ListingMediaObserver geschrieben und im MediaSyncService abgearbeitet.
 *
 * @property int $id
 * @property int $listing_id
 * @property string $flowfact_multimedia_id
 */
class ListingMediaDeletion extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'listing_media_deletions';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
}
