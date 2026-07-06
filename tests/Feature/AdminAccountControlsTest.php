<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_suspend_and_reactivate_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create([
            'email' => 'student@example.com',
            'role' => 'applicant',
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/status", [
                'account_status' => 'suspended',
                'suspension_reason' => 'Support review.',
            ])
            ->assertOk()
            ->assertJsonPath('user.account_status', 'suspended');

        $this->postJson('/login', [
            'email' => 'student@example.com',
            'password' => 'password',
        ])->assertForbidden();

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/status", [
                'account_status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('user.account_status', 'active');
    }

    public function test_admin_can_force_password_reset_and_reset_clears_flag(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create([
            'email' => 'resetme@example.com',
            'role' => 'applicant',
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/admin/users/{$applicant->id}/force-password-reset")
            ->assertOk()
            ->assertJsonPath('user.must_reset_password', true);

        $resetUrl = $response->json('reset_url');
        $this->assertNotEmpty($resetUrl);

        $loginResponse = $this->postJson('/login', [
            'email' => 'resetme@example.com',
            'password' => 'password',
        ])->assertStatus(423);
        $resetUrl = $loginResponse->json('reset_url') ?: $resetUrl;

        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        $this->postJson('/reset-password', [
            'email' => $query['email'],
            'token' => $query['token'],
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertFalse($applicant->fresh()->must_reset_password);

        $this->postJson('/login', [
            'email' => 'resetme@example.com',
            'password' => 'new-password',
        ])->assertOk();
    }

    public function test_admin_can_resend_and_manually_verify_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->unverified()->create(['role' => 'applicant']);

        $this->actingAs($admin)
            ->postJson("/admin/users/{$applicant->id}/verification-email")
            ->assertOk()
            ->assertJsonPath('email_verification_sent', true);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/email-verification")
            ->assertOk()
            ->assertJsonPath('user.email_verified', true);

        $this->assertTrue($applicant->fresh()->hasVerifiedEmail());
    }

    public function test_last_active_admin_cannot_be_suspended_or_demoted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$admin->id}/status", [
                'account_status' => 'suspended',
                'suspension_reason' => 'No admins left.',
            ])
            ->assertStatus(422);

        $profile = $admin->adminProfile;

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$admin->id}", [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'middle_initial' => $profile->middle_initial,
                'email' => $admin->email,
                'username' => $admin->username,
                'contact_number' => $profile->contact_number,
                'role' => 'applicant',
            ])
            ->assertStatus(422);
    }
}
