<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MediaTyp;
use Database\Factories\ListingMediaFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Medien eines Objekts (Datenvertrag Abschnitt 2.6, Masterprompt-Abgleich B.2).
 *
 * @property int $listing_id
 * @property MediaTyp $typ
 * @property int $sortierung
 * @property string|null $titel
 * @property bool $im_inserat
 * @property bool $freigegeben
 * @property int $rotation
 * @property string $pruefsumme_sha256
 */
class ListingMedia extends Model
{
    /** @use HasFactory<ListingMediaFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'typ' => MediaTyp::class,
            'groesse_bytes' => 'integer',
            'breite' => 'integer',
            'hoehe' => 'integer',
            'sortierung' => 'integer',
            'im_inserat' => 'boolean',
            'freigegeben' => 'boolean',
            'rotation' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Freigabe je Medium (Masterprompt-Abgleich B.2): Bilder und Grundrisse
        // sind beim Anlegen freigegeben, Dokumente und Energieausweise bleiben
        // intern, bis sie ausdrücklich freigegeben werden.
        static::creating(function (ListingMedia $medium): void {
            if ($medium->getAttribute('freigegeben') === null) {
                $typ = $medium->typ instanceof MediaTyp ? $medium->typ : MediaTyp::tryFrom((string) $medium->typ);
                $medium->freigegeben = $typ?->standardFreigegeben() ?? false;
            }

            if ($medium->getAttribute('rotation') === null) {
                $medium->rotation = 0;
            }
        });
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @param  Builder<ListingMedia>  $query
     * @return Builder<ListingMedia>
     */
    public function scopeBilder(Builder $query): Builder
    {
        return $query->where('typ', MediaTyp::Bild->value);
    }

    /**
     * @param  Builder<ListingMedia>  $query
     * @return Builder<ListingMedia>
     */
    public function scopeImInserat(Builder $query): Builder
    {
        return $query->where('im_inserat', true);
    }

    /**
     * @param  Builder<ListingMedia>  $query
     * @return Builder<ListingMedia>
     */
    public function scopeFreigegeben(Builder $query): Builder
    {
        return $query->where('freigegeben', true);
    }

    /**
     * Ob das Medium in eine Freigabeversion und in die Übertragung gehört:
     * im Inserat und freigegeben.
     */
    public function istVeroeffentlichbar(): bool
    {
        return $this->im_inserat && $this->freigegeben;
    }

    /**
     * Ob dieses Medium das Titelbild ist (sortierung 0, Datenvertrag 2.6).
     */
    public function istTitelbild(): bool
    {
        return $this->typ === MediaTyp::Bild && $this->sortierung === 0;
    }
}
