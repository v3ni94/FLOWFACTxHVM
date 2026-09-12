<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

/**
 * flow:check-config
 *
 * Betriebsprüfung ohne Shellzugang (docs/betrieb/installation.md, Architektur
 * Abschnitt 6). Gibt eine ausgerichtete Tabelle aus und liefert Exit-Code 1
 * bei Fehlern. Wird zusätzlich täglich um 06:00 über den Scheduler
 * aufgerufen (routes/console.php).
 */
class CheckConfigCommand extends Command
{
    /** Offene Jobs, die länger als so viele Minuten fällig sind, gelten als Stau. */
    public const int JOBS_STAU_MINUTEN = 15;

    protected $signature = 'flow:check-config';

    protected $description = 'Prüft Umgebung, Datenbank, Speicher, Mail, Warteschlange und Scheduler-Lebenszeichen.';

    /**
     * @var list<array{0: string, 1: string, 2: bool}>
     */
    private array $zeilen = [];

    public function handle(): int
    {
        $fehler = false;

        $fehler = $this->pruefeUmgebung() || $fehler;
        $fehler = $this->pruefeDatenbank() || $fehler;
        $fehler = $this->pruefeMedien() || $fehler;
        $this->pruefeMail();
        $fehler = $this->pruefeWarteschlange() || $fehler;
        $fehler = $this->pruefeScheduler() || $fehler;
        $this->pruefeFlowfact();

        $this->tabelleAusgeben();

        if ($fehler) {
            $this->newLine();
            $this->error('Es liegen Fehler vor, siehe oben.');

            return CommandAlias::FAILURE;
        }

        $this->newLine();
        $this->info('Alle Prüfungen ohne Fehler.');

        return CommandAlias::SUCCESS;
    }

    private function pruefeUmgebung(): bool
    {
        $fehler = false;

        $this->zeile('APP_ENV', (string) app()->environment(), false);

        $debug = (bool) config('app.debug');

        if (app()->environment('production') && $debug) {
            $this->zeile('APP_DEBUG', 'aktiv (Warnung: in Produktion deaktivieren)', false);
        } else {
            $this->zeile('APP_DEBUG', $debug ? 'aktiv' : 'inaktiv', false);
        }

        $url = (string) config('app.url');
        $schema = parse_url($url, PHP_URL_SCHEME) ?: 'unbekannt';

        if (app()->environment('production') && $schema !== 'https') {
            $this->zeile('APP_URL-Schema', $schema.' (Fehler: Produktion benötigt https)', true);
            $fehler = true;
        } else {
            $this->zeile('APP_URL-Schema', $schema, false);
        }

        $proxies = config('deploy.trusted_proxies');
        $this->zeile('TRUSTED_PROXIES', $proxies === '' || $proxies === null ? 'nicht gesetzt' : (string) $proxies, false);

        return $fehler;
    }

    private function pruefeDatenbank(): bool
    {
        try {
            DB::connection()->getPdo();
            $this->zeile('Datenbankverbindung', 'erfolgreich ('.DB::connection()->getDriverName().')', false);
        } catch (Throwable $exception) {
            $this->zeile('Datenbankverbindung', 'fehlgeschlagen: '.$exception->getMessage(), true);

            return true;
        }

        try {
            $ausstehend = ! Schema::hasTable('migrations') || $this->offeneMigrationen();
            $this->zeile('Migrationsstatus', $ausstehend ? 'ausstehende Migrationen' : 'aktuell', $ausstehend);

            return $ausstehend;
        } catch (Throwable $exception) {
            $this->zeile('Migrationsstatus', 'nicht ermittelbar: '.$exception->getMessage(), true);

            return true;
        }
    }

    private function offeneMigrationen(): bool
    {
        $dateien = collect(File::glob(database_path('migrations/*.php')))
            ->map(fn (string $pfad) => pathinfo($pfad, PATHINFO_FILENAME))
            ->map(fn (string $name) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name))
            ->filter()
            ->unique();

        $ausgefuehrt = collect(DB::table('migrations')->pluck('migration'))
            ->map(fn (string $name) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $name))
            ->unique();

        return $dateien->diff($ausgefuehrt)->isNotEmpty();
    }

    private function pruefeMedien(): bool
    {
        $root = (string) config('media.root');

        if (! File::isDirectory($root) || ! File::isWritable($root)) {
            $this->zeile('Medienverzeichnis', 'nicht beschreibbar: '.$root, true);

            return true;
        }

        $this->zeile('Medienverzeichnis', 'beschreibbar: '.$root, false);

        return false;
    }

    private function pruefeMail(): void
    {
        $this->zeile('Mail-Treiber', (string) config('mail.default'), false);
    }

    /**
     * Warteschlange (Prüfbericht 2026-09-11, Befund 8): neben den Zählern
     * wird das Alter des neuesten Jobs gemeldet. Offene Jobs, die seit mehr
     * als 15 Minuten fällig sind, gelten als Fehler: der Worker
     * (queue:work über den Scheduler) verarbeitet dann nicht.
     */
    private function pruefeWarteschlange(): bool
    {
        $treiber = (string) config('queue.default');
        $this->zeile('Warteschlangentreiber', $treiber, false);

        try {
            $ausstehend = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
            $fehlgeschlagen = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
            $this->zeile('Jobs ausstehend / fehlgeschlagen', $ausstehend.' / '.$fehlgeschlagen, false);
        } catch (Throwable) {
            $this->zeile('Jobs ausstehend / fehlgeschlagen', 'nicht ermittelbar', false);

            return false;
        }

        if (! Schema::hasTable('jobs')) {
            return false;
        }

        try {
            $jetzt = now()->getTimestamp();
            $neuester = DB::table('jobs')->max('created_at');
            $grenze = $jetzt - self::JOBS_STAU_MINUTEN * 60;

            $gestaut = DB::table('jobs')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $grenze)
                ->count();

            $neuesterText = $neuester === null
                ? 'keine Jobs in der Tabelle'
                : sprintf('neuester Job vor %d Minuten', intdiv(max(0, $jetzt - (int) $neuester), 60));

            if ($gestaut > 0) {
                $this->zeile('Warteschlange', sprintf('%s, %d offene(r) Job(s) seit mehr als %d Minuten fällig (Fehler: queue:work verarbeitet nicht)', $neuesterText, $gestaut, self::JOBS_STAU_MINUTEN), true);

                return true;
            }

            $this->zeile('Warteschlange', $neuesterText.', keine gestauten Jobs', false);
        } catch (Throwable $exception) {
            $this->zeile('Warteschlange', 'nicht ermittelbar: '.$exception->getMessage(), false);
        }

        return false;
    }

    private function pruefeScheduler(): bool
    {
        $letzterLauf = Cache::get('scheduler.last_run');

        if ($letzterLauf === null) {
            $this->zeile('Scheduler-Lebenszeichen', 'kein Lauf aufgezeichnet', true);

            return true;
        }

        $zeitpunkt = $letzterLauf instanceof \DateTimeInterface
            ? Carbon::instance($letzterLauf)
            : Carbon::parse((string) $letzterLauf);

        $minuten = $zeitpunkt->diffInMinutes(now());
        $fehler = $minuten > 15;

        $this->zeile(
            'Scheduler-Lebenszeichen',
            sprintf('letzter Lauf vor %d Minuten (%s)', $minuten, $zeitpunkt->toDateTimeString()),
            $fehler
        );

        return $fehler;
    }

    private function pruefeFlowfact(): void
    {
        $this->zeile('FLOWFACT-Stage', (string) config('flowfact.stage'), false);
        $this->zeile('FLOWFACT-Basis-URL', (string) config('flowfact.base_url'), false);

        $tokenHinterlegt = 'nicht konfiguriert';

        try {
            if (Schema::hasTable('settings')) {
                $vorhanden = DB::table('settings')->where('key', 'flowfact.api_token')->exists();
                $tokenHinterlegt = $vorhanden ? 'hinterlegt' : 'nicht hinterlegt';
            }
        } catch (Throwable) {
            $tokenHinterlegt = 'nicht ermittelbar';
        }

        $this->zeile('FLOWFACT-Token', $tokenHinterlegt, false);
        $this->zeile('FLOWFACT-Konfliktverhalten', $this->konfliktverhalten(), false);
    }

    /**
     * Einstellung flowfact.konfliktverhalten (docs/connector.md Abschnitt 7):
     * abbrechen (Standard) oder ueberschreiben.
     */
    private function konfliktverhalten(): string
    {
        try {
            if (! Schema::hasTable('settings')) {
                return 'abbrechen (Standard, keine Einstellungen)';
            }

            $wert = DB::table('settings')->where('key', 'flowfact.konfliktverhalten')->value('value');

            if ($wert === null) {
                return 'abbrechen (Standard)';
            }

            $dekodiert = json_decode((string) $wert, true);
            $text = is_string($dekodiert) ? strtolower(trim($dekodiert)) : '';

            return $text === 'ueberschreiben' ? 'ueberschreiben' : 'abbrechen';
        } catch (Throwable) {
            return 'nicht ermittelbar';
        }
    }

    private function zeile(string $bezeichnung, string $wert, bool $istFehler): void
    {
        $this->zeilen[] = [$bezeichnung, $wert, $istFehler];
    }

    private function tabelleAusgeben(): void
    {
        $breite = max(array_map(static fn (array $zeile) => mb_strlen($zeile[0]), $this->zeilen) ?: [0]);

        foreach ($this->zeilen as [$bezeichnung, $wert, $istFehler]) {
            $zeile = str_pad($bezeichnung, $breite).'  '.$wert;

            if ($istFehler) {
                $this->error($zeile);
            } else {
                $this->line($zeile);
            }
        }
    }
}
