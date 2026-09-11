<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objekte (Datenvertrag Abschnitt 2.2).
 *
 * Enum-Werte werden als string gespeichert, nie als Datenbank-Enum, damit die
 * Migration unverändert gegen MariaDB und SQLite läuft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('objektnummer')->unique();
            $table->string('vermarktungsart');
            $table->string('objektart');
            $table->string('titel', 100)->nullable();
            $table->string('strasse')->nullable();
            $table->string('hausnummer')->nullable();
            $table->string('plz', 5)->nullable();
            $table->string('ort')->nullable();
            $table->string('land', 2)->default('DE');
            $table->boolean('adresse_im_inserat_anzeigen')->default(true);
            $table->decimal('wohnflaeche_qm', 8, 2)->nullable();
            $table->decimal('nutzflaeche_qm', 8, 2)->nullable();
            $table->decimal('grundstuecksflaeche_qm', 10, 2)->nullable();
            $table->decimal('zimmer', 4, 1)->nullable();
            $table->smallInteger('schlafzimmer')->nullable();
            $table->smallInteger('badezimmer')->nullable();
            $table->smallInteger('etage')->nullable();
            $table->smallInteger('etagen_gesamt')->nullable();
            $table->smallInteger('baujahr')->nullable();
            $table->string('zustand')->nullable();
            $table->string('ausstattungsqualitaet')->nullable();
            $table->string('heizungsart')->nullable();
            $table->string('energietraeger')->nullable();
            $table->string('heizkosten_versorgung')->nullable();
            $table->string('verfuegbar_ab_typ')->nullable();
            $table->date('verfuegbar_ab_datum')->nullable();
            $table->json('ausstattung')->nullable();
            $table->string('stellplatz_typ')->nullable();
            $table->smallInteger('stellplatz_anzahl')->nullable();
            $table->text('beschreibung_objekt')->nullable();
            $table->text('beschreibung_ausstattung')->nullable();
            $table->text('beschreibung_lage')->nullable();
            $table->text('beschreibung_sonstiges')->nullable();
            $table->foreignId('ansprechpartner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('entwurf');
            $table->foreignId('erstellt_von_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('inhalt_geaendert_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
