<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\CheckConfigCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * flow:check-config, Warteschlangenprüfung (Prüfbericht 2026-09-11, Befund 8).
 */
final class CheckConfigCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('media.root', sys_get_temp_dir());
        Cache::put('scheduler.last_run', now(), now()->addDay());
    }

    private function jobEinfuegen(int $verfuegbarSeitSekunden, ?int $reserviertVorSekunden = null): void
    {
        $jetzt = now()->getTimestamp();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => $reserviertVorSekunden !== null ? $jetzt - $reserviertVorSekunden : null,
            'available_at' => $jetzt - $verfuegbarSeitSekunden,
            'created_at' => $jetzt - $verfuegbarSeitSekunden,
        ]);
    }

    public function test_ohne_jobs_meldet_die_pruefung_keine_gestauten_jobs(): void
    {
        $this->artisan('flow:check-config')
            ->expectsOutputToContain('keine Jobs in der Tabelle, keine gestauten Jobs')
            ->expectsOutputToContain('FLOWFACT-Konfliktverhalten')
            ->assertExitCode(0);
    }

    public function test_offene_jobs_aelter_als_15_minuten_gelten_als_fehler(): void
    {
        $this->jobEinfuegen((CheckConfigCommand::JOBS_STAU_MINUTEN + 5) * 60);
        DB::table('failed_jobs')->insert([
            'uuid' => 'f-1',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'x',
            'failed_at' => now(),
        ]);

        $this->artisan('flow:check-config')
            ->expectsOutputToContain('Jobs ausstehend / fehlgeschlagen  1 / 1')
            // Eine Zeile je Erwartung: mehrere Teilstrings derselben Zeile werden vom Ausgabe-Mock nur einmal abgeglichen.
            ->expectsOutputToContain('neuester Job vor 20 Minuten, 1 offene(r) Job(s) seit mehr als 15 Minuten fällig')
            ->assertExitCode(1);
    }

    public function test_junge_und_reservierte_jobs_zaehlen_nicht_als_stau(): void
    {
        $this->jobEinfuegen(60);
        $this->jobEinfuegen(30 * 60, reserviertVorSekunden: 10);

        $this->artisan('flow:check-config')
            ->expectsOutputToContain('neuester Job vor 1 Minuten, keine gestauten Jobs')
            ->assertExitCode(0);
    }
}
