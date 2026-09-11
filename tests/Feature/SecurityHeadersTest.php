<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private function registerTestRoute(): void
    {
        Route::get('/_test/security-headers', fn () => 'ok');
    }

    public function test_security_headers_are_present_on_every_response(): void
    {
        $this->registerTestRoute();

        $response = $this->get('/_test/security-headers');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $response->assertHeaderMissing('Strict-Transport-Security');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_security_headers_are_present_on_404_responses(): void
    {
        $response = $this->get('/_test/route-die-nicht-existiert');

        $response->assertStatus(404);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_health_endpoint_carries_security_headers(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
    }
}
