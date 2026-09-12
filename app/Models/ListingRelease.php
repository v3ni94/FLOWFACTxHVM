<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Listing\ListingSnapshot;
use App\Enums\ReleaseAktion;
use Database\Factories\ListingReleaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Freigabeversion eines Objekts (Masterprompt-Abgleich B.6). Eine Version
 * ist unveränderlich: Übertragung und Veröffentlichung lesen ausschließlich
 * payload_json und medien_json, nie den Live-Stand.
 *
 * @property int $id
 * @property int $listing_id
 * @property int $version
 * @property array<string, mixed> $payload_json
 * @property list<array<string, mixed>> $medien_json
 * @property list<string> $portale_json
 * @property string $inhalt_hash
 * @property int|null $freigegeben_von_user_id
 * @property ReleaseAktion $aktion
 */
class ListingRelease extends Model
{
    /** @use HasFactory<ListingReleaseFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'payload_json' => 'array',
            'medien_json' => 'array',
            'portale_json' => 'array',
            'freigegeben_at' => 'datetime',
            'aktion' => ReleaseAktion::class,
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
     * @return BelongsTo<User, $this>
     */
    public function freigegebenVon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freigegeben_von_user_id');
    }

    /**
     * @return HasMany<ListingPortalStatusLog, $this>
     */
    public function portalStatusLogs(): HasMany
    {
        return $this->hasMany(ListingPortalStatusLog::class, 'release_id');
    }

    /**
     * Eingefrorene Momentaufnahme dieser Version.
     */
    public function snapshot(): ListingSnapshot
    {
        return ListingSnapshot::fromArray(array_merge(
            is_array($this->payload_json) ? $this->payload_json : [],
            ['medien' => is_array($this->medien_json) ? $this->medien_json : []],
        ));
    }
}
