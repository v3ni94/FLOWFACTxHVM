<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interne Daten, 1:1 zu listings, NIE Teil der Übertragung
 * (Datenvertrag Abschnitt 2.5, ADR-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_internals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->string('eigentuemer_name')->nullable();
            $table->text('eigentuemer_kontakt')->nullable();
            $table->string('verwaltungsobjekt_referenz')->nullable();
            $table->text('interne_notizen')->nullable();
            $table->text('schluessel_hinweis')->nullable();
            $table->text('besichtigung_intern')->nullable();
            $table->text('kalkulation_notiz')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_internals');
    }
};
