<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fortlaufender Zähler je Jahr für die Objektnummer MF-JJJJ-NNNN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objektnummer_counters', function (Blueprint $table) {
            $table->smallInteger('jahr')->primary();
            $table->unsignedInteger('letzte_nummer')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objektnummer_counters');
    }
};
