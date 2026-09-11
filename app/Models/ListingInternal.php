<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ListingInternalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Interne Daten, 1:1 zu Listing, NIE Teil der Übertragung
 * (Datenvertrag Abschnitt 2.5, ADR-003).
 */
class ListingInternal extends Model
{
    /** @use HasFactory<ListingInternalFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
