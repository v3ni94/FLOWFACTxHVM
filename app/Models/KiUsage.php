<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\KiUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Verbrauchseintrag eines Aufrufs der KI-Textgenerierung (ADR-009). Ein
 * Eintrag je generate()-Aufruf, auch bei Fehlschlag.
 */
class KiUsage extends Model
{
    /** @use HasFactory<KiUsageFactory> */
    use HasFactory;

    /**
     * Kein updated_at: der Eintrag ist unveränderlich, geschrieben wird
     * einmal je Aufruf.
     */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'dauer_ms' => 'integer',
            'erfolgreich' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
