<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Standortdaten (GPS-EXIF) in hochgeladenen Bildern (Masterprompt Abschnitt
 * 14): MediaUploadService setzt das Flag beim Upload anhand der EXIF-GPS-IFD
 * eines JPEGs. Die Oberfläche kann damit anzeigen, dass Standortdaten
 * enthalten sind und beim Export durch den Connector entfernt werden (die
 * Vorschau- und Übertragungs-Neukodierung überschreibt EXIF ohnehin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_media', function (Blueprint $table) {
            $table->boolean('enthaelt_standortdaten')->default(false)->after('rotation');
        });
    }

    public function down(): void
    {
        Schema::table('listing_media', function (Blueprint $table) {
            $table->dropColumn('enthaelt_standortdaten');
        });
    }
};
