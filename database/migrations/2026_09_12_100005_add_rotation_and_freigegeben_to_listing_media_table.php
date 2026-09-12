<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drehung und Freigabe je Medium (Masterprompt-Abgleich B.2). Freigabe:
 * Standard true bei bild und grundriss, false bei dokument und
 * energieausweis (Model-Hook beim Anlegen, hier Nachtrag für Bestandsdaten).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_media', function (Blueprint $table) {
            $table->smallInteger('rotation')->default(0)->after('hoehe');
            $table->boolean('freigegeben')->default(true)->after('im_inserat');
        });

        DB::table('listing_media')
            ->whereIn('typ', ['dokument', 'energieausweis'])
            ->update(['freigegeben' => false]);
    }

    public function down(): void
    {
        Schema::table('listing_media', function (Blueprint $table) {
            $table->dropColumn(['rotation', 'freigegeben']);
        });
    }
};
