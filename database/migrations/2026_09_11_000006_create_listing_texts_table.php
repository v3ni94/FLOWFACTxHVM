<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historie der KI- und Handtexte (Datenvertrag Abschnitt 2.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->string('feld');
            $table->string('quelle');
            $table->string('modell')->nullable();
            $table->text('inhalt');
            $table->boolean('uebernommen')->default(false);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['listing_id', 'feld']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_texts');
    }
};
