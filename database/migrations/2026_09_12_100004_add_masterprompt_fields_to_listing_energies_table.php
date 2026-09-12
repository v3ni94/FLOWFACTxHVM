<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ausstellungsdatum, Ausnahmebestätigung und getrennter Stromkennwert für
 * Nichtwohngebäude (Masterprompt-Abgleich B.2, B.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_energies', function (Blueprint $table) {
            $table->date('ausstellungsdatum')->nullable()->after('ausweistyp');
            $table->decimal('kennwert_strom_kwh', 6, 1)->nullable()->after('kennwert_kwh');
            $table->text('ausnahme_begruendung')->nullable()->after('enthaelt_warmwasser');
            $table->foreignId('ausnahme_bestaetigt_von_user_id')->nullable()->after('ausnahme_begruendung')->constrained('users')->nullOnDelete();
            $table->timestamp('ausnahme_bestaetigt_at')->nullable()->after('ausnahme_bestaetigt_von_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('listing_energies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ausnahme_bestaetigt_von_user_id');
            $table->dropColumn(['ausstellungsdatum', 'kennwert_strom_kwh', 'ausnahme_begruendung', 'ausnahme_bestaetigt_at']);
        });
    }
};
