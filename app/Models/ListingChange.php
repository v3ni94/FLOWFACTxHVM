<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Änderungseintrag je Feld (Masterprompt-Abgleich B.6), geschrieben über
 * App\Observers\ListingChangeObserver. feld ist "tabelle.spalte". Interne
 * Felder werden protokolliert, aber nie exportiert: diese Tabelle ist nicht
 * Teil der Positivliste und wird von keinem Mapper gelesen.
 *
 * @property int $listing_id
 * @property int|null $user_id
 * @property string $feld
 * @property string|null $alt
 * @property string|null $neu
 */
class ListingChange extends Model
{
    public const UPDATED_AT = null;

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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tabelle und Spalte getrennt, für die Anzeige der Historie.
     *
     * @return array{0: string, 1: string}
     */
    public function tabelleUndSpalte(): array
    {
        $teile = explode('.', $this->feld, 2);

        return [$teile[0], $teile[1] ?? ''];
    }
}
