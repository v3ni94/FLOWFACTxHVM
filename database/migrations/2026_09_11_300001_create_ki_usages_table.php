<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verbrauchsprotokoll der KI-Textvorschläge (ADR-009). Ein Eintrag je Aufruf
 * der Anthropic Messages API, auch bei Fehlschlag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ki_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->string('modell');
            $table->unsignedInteger('input_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedInteger('dauer_ms')->nullable();
            $table->boolean('erfolgreich');
            $table->string('fehler')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['modell', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ki_usages');
    }
};
