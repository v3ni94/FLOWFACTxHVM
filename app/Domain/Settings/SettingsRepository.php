<?php

declare(strict_types=1);

namespace App\Domain\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Liest und schreibt die Tabelle settings (Datenvertrag Abschnitt 2.11).
 *
 * Werte werden als JSON-Text gespeichert, damit auch Zahlen, Booleans und
 * Arrays verlustfrei über den textbasierten value-Spalte transportiert
 * werden. Geheimnisse (z. B. flowfact.api_token) werden zusätzlich mit
 * Crypt verschlüsselt und nie im Klartext in der Datenbank abgelegt.
 */
final class SettingsRepository
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = Setting::query()->find($key);

        if ($setting === null || $setting->value === null) {
            return $default;
        }

        if ($setting->is_encrypted) {
            return json_decode(Crypt::decryptString($setting->value), true);
        }

        return json_decode($setting->value, true);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'updated_at' => now(),
            ],
        );
    }

    public function setSecret(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)),
                'is_encrypted' => true,
                'updated_at' => now(),
            ],
        );
    }

    public function getSecret(string $key): ?string
    {
        $setting = Setting::query()->find($key);

        if ($setting === null || $setting->value === null || ! $setting->is_encrypted) {
            return null;
        }

        return json_decode(Crypt::decryptString($setting->value), true);
    }

    public function hasSecret(string $key): bool
    {
        $setting = Setting::query()->find($key);

        return $setting !== null && $setting->value !== null && $setting->is_encrypted;
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
    }

    /**
     * @return array<string, mixed> Vollständiger Schlüssel => entschlüsselter/dekodierter Wert
     */
    public function all(string $prefix): array
    {
        $ergebnis = [];

        Setting::query()
            ->where('key', 'like', $prefix.'%')
            ->get()
            ->each(function (Setting $setting) use (&$ergebnis): void {
                if ($setting->value === null) {
                    $ergebnis[$setting->key] = null;

                    return;
                }

                $ergebnis[$setting->key] = $setting->is_encrypted
                    ? json_decode(Crypt::decryptString($setting->value), true)
                    : json_decode($setting->value, true);
            });

        return $ergebnis;
    }
}
