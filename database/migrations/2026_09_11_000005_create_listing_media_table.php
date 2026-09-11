<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medien eines Objekts (Datenvertrag Abschnitt 2.6, ADR-012).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->string('typ');
            $table->string('dateiname_original');
            $table->string('pfad');
            $table->string('mime');
            $table->unsignedInteger('groesse_bytes');
            $table->unsignedInteger('breite')->nullable();
            $table->unsignedInteger('hoehe')->nullable();
            $table->smallInteger('sortierung')->default(0);
            $table->string('titel')->nullable();
            $table->boolean('im_inserat')->default(true);
            $table->string('flowfact_multimedia_id')->nullable();
            $table->char('pruefsumme_sha256', 64);
            $table->timestamps();

            $table->index(['listing_id', 'sortierung']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_media');
    }
};
