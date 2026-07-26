<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_admin_can_create_an_admin_with_limited_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/admin/users', [
            'first_name' => 'Review',
            'last_name' => 'Officer',
            'middle_initial' => 'A',
            'email' => 'review.officer@example.test',
            'username' => 'review.officer',
            'contact_number' => '09171234567',
            'role' => 'admin',
            'account_title' => 'Review officer',
            'permissions' => ['manage_reviews', 'manage_accounts'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $staff = User::query()->findOrFail($response->json('user.id'));

        $this->assertSame($admin->id, $staff->parent_account_id);
        $this->assertSame(['manage_reviews'], $staff->permissions);

        $this->actingAs($staff)->get('/admin/reviews')->assertOk();
        $this->actingAs($staff)->get('/admin/manage-users')->assertForbidden();
        $this->actingAs($staff)->get('/admin/logs')->assertForbidden();
    }

    public function test_delegated_admin_cannot_create_or_manage_an_admin_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $admin->id,
            'account_title' => 'Account officer',
            'permissions' => ['manage_accounts'],
        ]);

        $this->actingAs($staff)->postJson('/admin/users', [
            'first_name' => 'Another',
            'last_name' => 'Admin',
            'middle_initial' => 'B',
            'email' => 'another.admin@example.test',
            'username' => 'another.admin',
            'contact_number' => '09171234568',
            'role' => 'admin',
            'account_title' => 'Administrator',
            'permissions' => ['manage_accounts'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->actingAs($staff)
            ->getJson("/admin/users/{$admin->id}")
            ->assertForbidden();
    }

    public function test_provider_team_account_uses_organization_programs_and_enforced_permissions(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $response = $this->actingAs($provider)->postJson('/provider/team/accounts', [
            'first_name' => 'Paolo',
            'last_name' => 'Coordinator',
            'middle_initial' => 'C',
            'email' => 'paolo.coordinator@example.test',
            'username' => 'paolo.coordinator',
            'contact_number' => '09171234569',
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs', 'manage_reports'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $staff = User::query()->findOrFail($response->json('account.id'));

        $this->assertSame($provider->id, $staff->parent_account_id);
        $this->assertSame(['manage_programs'], $staff->permissions);

        $programResponse = $this->actingAs($staff)->postJson('/provider/scholarships', [
            'title' => 'Team Managed Draft',
            'status' => 'draft',
        ])->assertCreated();

        $this->assertDatabaseHas('scholarships', [
            'id' => $programResponse->json('scholarship.id'),
            'provider_id' => $provider->id,
        ]);

        $this->actingAs($staff)->get('/provider/applications')->assertForbidden();
        $this->actingAs($staff)->get('/provider/reports')->assertForbidden();
    }

    public function test_provider_can_create_a_custom_role_with_selected_permissions(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $response = $this->actingAs($provider)->postJson('/provider/team/accounts', [
            'first_name' => 'Custom',
            'last_name' => 'Staff',
            'middle_initial' => 'R',
            'email' => 'custom.staff@example.test',
            'username' => 'custom.staff',
            'contact_number' => '09171234560',
            'account_title' => 'custom',
            'permissions' => ['manage_programs', 'manage_reports'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $staff = User::query()->findOrFail($response->json('account.id'));

        $this->assertSame('custom', $staff->account_title);
        $this->assertSame(['manage_programs', 'manage_reports'], $staff->permissions);
        $this->assertSame('Custom role', $response->json('account.team_role_label'));
    }

    public function test_provider_team_account_cannot_access_another_provider_organization(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $staff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['review_applications'],
        ]);
        $otherProgram = Scholarship::create([
            'provider_id' => $otherProvider->id,
            'title' => 'Other Provider Program',
            'description' => 'This program belongs to another provider organization.',
            'status' => 'draft',
        ]);

        $this->actingAs($staff)
            ->getJson("/provider/scholarships/{$otherProgram->id}")
            ->assertForbidden();

        $this->actingAs($staff)
            ->getJson('/provider/scholarships')
            ->assertOk()
            ->assertJsonCount(0, 'scholarships');
    }

    public function test_managed_accounts_stop_when_their_primary_account_is_suspended(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $adminStaff = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $admin->id,
            'permissions' => ['manage_reviews'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $providerStaff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'permissions' => ['manage_programs'],
        ]);

        $admin->forceFill(['account_status' => 'suspended'])->save();
        $provider->forceFill(['account_status' => 'suspended'])->save();

        $this->actingAs($adminStaff)->get('/admin/reviews')->assertForbidden();
        $this->actingAs($providerStaff)->get('/provider/programs')->assertForbidden();
    }
}
