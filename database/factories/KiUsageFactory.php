<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\KiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KiUsage>
 */
class KiUsageFactory extends Factory
{
    protected $model = KiUsage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'listing_id' => null,
            'modell' => 'claude-opus-5',
            'input_tokens' => 500,
            'output_tokens' => 300,
            'dauer_ms' => 1200,
            'erfolgreich' => true,
            'fehler' => null,
            'created_at' => now(),
        ];
    }

    public function fehlgeschlagen(): static
    {
        return $this->state(fn (array $attributes): array => [
            'erfolgreich' => false,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'fehler' => 'KI-Anbieter derzeit nicht erreichbar.',
        ]);
    }
}
