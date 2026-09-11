<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TransferRichtung;
use Database\Factories\TransferLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Übertragungsprotokoll (Datenvertrag Abschnitt 2.10, ADR-014).
 */
class TransferLog extends Model
{
    /** @use HasFactory<TransferLogFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'richtung' => TransferRichtung::class,
            'http_status' => 'integer',
            'erfolgreich' => 'boolean',
            'details' => 'array',
            'dauer_ms' => 'integer',
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
}
