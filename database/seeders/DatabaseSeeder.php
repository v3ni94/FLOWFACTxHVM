<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Erzeugt bewusst keine Benutzer. Benutzer entstehen ausschließlich über
 * das Kommando `flow:user:create`, damit in Produktion nie ein
 * Standardpasswort aus einem Seeder existiert.
 *
 * SettingsSeeder darf gefahrlos auch in Produktion laufen, weil er
 * ausschließlich fehlende Schlüssel ergänzt und keine vorhandenen Werte
 * überschreibt.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SettingsSeeder::class);
    }
}
