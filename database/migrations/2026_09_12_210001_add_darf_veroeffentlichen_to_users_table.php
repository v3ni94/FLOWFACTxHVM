<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recht "Veröffentlichen" für Mitarbeiter, unabhängig von der Rolle
 * (Masterprompt Abschnitt 6, Abgleich B.2/B.3). Ein Administrator darf immer
 * veröffentlichen, unabhängig von diesem Feld (siehe User::kannVeroeffentlichen()).
 *
 * Nullable-Regel dieser Welle: Standardwert false, damit bestehende Zeilen
 * unverändert lauffähig bleiben und der aktuelle Assistent (Welle 1 unverändert)
 * nicht betroffen ist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('darf_veroeffentlichen')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('darf_veroeffentlichen');
        });
    }
};
