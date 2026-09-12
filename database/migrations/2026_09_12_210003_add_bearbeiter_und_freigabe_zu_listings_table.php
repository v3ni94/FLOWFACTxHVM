<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Berechtigungsfelder für Objekte (Masterprompt Abschnitt 6, Abgleich B.2/B.3).
 *
 * bearbeiter_user_id: zuständiger Mitarbeiter, Grundlage der Bearbeitungs-
 * und Veröffentlichungsrechte in ListingPolicy. freigegeben_fuer_alle: hebt
 * die Bearbeitungssperre für alle Mitarbeiter auf.
 *
 * Beide Spalten sind nullable bzw. mit Standardwert false, damit der aktuelle
 * Assistent (Welle 1 unverändert, app/Http/Controllers/App/**) ohne Anpassung
 * weiterläuft; er setzt diese Felder noch nicht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->foreignId('bearbeiter_user_id')->nullable()->after('erstellt_von_user_id')
                ->constrained('users')->nullOnDelete();
            $table->boolean('freigegeben_fuer_alle')->default(false)->after('bearbeiter_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bearbeiter_user_id');
            $table->dropColumn('freigegeben_fuer_alle');
        });
    }
};
