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
 * Medien eines Objekts (Datenvertrag Abschnitt 2.6).
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
     * Ob dieses Medium das Titelbild ist (sortierung 0, Datenvertrag 2.6).
     */
    public function istTitelbild(): bool
    {
        return $this->typ === MediaTyp::Bild && $this->sortierung === 0;
    }
}
