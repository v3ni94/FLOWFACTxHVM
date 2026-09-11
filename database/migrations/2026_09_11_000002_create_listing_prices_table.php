<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preise, 1:1 zu listings (Datenvertrag Abschnitt 2.3). Alle Beträge in Cent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->unsignedBigInteger('kaltmiete_cent')->nullable();
            $table->unsignedBigInteger('nebenkosten_cent')->nullable();
            $table->unsignedBigInteger('heizkosten_cent')->nullable();
            $table->boolean('heizkosten_in_nebenkosten_enthalten')->default(false);
            $table->unsignedBigInteger('warmmiete_cent')->nullable();
            $table->unsignedBigInteger('kaution_cent')->nullable();
            $table->unsignedBigInteger('stellplatz_miete_cent')->nullable();
            $table->unsignedBigInteger('kaufpreis_cent')->nullable();
            $table->unsignedBigInteger('hausgeld_cent')->nullable();
            $table->unsignedBigInteger('stellplatz_kaufpreis_cent')->nullable();
            $table->unsignedBigInteger('mieteinnahmen_ist_cent')->nullable();
            $table->string('provision_typ')->default('provisionsfrei');
            $table->string('provision_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_prices');
    }
};
