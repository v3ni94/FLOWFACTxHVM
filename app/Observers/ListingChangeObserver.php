<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Listing;
use App\Models\ListingChange;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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

    /**
     * Freitext-Spalten je Tabelle, deren aufeinanderfolgende Änderungen
     * innerhalb von zehn Minuten durch denselben Benutzer zu einer Zeile
     * zusammengefasst werden (Prüfbericht 2026-09-12, Befund 6): Autosave in
     * Schritt 7 und 8 speichert bei jeder Eingabepause, ohne Bündelung
     * entstünden Dutzende Zeilen mit vollem Alt- und Neuwert je Beschreibung.
     *
     * @var array<string, list<string>>
     */
    private const array TEXT_SPALTEN = [
        'listings' => [
            'titel',
            'interne_bezeichnung',
            'beschreibung_objekt',
            'beschreibung_ausstattung',
            'beschreibung_lage',
            'beschreibung_sonstiges',
        ],
    ];

    private const int BUENDELUNG_MINUTEN = 10;

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
        $textSpalten = self::TEXT_SPALTEN[$tabelle] ?? [];

        foreach (array_keys($model->getChanges()) as $spalte) {
            if ($this->wirdUebersprungen($spalte)) {
                continue;
            }

            $feld = $tabelle.'.'.$spalte;
            $neu = $this->alsText($model->getAttributes()[$spalte] ?? null);

            if (in_array($spalte, $textSpalten, true)
                && $this->buendleMitVorheriger((int) $listingId, $feld, $userId, $zeitpunkt, $neu)) {
                continue;
            }

            $eintraege[] = [
                'listing_id' => (int) $listingId,
                'user_id' => $userId !== null ? (int) $userId : null,
                'feld' => $feld,
                'alt' => $this->alsText($model->getRawOriginal($spalte)),
                'neu' => $neu,
                'created_at' => $zeitpunkt,
            ];
        }

        if ($eintraege !== []) {
            ListingChange::query()->insert($eintraege);
        }
    }

    /**
     * Aktualisiert die letzte Zeile einer laufenden Bündelung (nur "neu" und
     * der Zeitpunkt), statt eine neue anzulegen. "alt" bleibt der Wert vor
     * der ersten Änderung der Serie. Gibt false zurück, wenn keine
     * bündelbare Zeile innerhalb der letzten zehn Minuten vom selben
     * Benutzer existiert, dann legt der Aufrufer eine neue Zeile an.
     */
    private function buendleMitVorheriger(int $listingId, string $feld, ?int $userId, Carbon $zeitpunkt, ?string $neu): bool
    {
        $letzte = ListingChange::query()
            ->where('listing_id', $listingId)
            ->where('feld', $feld)
            ->where('user_id', $userId)
            ->where('created_at', '>=', $zeitpunkt->clone()->subMinutes(self::BUENDELUNG_MINUTEN))
            ->orderByDesc('id')
            ->first();

        if ($letzte === null) {
            return false;
        }

        $letzte->forceFill(['neu' => $neu, 'created_at' => $zeitpunkt])->save();

        return true;
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
