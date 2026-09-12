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
use Illuminate\Support\Carbon;

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
 * @property list<string>|null $gesendete_felder_json
 * @property int|null $freigegeben_von_user_id
 * @property Carbon $freigegeben_at
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
            'gesendete_felder_json' => 'array',
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
     * Veröffentlichungen, die mit dieser Version angefordert wurden.
     *
     * @return HasMany<ListingPortalPublication, $this>
     */
    public function portalPublications(): HasMany
    {
        return $this->hasMany(ListingPortalPublication::class, 'release_id');
    }

    /**
     * Portal-IDs dieser Version als Liste von Zeichenketten.
     *
     * @return list<string>
     */
    public function portalIds(): array
    {
        return array_values(array_map('strval', is_array($this->portale_json) ? $this->portale_json : []));
    }

    /**
     * FLOWFACT-Feldnamen, die mit dieser Version tatsächlich gesendet wurden
     * (Prüfbericht 2026-09-12, Befund 5); null, solange die Version nicht
     * übertragen wurde oder vor Einführung der Spalte übertragen wurde.
     *
     * @return list<string>|null
     */
    public function gesendeteFelder(): ?array
    {
        $felder = $this->gesendete_felder_json;

        if (! is_array($felder)) {
            return null;
        }

        return array_values(array_unique(array_map('strval', array_filter($felder, 'is_scalar'))));
    }

    /**
     * Eigenes Feld => FLOWFACT-Feld der letzten Übertragung (Befund 5).
     * Ältere Listen ohne Schlüssel liefern eine leere Zuordnung.
     *
     * @return array<string, string>
     */
    public function gesendeteZuordnung(): array
    {
        $felder = $this->gesendete_felder_json;

        if (! is_array($felder)) {
            return [];
        }

        $zuordnung = [];

        foreach ($felder as $quelle => $ziel) {
            if (is_string($quelle) && is_scalar($ziel)) {
                $zuordnung[$quelle] = (string) $ziel;
            }
        }

        return $zuordnung;
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
