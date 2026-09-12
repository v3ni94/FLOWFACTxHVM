<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserInvitation>
 */
class UserInvitationFactory extends Factory
{
    protected $model = UserInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => UserRole::Mitarbeiter,
            'darf_veroeffentlichen' => false,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => now()->addHours(UserInvitation::GUELTIGKEIT_STUNDEN),
            'eingeladen_von_user_id' => User::factory()->admin(),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    public function abgelaufen(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subHour(),
        ]);
    }

    public function angenommen(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
        ]);
    }

    public function widerrufen(): static
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => now(),
        ]);
    }
}
