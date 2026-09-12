<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interne Lageangaben (Masterprompt-Abgleich B.1 Schritt 2, B.2). Wie alle
 * Spalten in listing_internals nie Teil der Übertragung (ADR-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_internals', function (Blueprint $table) {
            $table->string('gebaeudebezeichnung')->nullable()->after('verwaltungsobjekt_referenz');
            $table->string('einheitsnummer')->nullable()->after('gebaeudebezeichnung');
            $table->string('lage_im_gebaeude')->nullable()->after('einheitsnummer');
        });
    }

    public function down(): void
    {
        Schema::table('listing_internals', function (Blueprint $table) {
            $table->dropColumn(['gebaeudebezeichnung', 'einheitsnummer', 'lage_im_gebaeude']);
        });
    }
};
