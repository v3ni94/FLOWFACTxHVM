<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfbericht 2026-09-12, Befund 3: die Adminbestätigung der
 * Energieausweis-Ausnahme wird an einen Hash der bestätigten Begründung
 * gebunden, damit eine spätere, nie geprüfte Begründung nicht unter einer
 * fremden Bestätigung weiterläuft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_energies', function (Blueprint $table) {
            $table->char('ausnahme_bestaetigt_hash', 64)->nullable()->after('ausnahme_bestaetigt_at');
        });
    }

    public function down(): void
    {
        Schema::table('listing_energies', function (Blueprint $table) {
            $table->dropColumn('ausnahme_bestaetigt_hash');
        });
    }
};
