<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Energieausweis, 1:1 zu listings (Datenvertrag Abschnitt 2.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_energies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->unique()->constrained('listings')->cascadeOnDelete();
            $table->string('status')->nullable();
            $table->string('ausweistyp')->nullable();
            $table->decimal('kennwert_kwh', 6, 1)->nullable();
            $table->string('effizienzklasse')->nullable();
            $table->smallInteger('baujahr_anlage')->nullable();
            $table->date('gueltig_bis')->nullable();
            $table->boolean('enthaelt_warmwasser')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_energies');
    }
};
