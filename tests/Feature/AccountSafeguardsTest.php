<?php

namespace Tests\Feature;

use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unverified_applicant_cannot_submit_application(): void
    {
        $applicant = User::factory()->unverified()->create();
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Verified Applicants Only',
            'description' => 'Email verification is required to apply.',
            'status' => 'published',
        ]);

        $this->actingAs($applicant)
            ->postJson('/dashboard/applications', [
                'scholarship_id' => $scholarship->id,
                'terms_accepted' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath('verification_required', true);
    }

    public function test_unverified_provider_cannot_submit_scholarship(): void
    {
        $provider = User::factory()->unverified()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $this->actingAs($provider)
            ->postJson('/provider/scholarships')
            ->assertForbidden();
    }

    public function test_expired_mobile_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $plainToken = 'expired-mobile-token';
        MobileApiToken::create([
            'user_id' => $user->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->subMinute(),
        ]);

        $this->withToken($plainToken)
            ->getJson('/api/mobile/profile')
            ->assertUnauthorized();
    }

    public function test_mobile_login_creates_an_expiring_token(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->postJson('/api/mobile/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->assertJsonStructure(['token']);

        $this->assertNotNull(MobileApiToken::first()?->expires_at);
    }
}
