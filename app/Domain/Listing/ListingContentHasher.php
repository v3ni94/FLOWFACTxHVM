<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Models\Listing;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Bildet einen stabilen Hash über die veröffentlichbaren Daten eines Objekts
 * (Datenvertrag Abschnitt 2.8, ADR-003, ADR-005).
 *
 * Der Hash ändert sich bei jeder inhaltlichen Änderung der Positivliste
 * (Objekt-, Preis- und Energiedaten sowie die im Inserat enthaltenen Medien)
 * und bleibt bei Änderungen an listing_internals oder am Bearbeitungsstatus
 * unverändert. Interne Felder werden hier technisch nie gelesen, ausschließlich
 * die Konstanten aus PublishableFields.
 */
final class ListingContentHasher
{
    public function hash(Listing $listing): string
    {
        $daten = [
            'listing' => $this->auszug($listing, PublishableFields::LISTING),
            'price' => $listing->price !== null ? $this->auszug($listing->price, PublishableFields::PRICE) : null,
            'energy' => $listing->energy !== null ? $this->auszug($listing->energy, PublishableFields::ENERGY) : null,
            'media' => $listing->media
                ->where('im_inserat', true)
                ->sortBy('sortierung')
                ->values()
                ->map(fn ($medium): array => [
                    'pruefsumme_sha256' => $medium->pruefsumme_sha256,
                    'sortierung' => $medium->sortierung,
                    'titel' => $medium->titel,
                    'typ' => $this->normalisiert($medium->typ),
                ])
                ->all(),
        ];

        return hash('sha256', $this->kanonisiertesJson($daten));
    }

    /**
     * @param  list<string>  $felder
     * @return array<string, mixed>
     */
    private function auszug(Model $model, array $felder): array
    {
        $auszug = [];

        foreach ($felder as $feld) {
            $auszug[$feld] = $this->normalisiert($model->getAttribute($feld));
        }

        ksort($auszug);

        return $auszug;
    }

    private function normalisiert(mixed $wert): mixed
    {
        if ($wert instanceof BackedEnum) {
            return $wert->value;
        }

        if ($wert instanceof DateTimeInterface) {
            return $wert->format(DateTimeInterface::ATOM);
        }

        if (is_array($wert)) {
            ksort($wert);

            return array_map($this->normalisiert(...), $wert);
        }

        return $wert;
    }

    /**
     * @param  array<string, mixed>  $daten
     */
    private function kanonisiertesJson(array $daten): string
    {
        return json_encode($daten, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
