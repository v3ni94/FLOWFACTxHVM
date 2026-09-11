<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_flow_install_runs_twice_on_sqlite_without_error(): void
    {
        // Eigenes, garantiert beschreibbares Medienverzeichnis für den Test.
        $mediaRoot = storage_path('framework/testing/media-install-test');
        File::deleteDirectory($mediaRoot);
        config(['media.root' => $mediaRoot]);

        $this->artisan('flow:install')->assertExitCode(0);
        $this->assertTrue(File::isDirectory($mediaRoot));

        // Zweiter, idempotenter Lauf darf ebenfalls ohne Fehler durchlaufen.
        $this->artisan('flow:install')->assertExitCode(0);

        File::deleteDirectory($mediaRoot);
    }
}
