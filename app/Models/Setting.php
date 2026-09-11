<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Einstellungen als Schlüssel-Wert-Speicher (Datenvertrag Abschnitt 2.11).
 *
 * Wird ausschließlich über App\Domain\Settings\SettingsRepository gelesen und
 * geschrieben, damit Verschlüsselung und JSON-Kodierung an einer Stelle
 * bleiben.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'key',
        'value',
        'is_encrypted',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'updated_at' => 'datetime',
        ];
    }
}
