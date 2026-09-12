<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Neue Inserats- und Objektfelder (Masterprompt-Abgleich B.2). Alle Spalten
 * sind nullable oder mit Standardwert, damit der bestehende Assistent
 * unverändert weiterläuft. bearbeiter_user_id und freigegeben_fuer_alle
 * kommen in einer eigenen Migration der Benutzerverwaltung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('gewerbe_unterart')->nullable()->after('objektart');
            $table->string('nutzungsstatus')->default('unbekannt')->after('gewerbe_unterart');
            $table->string('adresszusatz')->nullable()->after('hausnummer');
            $table->string('stadtteil')->nullable()->after('ort');
            $table->string('adress_freigabe')->default('vollstaendig')->after('adresse_im_inserat_anzeigen');
            $table->decimal('gewerbeflaeche_qm', 8, 2)->nullable()->after('nutzflaeche_qm');
            $table->smallInteger('modernisierungsjahr')->nullable()->after('baujahr');
            $table->string('interne_bezeichnung')->nullable()->after('titel');
            $table->string('heizung_waermeabgabe')->nullable()->after('heizkosten_versorgung');
            $table->string('heizung_warmwasser')->nullable()->after('heizung_waermeabgabe');
            $table->boolean('einbaukueche_mitvermietet')->nullable()->after('ausstattung');
        });

        // Bestandsdaten: Adressfreigabe aus dem bisherigen Flag ableiten.
        DB::table('listings')
            ->where('adresse_im_inserat_anzeigen', false)
            ->update(['adress_freigabe' => 'nur_plz_ort']);
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn([
                'gewerbe_unterart',
                'nutzungsstatus',
                'adresszusatz',
                'stadtteil',
                'adress_freigabe',
                'gewerbeflaeche_qm',
                'modernisierungsjahr',
                'interne_bezeichnung',
                'heizung_waermeabgabe',
                'heizung_warmwasser',
                'einbaukueche_mitvermietet',
            ]);
        });
    }
};
