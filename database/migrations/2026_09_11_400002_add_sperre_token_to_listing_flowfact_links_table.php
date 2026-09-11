<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zufallstoken der Sync-Lease (Prüfbericht 2026-09-11, Befund 7). Die
 * Freigabe erfolgt nur noch bedingt auf das eigene Token, damit ein Lauf,
 * der seine Lease überschritten hat, nicht die Lease eines Nachfolgers löscht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_flowfact_links', function (Blueprint $table) {
            $table->string('sperre_token', 64)->nullable()->after('sperre_bis');
        });
    }

    public function down(): void
    {
        Schema::table('listing_flowfact_links', function (Blueprint $table) {
            $table->dropColumn('sperre_token');
        });
    }
};
