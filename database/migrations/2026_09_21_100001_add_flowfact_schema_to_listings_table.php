<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Das FLOWFACT-Zielschema wird je Objekt in Schritt 1 gewählt, nicht mehr
 * ausschließlich global im Adminbereich (Kundenwunsch 21.09.2026): der
 * Sachbearbeiter sieht nach Miete/Kauf die bei FLOWFACT geladenen Schemata
 * (Adminbereich, "Schemata laden") und wählt das passende aus. Die globalen
 * Einstellungen flowfact.schema_miete/flowfact.schema_kauf bleiben als
 * Rückfall für Objekte ohne eigene Wahl erhalten (ListingSyncService::
 * schemaFuer()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('flowfact_schema', 150)->nullable()->after('objektart');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('flowfact_schema');
        });
    }
};
