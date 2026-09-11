<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    /**
     * Einige Tests führen flow:install aus, das Konfigurations-, Routen- und
     * View-Caches nach bootstrap/cache schreibt. Eine gecachte Konfiguration
     * würde alle folgenden Tests verfälschen (bootstrap/providers.php wird dann
     * nicht mehr gelesen). Deshalb wird nach jedem Test aufgeräumt, sobald ein
     * Cache vorliegt.
     */
    protected function tearDown(): void
    {
        if (file_exists($this->app->getCachedConfigPath()) || file_exists($this->app->getCachedRoutesPath())) {
            Artisan::call('optimize:clear');
        }

        parent::tearDown();
    }
}
