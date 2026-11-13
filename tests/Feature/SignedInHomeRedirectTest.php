<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignedInHomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_keep_the_landing_and_login_pages(): void
    {
        $this->get('/')->assertOk()->assertViewIs('landing');
        $this->get('/login')->assertOk()->assertViewIs('login');
        $this->assertGuest();
    }

    public function test_signed_in_applicant_opening_home_goes_directly_to_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'))
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/login')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_existing_session_is_reused_for_home_after_login_without_logging_in_again(): void
    {
        $user = User::factory()->create(['password' => 'password123']);
        $this->postJson('/login', ['login' => $user->username, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('redirect', '/dashboard');
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->get('/')->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_provider_and_admin_home_redirects_are_unchanged(): void
    {
        foreach (['provider' => 'provider.index', 'admin' => 'admin.index'] as $role => $route) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get('/')->assertRedirect(route($route));
        }
    }
}
