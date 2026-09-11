<?php

declare(strict_types=1);

namespace Tests\Feature\Pages;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rendert die vollständigen Seiten mit den echten Layouts (Agent A konnte
 * das während der Parallelentwicklung nicht prüfen) und stellt sicher, dass
 * keine Inline-Styles oder Inline-Skripte entstehen, die die CSP blockiert.
 */
final class PagesRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_with_guest_layout(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Müller FLOW');
        $response->assertHeader('Content-Security-Policy');
        $this->assertNoInlineCode($response->getContent());
    }

    public function test_authenticated_pages_render_with_app_layout(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_active' => true]);

        foreach (['app.dashboard', 'account.edit', 'admin.users.index', 'admin.users.create'] as $route) {
            $response = $this->actingAs($admin)->get(route($route));
            $response->assertOk();
            $response->assertSee('Abmelden');
            $this->assertNoInlineCode($response->getContent(), $route);
        }
    }

    public function test_mitarbeiter_sees_no_admin_navigation(): void
    {
        $user = User::factory()->create(['role' => UserRole::Mitarbeiter, 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertDontSee(route('admin.users.index'));
    }

    public function test_error_page_renders_in_german(): void
    {
        $response = $this->get('/diese-seite-gibt-es-nicht');

        $response->assertNotFound();
        $response->assertSee('Seite');
        $this->assertNoInlineCode($response->getContent());
    }

    private function assertNoInlineCode(string $html, string $context = ''): void
    {
        $this->assertDoesNotMatchRegularExpression('/<[^>]+\sstyle\s*=/i', $html, "Inline-Style gefunden {$context}");
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, "Inline-Skript gefunden {$context}");
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $html, "Inline-Handler gefunden {$context}");
    }
}
