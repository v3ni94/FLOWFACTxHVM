<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Übertragungsprotokoll (Datenvertrag Abschnitt 2.10, ADR-014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfer_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aktion');
            $table->string('richtung');
            $table->smallInteger('http_status')->nullable();
            $table->boolean('erfolgreich');
            $table->string('zusammenfassung');
            $table->json('details')->nullable();
            $table->unsignedInteger('dauer_ms')->nullable();
            $table->string('idempotenzschluessel')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'created_at']);
            $table->index('idempotenzschluessel');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_logs');
    }
};
