<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfbericht 2026-09-12:
 * - Befund 5: Die tatsächlich gesendeten FLOWFACT-Feldnamen werden je
 *   Übertragung gespeichert (listing_flowfact_links.gesendete_felder_json,
 *   zusätzlich an der übertragenen Freigabeversion). Die Löschliste des
 *   nächsten PATCH entsteht aus diesen Namen, nicht aus einer erneuten
 *   Zuordnung der Vorgängerversion mit der dann aktuellen Feldzuordnung.
 * - Befund 10: listing_flowfact_links.flowfact_status hält den zuletzt
 *   gesendeten Entitätsstatus (active oder inactive). Ohne
 *   Veröffentlichungsrecht wird die Entität inaktiv angelegt; vor einer
 *   Veröffentlichung wird sie dann erneut übertragen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_flowfact_links', function (Blueprint $table): void {
            $table->json('gesendete_felder_json')->nullable()->after('flowfact_last_modified');
            $table->string('flowfact_status', 20)->nullable()->after('gesendete_felder_json');
        });

        Schema::table('listing_releases', function (Blueprint $table): void {
            $table->json('gesendete_felder_json')->nullable()->after('inhalt_hash');
        });
    }

    public function down(): void
    {
        Schema::table('listing_releases', function (Blueprint $table): void {
            $table->dropColumn('gesendete_felder_json');
        });

        Schema::table('listing_flowfact_links', function (Blueprint $table): void {
            $table->dropColumn(['gesendete_felder_json', 'flowfact_status']);
        });
    }
};
