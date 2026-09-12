<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freigabeversionen (Masterprompt-Abgleich B.6). Übertragung und
 * Veröffentlichung arbeiten ausschließlich mit der jüngsten Version, nie mit
 * dem Live-Stand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('payload_json');
            $table->json('medien_json');
            $table->json('portale_json');
            $table->char('inhalt_hash', 64);
            $table->foreignId('freigegeben_von_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('freigegeben_at');
            $table->string('aktion');
            $table->timestamps();

            $table->unique(['listing_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_releases');
    }
};
