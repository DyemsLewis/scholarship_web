<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_admin_area(): void
    {
        $this->get('/admin')
            ->assertRedirect('/login');
    }

    public function test_non_admin_cannot_access_admin_area(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs($provider)
            ->getJson('/admin/users')
            ->assertForbidden();
    }

    public function test_admin_can_access_admin_area(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_admin_can_access_role_specific_review_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($admin)
            ->get("/admin/providers/{$provider->id}/review")
            ->assertOk();

        $this->actingAs($admin)
            ->getJson("/admin/providers/{$provider->id}/review/data")
            ->assertOk()
            ->assertJsonPath('provider.id', $provider->id)
            ->assertJsonPath('provider.role', 'provider');

        $this->actingAs($admin)
            ->get("/admin/applicants/{$applicant->id}/review")
            ->assertOk();

        $this->actingAs($admin)
            ->getJson("/admin/applicants/{$applicant->id}/review/data")
            ->assertOk()
            ->assertJsonPath('applicant.id', $applicant->id)
            ->assertJsonPath('applicant.role', 'applicant');

        $this->actingAs($admin)
            ->get("/admin/providers/{$applicant->id}/review")
            ->assertNotFound();

        $this->actingAs($admin)
            ->get("/admin/applicants/{$provider->id}/review")
            ->assertNotFound();

        $this->actingAs($provider)
            ->get("/admin/providers/{$provider->id}/review")
            ->assertForbidden();
    }

    public function test_provider_area_requires_provider_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($admin)
            ->get('/provider')
            ->assertForbidden();

        $this->actingAs($provider)
            ->get('/provider')
            ->assertOk();
    }
}
