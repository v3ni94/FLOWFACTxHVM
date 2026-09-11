<?php

namespace Database\Factories;

use App\Domain\Security\Base32;
use App\Domain\Security\TimeBasedOneTimePassword;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Mitarbeiter,
            'is_active' => true,
            'phone' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Aktiviert 2FA mit dem RFC-Testschlüssel, damit Tests einen gültigen Code
     * berechnen können, ohne das Geheimnis selbst zu kennen.
     */
    public function withTwoFactor(?string $secret = null): static
    {
        $secret ??= Base32::encode('12345678901234567890');

        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(
                fn (string $code): string => Hash::make($code),
                ['ABCDE-FGHJK']
            ),
        ]);
    }

    /**
     * Liefert den aktuell gültigen TOTP-Code zum in withTwoFactor() gesetzten Geheimnis.
     */
    public static function currentTotpCode(string $secret): string
    {
        return (new TimeBasedOneTimePassword)->currentCode($secret);
    }
}
