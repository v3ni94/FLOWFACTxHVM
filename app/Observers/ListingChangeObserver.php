<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Listing;
use App\Models\ListingChange;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Schreibt je geändertem Attribut einen Eintrag in listing_changes
 * (Masterprompt-Abgleich B.6). Registriert für Listing, ListingPrice,
 * ListingEnergy, ListingInternal und ListingMedia (nur Metadaten).
 *
 * Zeitstempel und Sync-Buchhaltung werden übersprungen. Interne Felder
 * (listing_internals) werden protokolliert, aber nie exportiert: die Historie
 * ist nicht Teil der Positivliste.
 */
final class ListingChangeObserver
{
    /**
     * @var list<string>
     */
    private const array UEBERSPRUNGENE_SPALTEN = [
        'id',
        'created_at',
        'updated_at',
        'inhalt_geaendert_at',
        'uebertragener_inhalt_hash',
        'letzte_uebertragung_at',
        'letzter_fehler',
    ];

    /**
     * @var list<string>
     */
    private const array UEBERSPRUNGENE_PRAEFIXE = ['flowfact_', 'sperre_'];

    public function updated(Model $model): void
    {
        $listingId = $model instanceof Listing ? $model->getKey() : $model->getAttribute('listing_id');

        if ($listingId === null) {
            return;
        }

        $tabelle = $model->getTable();
        $userId = auth()->id();
        $zeitpunkt = now();
        $eintraege = [];

        foreach (array_keys($model->getChanges()) as $spalte) {
            if ($this->wirdUebersprungen($spalte)) {
                continue;
            }

            $eintraege[] = [
                'listing_id' => (int) $listingId,
                'user_id' => $userId !== null ? (int) $userId : null,
                'feld' => $tabelle.'.'.$spalte,
                'alt' => $this->alsText($model->getRawOriginal($spalte)),
                'neu' => $this->alsText($model->getAttributes()[$spalte] ?? null),
                'created_at' => $zeitpunkt,
            ];
        }

        if ($eintraege !== []) {
            ListingChange::query()->insert($eintraege);
        }
    }

    public static function wirdUebersprungen(string $spalte): bool
    {
        if (in_array($spalte, self::UEBERSPRUNGENE_SPALTEN, true)) {
            return true;
        }

        foreach (self::UEBERSPRUNGENE_PRAEFIXE as $praefix) {
            if (str_starts_with($spalte, $praefix)) {
                return true;
            }
        }

        return false;
    }

    private function alsText(mixed $wert): ?string
    {
        if ($wert === null) {
            return null;
        }

        if ($wert instanceof BackedEnum) {
            return (string) $wert->value;
        }

        if ($wert instanceof DateTimeInterface) {
            return $wert->format(DateTimeInterface::ATOM);
        }

        if (is_bool($wert)) {
            return $wert ? '1' : '0';
        }

        if (is_array($wert)) {
            return json_encode($wert, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_string($wert)) {
            $dekodiert = json_decode($wert, true);

            if (is_array($dekodiert)) {
                return json_encode($dekodiert, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        return (string) $wert;
    }
}
