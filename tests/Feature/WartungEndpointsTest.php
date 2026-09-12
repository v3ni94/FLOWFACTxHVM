<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class WartungEndpointsTest extends TestCase
{
    // ------------------------------------------------------------------
    // /wartung/schedule
    // ------------------------------------------------------------------

    public function test_schedule_endpoint_is_disabled_without_configured_token(): void
    {
        config(['deploy.cron_schedule_token' => '']);

        $this->postJson('/wartung/schedule', [], ['X-Cron-Token' => 'irgendein-wert'])
            ->assertStatus(404);
    }

    public function test_schedule_endpoint_rejects_wrong_token(): void
    {
        config(['deploy.cron_schedule_token' => 'geheimer-wert']);

        $this->postJson('/wartung/schedule', [], ['X-Cron-Token' => 'falscher-wert'])
            ->assertStatus(403);
    }

    public function test_schedule_endpoint_rejects_missing_token(): void
    {
        config(['deploy.cron_schedule_token' => 'geheimer-wert']);

        $this->postJson('/wartung/schedule', [])
            ->assertStatus(403);
    }

    public function test_schedule_endpoint_runs_scheduler_with_correct_token(): void
    {
        config(['deploy.cron_schedule_token' => 'geheimer-wert']);

        $response = $this->postJson('/wartung/schedule', [], ['X-Cron-Token' => 'geheimer-wert']);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertArrayHasKey('output', $response->json());
    }

    public function test_schedule_endpoint_rejects_get_without_token(): void
    {
        config(['deploy.cron_schedule_token' => 'geheimer-wert']);

        $this->get('/wartung/schedule')->assertStatus(403);
    }

    public function test_schedule_endpoint_accepts_get_with_token_parameter(): void
    {
        config(['deploy.cron_schedule_token' => 'geheimer-wert']);

        $this->get('/wartung/schedule?token=geheimer-wert')
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_schedule_endpoint_rejects_get_with_wrong_token_parameter(): void
    {
        config(['deploy.cron_schedule_token' => 'geheimer-wert']);

        $this->get('/wartung/schedule?token=falsch')->assertStatus(403);
    }

    // ------------------------------------------------------------------
    // /wartung/install
    // ------------------------------------------------------------------

    public function test_install_endpoint_is_disabled_without_configured_token(): void
    {
        config(['deploy.cron_install_token' => '']);

        $this->postJson('/wartung/install', [], ['X-Cron-Token' => 'irgendein-wert'])
            ->assertStatus(404);
    }

    public function test_install_endpoint_rejects_wrong_token(): void
    {
        config(['deploy.cron_install_token' => 'geheimer-wert']);

        $this->postJson('/wartung/install', [], ['X-Cron-Token' => 'falscher-wert'])
            ->assertStatus(403);
    }

    public function test_install_endpoint_runs_install_with_correct_token(): void
    {
        config(['deploy.cron_install_token' => 'geheimer-wert']);

        $response = $this->postJson('/wartung/install', [], ['X-Cron-Token' => 'geheimer-wert']);

        $response->assertOk();
        $this->assertArrayHasKey('success', $response->json());
        $this->assertArrayHasKey('output', $response->json());
    }

    public function test_install_endpoint_rejects_get_without_token(): void
    {
        config(['deploy.cron_install_token' => 'geheimer-wert']);

        $this->get('/wartung/install')->assertStatus(403);
    }

    public function test_install_endpoint_accepts_get_with_token_parameter(): void
    {
        config(['deploy.cron_install_token' => 'geheimer-wert']);

        $response = $this->get('/wartung/install?token=geheimer-wert');

        $response->assertOk();
        $this->assertArrayHasKey('success', $response->json());
    }
}
