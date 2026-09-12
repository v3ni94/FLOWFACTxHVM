<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nachweis je Portalstatuswechsel (Masterprompt-Abgleich B.6): Quelle
 * (z. B. "GET /estates/{id}/portals onlineSince", "POST /publish Antwort",
 * "manuell"), Zeitpunkt und Freigabeversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_portal_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('publication_id')->nullable()->constrained('listing_portal_publications')->nullOnDelete();
            $table->string('portal_id');
            $table->string('von_status')->nullable();
            $table->string('nach_status');
            $table->string('nachweis_quelle');
            $table->timestamp('nachweis_at')->nullable();
            $table->foreignId('release_id')->nullable()->constrained('listing_releases')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['listing_id', 'portal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_portal_status_logs');
    }
};
