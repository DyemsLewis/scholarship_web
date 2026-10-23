<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProviderAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_staff_can_open_their_account_without_organization_profile_permission(): void
    {
        [$owner, $staff] = $this->providerStaff();

        $this->actingAs($staff)
            ->get(route('provider.account'))
            ->assertOk()
            ->assertViewIs('provider-account');

        $this->actingAs($staff)
            ->getJson(route('provider.account.data'))
            ->assertOk()
            ->assertJsonPath('account.name', 'Mara Q. Santos')
            ->assertJsonPath('account.role_label', 'Application Reviewer')
            ->assertJsonPath('account.organization_name', 'Northstar Learning Foundation');

        $this->actingAs($staff)
            ->get(route('provider.profile'))
            ->assertRedirect(route('provider.index'));

        $this->assertSame($owner->id, $staff->providerOrganizationId());
    }

    public function test_provider_updates_only_their_personal_account_fields(): void
    {
        [$owner, $staff] = $this->providerStaff();

        $this->actingAs($staff)
            ->patchJson(route('provider.account.update'), [
                'first_name' => 'Maria',
                'last_name' => 'Santos-Reyes',
                'middle_initial' => 'L',
                'email' => $staff->email,
                'username' => 'maria.santos',
                'contact_number' => '09171234567',
                'current_password' => 'password123',
            ])
            ->assertOk()
            ->assertJsonPath('account.name', 'Maria L. Santos-Reyes')
            ->assertJsonPath('account.username', 'maria.santos');

        $staff->refresh()->load('providerProfile');
        $owner->refresh()->load('providerProfile');

        $this->assertSame('Maria', $staff->providerProfile->first_name);
        $this->assertSame('09171234567', $staff->providerProfile->contact_number);
        $this->assertSame('Northstar Learning Foundation', $staff->providerProfile->provider_name);
        $this->assertSame('Northstar Learning Foundation', $owner->providerProfile->provider_name);
    }

    public function test_provider_password_change_requires_the_current_password(): void
    {
        [, $staff] = $this->providerStaff();

        $this->actingAs($staff)
            ->patchJson(route('provider.account.password.update'), [
                'current_password' => 'incorrect-password',
                'password' => 'updated-password-123',
                'password_confirmation' => 'updated-password-123',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->actingAs($staff)
            ->patchJson(route('provider.account.password.update'), [
                'current_password' => 'password123',
                'password' => 'updated-password-123',
                'password_confirmation' => 'updated-password-123',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.');

        $this->assertTrue(Hash::check('updated-password-123', $staff->fresh()->password));
    }

    private function providerStaff(): array
    {
        $owner = User::factory()->create([
            'role' => 'provider',
            'email' => 'owner@northstar.test',
            'username' => 'northstar.owner',
            'password' => 'password123',
        ]);
        $owner->providerProfile()->updateOrCreate(['user_id' => $owner->id], [
            'first_name' => 'Lina',
            'last_name' => 'Reyes',
            'middle_initial' => 'D',
            'contact_number' => '09170000001',
            'provider_name' => 'Northstar Learning Foundation',
        ]);

        $staff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['verify_applications'],
            'email' => 'reviewer@northstar.test',
            'username' => 'northstar.reviewer',
            'password' => 'password123',
        ]);
        $staff->providerProfile()->updateOrCreate(['user_id' => $staff->id], [
            'first_name' => 'Mara',
            'last_name' => 'Santos',
            'middle_initial' => 'Q',
            'contact_number' => '09170000002',
            'provider_name' => 'Northstar Learning Foundation',
        ]);

        return [$owner, $staff];
    }
}
