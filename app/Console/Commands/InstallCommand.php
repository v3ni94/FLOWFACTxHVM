<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * flow:install
 *
 * Idempotente Inbetriebnahme nach jedem Deployment
 * (docs/betrieb/installation.md, Architektur Abschnitt 6). Prüft
 * Voraussetzungen, führt Migrationen aus und baut die Caches neu.
 *
 * Exit-Code 1 bei Blockern (fehlender APP_KEY, fehlende PHP-Erweiterung,
 * nicht beschreibbare Verzeichnisse). Warnungen (z. B. APP_DEBUG in der
 * Produktion) blockieren nicht.
 */
class InstallCommand extends Command
{
    protected $signature = 'flow:install';

    protected $description = 'Führt die Inbetriebnahme nach einem Deployment idempotent aus (Migrationen, Caches, Prüfungen).';

    /**
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = [
        'pdo',
        'mbstring',
        'gd',
        'intl',
        'zip',
        'openssl',
        'fileinfo',
        'ctype',
        'json',
        'tokenizer',
        'dom',
    ];

    public function handle(): int
    {
        $this->info('Müller FLOW – Inbetriebnahme');
        $this->line(str_repeat('-', 60));

        $blocker = false;

        // --- APP_KEY ---------------------------------------------------------
        if (blank(config('app.key'))) {
            $this->error('APP_KEY ist nicht gesetzt. Bitte "php artisan key:generate" ausführen.');
            $blocker = true;
        } else {
            $this->line('APP_KEY ist gesetzt.');
        }

        // --- PHP-Erweiterungen -------------------------------------------------
        $fehlend = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (! extension_loaded($extension)) {
                $fehlend[] = $extension;
            }
        }

        if ($fehlend !== []) {
            $this->error('Fehlende PHP-Erweiterungen: '.implode(', ', $fehlend));
            $blocker = true;
        } else {
            $this->line('Alle benötigten PHP-Erweiterungen sind geladen.');
        }

        // --- Schreibrechte ---------------------------------------------------
        foreach ([storage_path(), base_path('bootstrap/cache')] as $verzeichnis) {
            if (! File::isDirectory($verzeichnis) || ! File::isWritable($verzeichnis)) {
                $this->error(sprintf('Verzeichnis nicht beschreibbar: %s', $verzeichnis));
                $blocker = true;
            } else {
                $this->line(sprintf('Verzeichnis beschreibbar: %s', $verzeichnis));
            }
        }

        // --- Medienverzeichnis ------------------------------------------------
        $mediaRoot = (string) config('media.root');

        if (! File::isDirectory($mediaRoot)) {
            if (! File::makeDirectory($mediaRoot, 0755, true, true)) {
                $this->error(sprintf('Medienverzeichnis konnte nicht angelegt werden: %s', $mediaRoot));
                $blocker = true;
            } else {
                $this->line(sprintf('Medienverzeichnis angelegt: %s', $mediaRoot));
            }
        } elseif (! File::isWritable($mediaRoot)) {
            $this->error(sprintf('Medienverzeichnis nicht beschreibbar: %s', $mediaRoot));
            $blocker = true;
        } else {
            $this->line(sprintf('Medienverzeichnis vorhanden und beschreibbar: %s', $mediaRoot));
        }

        // --- Warnungen, blockieren nicht --------------------------------------
        if (app()->environment('production') && config('app.debug') === true) {
            $this->warn('APP_DEBUG ist in der Produktion aktiv. Bitte in der shared/.env auf false setzen.');
        }

        if ($blocker) {
            $this->newLine();
            $this->error('Abgebrochen: Blockierende Punkte oben beheben und erneut ausführen.');

            return CommandAlias::FAILURE;
        }

        // --- Migrationen und Caches --------------------------------------------
        $this->newLine();
        $this->line('Führe Migrationen aus ...');
        Artisan::call('migrate', ['--force' => true], $this->output);

        foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $command) {
            $this->line(sprintf('Baue Cache: %s', $command));
            Artisan::call($command, [], $this->output);
        }

        $this->newLine();
        $this->info('Inbetriebnahme abgeschlossen.');

        return CommandAlias::SUCCESS;
    }
}
