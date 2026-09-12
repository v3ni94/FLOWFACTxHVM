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
 *
 * release_id: zuletzt erfolgreich übertragene Freigabeversion (B.6).
 * flowfact_last_modified: _metadata.lastModifiedTimestamp der FLOWFACT-Entität
 * nach dem letzten Anlegen oder Aktualisieren (Konflikterkennung, Masterprompt
 * Abschnitt 23), als Zeichenkette wie geliefert.
 *
 * @property int $listing_id
 * @property string|null $flowfact_entity_id
 * @property string|null $flowfact_schema
 * @property SyncStatus $sync_status
 * @property string|null $letzter_fehler
 * @property string|null $uebertragener_inhalt_hash
 * @property int|null $release_id
 * @property string|null $flowfact_last_modified
 * @property string|null $sperre_token
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

    /**
     * Zuletzt erfolgreich übertragene Freigabeversion.
     *
     * @return BelongsTo<ListingRelease, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(ListingRelease::class, 'release_id');
    }
}
