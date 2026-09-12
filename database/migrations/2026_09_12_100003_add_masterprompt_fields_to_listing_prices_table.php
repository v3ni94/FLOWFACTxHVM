<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stellplatzmodus, Provisionsbestätigung und Heizkostenstruktur
 * (Masterprompt-Abgleich B.2). heizkosten_struktur ersetzt fachlich das Flag
 * heizkosten_in_nebenkosten_enthalten plus listings.heizkosten_versorgung;
 * beide bleiben als Ableitung erhalten (PriceStructure), damit der
 * RentCalculator unverändert bleibt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_prices', function (Blueprint $table) {
            $table->string('stellplatz_modus')->default('keiner')->after('stellplatz_miete_cent');
            $table->boolean('stellplatz_im_kaufpreis')->nullable()->after('stellplatz_kaufpreis_cent');
            $table->string('heizkosten_struktur')->nullable()->after('heizkosten_in_nebenkosten_enthalten');
            $table->boolean('provision_bestaetigt')->default(false)->after('provision_text');
        });
    }

    public function down(): void
    {
        Schema::table('listing_prices', function (Blueprint $table) {
            $table->dropColumn(['stellplatz_modus', 'stellplatz_im_kaufpreis', 'heizkosten_struktur', 'provision_bestaetigt']);
        });
    }
};
