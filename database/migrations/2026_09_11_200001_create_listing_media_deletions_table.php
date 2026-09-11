<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vormerkungen für in FLOWFACT zu löschende Multimedia-Items
 * (docs/connector.md Abschnitt 3, Schritt 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_media_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->string('flowfact_multimedia_id');
            $table->timestamp('created_at')->nullable();

            $table->index('listing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_media_deletions');
    }
};
