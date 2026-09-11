<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zuletzt an FLOWFACT übertragener Bildtitel je Medium (Prüfbericht
 * 2026-09-11, Befund 4). Weicht listing_media.titel davon ab, sendet der
 * MediaSyncService einen PATCH /items/{id} und schreibt den Wert nach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_media', function (Blueprint $table) {
            $table->string('flowfact_titel')->nullable()->after('flowfact_multimedia_id');
        });
    }

    public function down(): void
    {
        Schema::table('listing_media', function (Blueprint $table) {
            $table->dropColumn('flowfact_titel');
        });
    }
};
