<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FLOWFACT-Verknüpfung, 1:1 zu listings (Datenvertrag Abschnitt 2.8, ADR-005).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_flowfact_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->string('flowfact_entity_id')->nullable();
            $table->string('flowfact_schema')->nullable();
            $table->string('sync_status')->default('nicht_uebertragen');
            $table->timestamp('letzte_uebertragung_at')->nullable();
            $table->text('letzter_fehler')->nullable();
            $table->string('uebertragener_inhalt_hash')->nullable();
            $table->timestamp('sperre_bis')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_flowfact_links');
    }
};
