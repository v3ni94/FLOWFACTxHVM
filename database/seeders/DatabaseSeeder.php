<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Erzeugt bewusst keine Benutzer. Benutzer entstehen ausschließlich über
 * das Kommando `flow:user:create`, damit in Produktion nie ein
 * Standardpasswort aus einem Seeder existiert.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Absichtlich leer.
    }
}
