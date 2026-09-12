<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PortalStatus;
use Database\Factories\ListingPortalPublicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Portalveröffentlichung (Datenvertrag Abschnitt 2.9, 4.3, Masterprompt-Abgleich B.6).
 *
 * Jeder Statuswechsel läuft über App\Flowfact\Sync\PortalStatusTransition, die
 * den Nachweis in listing_portal_status_logs schreibt. release_id ist die
 * Freigabeversion, mit der die Veröffentlichung zuletzt angefordert wurde.
 *
 * @property int $listing_id
 * @property string $portal_id
 * @property string $portal_name
 * @property PortalStatus $status
 * @property int|null $release_id
 * @property string|null $letzter_fehler
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

    /**
     * @return BelongsTo<ListingRelease, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(ListingRelease::class, 'release_id');
    }

    /**
     * @return HasMany<ListingPortalStatusLog, $this>
     */
    public function statusLogs(): HasMany
    {
        return $this->hasMany(ListingPortalStatusLog::class, 'publication_id')->orderBy('id');
    }
}
