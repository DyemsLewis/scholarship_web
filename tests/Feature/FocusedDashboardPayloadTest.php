<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FocusedDashboardPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_returns_only_dashboard_summary_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $payload = $this->actingAs($admin)
            ->getJson('/admin/dashboard/data')
            ->assertOk()
            ->assertJsonStructure([
                'stats' => [
                    'pending_providers',
                    'documents_pending_review',
                    'documents_needing_replacement',
                    'upcoming_deadlines',
                    'expired_published',
                    'needs_review_applications',
                ],
                'recent_users',
                'recent_scholarships',
            ])
            ->json();

        $this->assertArrayNotHasKey('monthly_applications', $payload);
        $this->assertArrayNotHasKey('provider_performance', $payload);
        $this->assertArrayNotHasKey('notifications', $payload);
    }

    public function test_provider_dashboard_and_profile_endpoints_return_page_specific_data(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $dashboard = $this->actingAs($provider)
            ->getJson('/provider/dashboard/data')
            ->assertOk()
            ->assertJsonStructure(['user', 'scholarships', 'review_queue'])
            ->json();

        $this->assertArrayNotHasKey('stats', $dashboard);
        $this->assertArrayNotHasKey('notifications', $dashboard);
        $this->assertArrayNotHasKey('verification_documents', $dashboard);

        $profile = $this->actingAs($provider)
            ->getJson('/provider/profile/data')
            ->assertOk()
            ->assertJsonStructure(['user', 'verification_documents'])
            ->json();

        $this->assertArrayNotHasKey('scholarships', $profile);
        $this->assertArrayNotHasKey('review_queue', $profile);
        $this->assertArrayNotHasKey('stats', $profile);
        $this->assertArrayNotHasKey('notifications', $profile);
    }
}
