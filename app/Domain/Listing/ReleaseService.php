<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\ReleaseAktion;
use App\Models\Listing;
use App\Models\ListingChange;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Freigabeversionen (Masterprompt-Abgleich B.6). Jede Freigabe friert den
 * veröffentlichbaren Inhalt als ListingSnapshot ein; Übertragung und
 * Veröffentlichung arbeiten ausschließlich mit der jüngsten Version.
 *
 * Die Vollständigkeitsprüfung (CompletenessCheck::blockiert) führt der
 * Aufrufer vor der Freigabe aus; dieser Dienst erzeugt nur die Version.
 */
final class ReleaseService
{
    public function __construct(
        private readonly ListingContentHasher $hasher = new ListingContentHasher,
    ) {}

    /**
     * @param  list<string>  $portalIds
     */
    public function freigeben(Listing $listing, User $user, ReleaseAktion $aktion, array $portalIds): ListingRelease
    {
        return DB::transaction(function () use ($listing, $user, $aktion, $portalIds): ListingRelease {
            $listing->load(['price', 'energy', 'media']);

            $snapshot = ListingSnapshot::fromListing($listing);
            $vorherige = $this->latest($listing);
            $version = ($vorherige?->version ?? 0) + 1;

            $release = $listing->releases()->create([
                'version' => $version,
                'payload_json' => $snapshot->payload(),
                'medien_json' => $snapshot->medien,
                'portale_json' => array_values(array_map('strval', $portalIds)),
                'inhalt_hash' => $this->hasher->hashSnapshot($snapshot),
                'freigegeben_von_user_id' => $user->getKey(),
                'freigegeben_at' => now(),
                'aktion' => $aktion,
            ]);

            ListingChange::query()->create([
                'listing_id' => $listing->getKey(),
                'user_id' => $user->getKey(),
                'feld' => 'listing_releases.version',
                'alt' => $vorherige !== null ? (string) $vorherige->version : null,
                'neu' => $version.' ('.$aktion->value.')',
                'created_at' => now(),
            ]);

            $listing->unsetRelation('latestRelease');

            return $release;
        });
    }

    public function latest(Listing $listing): ?ListingRelease
    {
        return $listing->releases()->orderByDesc('version')->first();
    }

    /**
     * Ob die Version noch dem Live-Stand entspricht (Hash gleich).
     */
    public function istAktuell(ListingRelease $release): bool
    {
        $listing = $release->listing()->with(['price', 'energy', 'media'])->first();

        if ($listing === null) {
            return false;
        }

        return $this->hasher->hash($listing) === $release->inhalt_hash;
    }
}
