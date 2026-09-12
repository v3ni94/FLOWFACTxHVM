<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freigabeversionen im Connector (Masterprompt-Abgleich B.6, Masterprompt
 * Abschnitt 19 und 23):
 * - listing_flowfact_links.release_id: zuletzt erfolgreich übertragene Version.
 * - listing_flowfact_links.flowfact_last_modified: _metadata.lastModifiedTimestamp
 *   der FLOWFACT-Entität nach dem letzten Anlegen oder Aktualisieren, Grundlage
 *   der Konflikterkennung vor einem PATCH (Zeichenkette, wie geliefert).
 * - listing_portal_publications.release_id: Version, mit der die
 *   Veröffentlichung angefordert wurde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_flowfact_links', function (Blueprint $table): void {
            $table->foreignId('release_id')->nullable()->after('uebertragener_inhalt_hash')->constrained('listing_releases')->nullOnDelete();
            $table->string('flowfact_last_modified', 64)->nullable()->after('release_id');
        });

        Schema::table('listing_portal_publications', function (Blueprint $table): void {
            $table->foreignId('release_id')->nullable()->after('status')->constrained('listing_releases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('listing_portal_publications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('release_id');
        });

        Schema::table('listing_flowfact_links', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('release_id');
            $table->dropColumn('flowfact_last_modified');
        });
    }
};
