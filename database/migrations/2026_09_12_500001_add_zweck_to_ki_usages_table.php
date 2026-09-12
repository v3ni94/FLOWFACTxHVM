<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zweck eines KI-Aufrufs (Masterprompt Abschnitt 16, ADR-009): "entwurf" für
 * AnthropicTextGenerator::generate(), "ueberarbeitung" für
 * AnthropicTextReviser::revise(). Nullable, da ältere Einträge und der
 * Verbindungstest (testConnection()) keinen Zweck tragen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ki_usages', function (Blueprint $table) {
            $table->string('zweck')->nullable()->after('modell');
            $table->index(['zweck', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ki_usages', function (Blueprint $table) {
            $table->dropIndex(['zweck', 'created_at']);
            $table->dropColumn('zweck');
        });
    }
};
