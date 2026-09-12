<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Einladungen neuer Benutzer per E-Mail (Masterprompt Abschnitt 6, Abgleich
 * B.2). Der eingeladene Benutzer wird erst beim Abschluss der Einladung
 * (Passwortvergabe über /einladung/{token}) als Zeile in "users" angelegt;
 * bis dahin existiert nur dieser Datensatz.
 *
 * Es wird ausschließlich der SHA-256-Hash des Einladungstokens gespeichert,
 * nie der rohe Token selbst (App\Models\UserInvitation::issue()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('role');
            $table->boolean('darf_veroeffentlichen')->default(false);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->foreignId('eingeladen_von_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
