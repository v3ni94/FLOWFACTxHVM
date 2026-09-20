<?php

declare(strict_types=1);

namespace Tests\Unit\Install;

use PHPUnit\Framework\TestCase;

/**
 * Reine Dateiprüfung ohne Laravel-Bootstrap: die .htaccess-Dateien dürfen
 * keinen "Options"-Befehl enthalten (IONOS Webhosting antwortet sonst mit
 * Fehler 500) und müssen sensible Pfade vor der Umschreibung sperren.
 */
class HtaccessTest extends TestCase
{
    public function test_root_htaccess_has_no_options_directive(): void
    {
        $content = file_get_contents(base_path_for_test('.htaccess'));

        $this->assertDoesNotMatchRegularExpression('/^\s*Options\b/mi', $content);
    }

    public function test_root_htaccess_blocks_env_and_vendor_before_rewriting(): void
    {
        $content = file_get_contents(base_path_for_test('.htaccess'));

        $this->assertMatchesRegularExpression('/RewriteRule\s+\(\^\|\/\)\\\\\./', $content);
        $this->assertStringContainsString('vendor', $content);

        // Reihenfolge: Sperren vor Umschreiben.
        $sperrePosition = strpos($content, 'Sperren');
        $umschreibenPosition = strpos($content, 'Umschreiben nach public');

        $this->assertNotFalse($sperrePosition);
        $this->assertNotFalse($umschreibenPosition);
        $this->assertLessThan($umschreibenPosition, $sperrePosition);
    }

    public function test_public_htaccess_has_no_options_directive(): void
    {
        $content = file_get_contents(base_path_for_test('public/.htaccess'));

        $this->assertDoesNotMatchRegularExpression('/^\s*Options\b/mi', $content);
    }

    public function test_public_htaccess_is_reduced_to_the_ionos_verified_rules(): void
    {
        // Stand 20.09.2026: Redirects, REDIRECT_STATUS-Bedingung und die Sperre
        // versteckter Dateien fuehrten auf IONOS zu Fehler 500. Die Sperre ist
        // entbehrlich, weil versteckte Dateien ausserhalb von public/ liegen.
        $content = file_get_contents(base_path_for_test('public/.htaccess'));

        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $content);
        $this->assertStringNotContainsString('REDIRECT_STATUS', $content);
        $this->assertStringNotContainsString('R=301', $content);
        $this->assertStringNotContainsString('Options', $content);
    }
}

/**
 * Kleiner Helfer, um ohne Laravel-Bootstrap den Projektwurzelpfad zu bilden.
 */
function base_path_for_test(string $path): string
{
    return dirname(__DIR__, 3).'/'.$path;
}
