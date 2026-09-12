<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Änderungshistorie je Feld (Masterprompt-Abgleich B.6), geschrieben über
 * App\Observers\ListingChangeObserver. Interne Felder werden protokolliert,
 * aber nie exportiert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('feld');
            $table->text('alt')->nullable();
            $table->text('neu')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['listing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_changes');
    }
};
