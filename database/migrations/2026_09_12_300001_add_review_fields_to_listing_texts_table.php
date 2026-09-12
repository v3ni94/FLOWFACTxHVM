<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datenbasis-Hash und Überarbeitungsanweisung je Text (Masterprompt-Abgleich
 * B.1 Schritt 8, Masterprompt Abschnitt 16).
 *
 * datenbasis_hash wird beim Übernehmen eines Textvorschlags und bei jeder
 * manuellen Speicherung eines Textfelds auf den aktuellen
 * ListingContentHasher-Hash gesetzt. Weicht der aktuelle Hash des Objekts von
 * diesem Wert ab, gilt das Feld als "prüfbedürftig": die Daten haben sich
 * seit der letzten Übernahme geändert.
 *
 * anweisung hält bei einer gezielten Überarbeitung (kürzer, sachlicher,
 * sprachlich verbessern) fest, welche Anweisung zu diesem Vorschlag geführt
 * hat (App\Services\Ai\TextReviser).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_texts', function (Blueprint $table): void {
            $table->char('datenbasis_hash', 64)->nullable()->after('uebernommen');
            $table->string('anweisung')->nullable()->after('datenbasis_hash');
        });
    }

    public function down(): void
    {
        Schema::table('listing_texts', function (Blueprint $table): void {
            $table->dropColumn(['datenbasis_hash', 'anweisung']);
        });
    }
};
