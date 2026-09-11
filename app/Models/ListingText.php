<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use Database\Factories\ListingTextFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historie der KI- und Handtexte (Datenvertrag Abschnitt 2.7).
 */
class ListingText extends Model
{
    /** @use HasFactory<ListingTextFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feld' => TextFeld::class,
            'quelle' => TextQuelle::class,
            'uebernommen' => 'boolean',
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
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
