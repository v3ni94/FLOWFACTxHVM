<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TransferRichtung;
use App\Models\Listing;
use App\Models\TransferLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferLog>
 */
class TransferLogFactory extends Factory
{
    protected $model = TransferLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'user_id' => User::factory(),
            'aktion' => 'entity.create',
            'richtung' => TransferRichtung::Ausgehend,
            'http_status' => 200,
            'erfolgreich' => true,
            'zusammenfassung' => 'Objekt erfolgreich angelegt.',
            'details' => null,
            'dauer_ms' => 120,
            'idempotenzschluessel' => null,
        ];
    }
}
