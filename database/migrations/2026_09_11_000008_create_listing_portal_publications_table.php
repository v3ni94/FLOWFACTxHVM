<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portalveröffentlichungen (Datenvertrag Abschnitt 2.9, 4.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_portal_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->string('portal_id');
            $table->string('portal_name');
            $table->string('status')->default('nicht_veroeffentlicht');
            $table->timestamp('angefordert_at')->nullable();
            $table->timestamp('bestaetigt_at')->nullable();
            $table->timestamp('zurueckgezogen_at')->nullable();
            $table->timestamp('letzte_pruefung_at')->nullable();
            $table->text('letzter_fehler')->nullable();
            $table->timestamps();

            $table->unique(['listing_id', 'portal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_portal_publications');
    }
};
