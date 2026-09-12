<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Models\Listing;
use App\Models\ListingMedia;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Momentaufnahme der veröffentlichbaren Daten eines Objekts
 * (Masterprompt-Abgleich B.6, ADR-003).
 *
 * Liest ausschließlich die Positivliste aus PublishableFields und die
 * Metadaten der im Inserat enthaltenen und freigegebenen Medien. Interne
 * Daten (listing_internals, interne_bezeichnung, Bestätigungen, Sync-
 * Buchhaltung) und Firmen- oder Kontaktdaten sind technisch nicht enthalten.
 * Ein Test mit Markerwerten belegt das.
 */
final readonly class ListingSnapshot
{
    /**
     * @param  array<string, mixed>  $listing
     * @param  array<string, mixed>|null  $price
     * @param  array<string, mixed>|null  $energy
     * @param  list<array<string, mixed>>  $medien
     */
    public function __construct(
        public array $listing,
        public ?array $price,
        public ?array $energy,
        public array $medien,
    ) {}

    public static function fromListing(Listing $listing): self
    {
        $medien = $listing->media
            ->filter(fn (ListingMedia $medium): bool => $medium->istVeroeffentlichbar())
            ->sortBy([['sortierung', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (ListingMedia $medium): array => array_merge(
                ['id' => $medium->getKey()],
                self::auszug($medium, PublishableFields::MEDIA),
            ))
            ->all();

        return new self(
            listing: self::auszug($listing, PublishableFields::LISTING),
            price: $listing->price !== null ? self::auszug($listing->price, PublishableFields::PRICE) : null,
            energy: $listing->energy !== null ? self::auszug($listing->energy, PublishableFields::ENERGY) : null,
            medien: $medien,
        );
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    public static function fromArray(array $daten): self
    {
        $listing = is_array($daten['listing'] ?? null) ? $daten['listing'] : [];
        $price = is_array($daten['price'] ?? null) ? $daten['price'] : null;
        $energy = is_array($daten['energy'] ?? null) ? $daten['energy'] : null;
        $medien = is_array($daten['medien'] ?? null) ? array_values($daten['medien']) : [];

        return new self($listing, $price, $energy, $medien);
    }

    /**
     * @return array{listing: array<string, mixed>, price: array<string, mixed>|null, energy: array<string, mixed>|null, medien: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'listing' => $this->listing,
            'price' => $this->price,
            'energy' => $this->energy,
            'medien' => $this->medien,
        ];
    }

    /**
     * Objekt-, Preis- und Energiedaten ohne Medien (listing_releases.payload_json).
     *
     * @return array{listing: array<string, mixed>, price: array<string, mixed>|null, energy: array<string, mixed>|null}
     */
    public function payload(): array
    {
        return [
            'listing' => $this->listing,
            'price' => $this->price,
            'energy' => $this->energy,
        ];
    }

    /**
     * Daten für den Inhalts-Hash: wie toArray(), aber ohne Medien-IDs, damit
     * ein erneuter Upload derselben Datei den Hash nicht ändert.
     *
     * @return array<string, mixed>
     */
    public function hashDaten(): array
    {
        return [
            'listing' => $this->listing,
            'price' => $this->price,
            'energy' => $this->energy,
            'medien' => array_map(function (array $medium): array {
                unset($medium['id']);
                ksort($medium);

                return $medium;
            }, $this->medien),
        ];
    }

    /**
     * @param  list<string>  $felder
     * @return array<string, mixed>
     */
    private static function auszug(Model $model, array $felder): array
    {
        $auszug = [];

        foreach ($felder as $feld) {
            $auszug[$feld] = self::normalisiert($model->getAttribute($feld));
        }

        ksort($auszug);

        return $auszug;
    }

    private static function normalisiert(mixed $wert): mixed
    {
        if ($wert instanceof BackedEnum) {
            return $wert->value;
        }

        if ($wert instanceof DateTimeInterface) {
            return $wert->format(DateTimeInterface::ATOM);
        }

        if (is_array($wert)) {
            ksort($wert);

            return array_map(self::normalisiert(...), $wert);
        }

        return $wert;
    }
}
