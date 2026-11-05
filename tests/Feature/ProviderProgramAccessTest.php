<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderProgramAccessTest extends TestCase
{
    use RefreshDatabase;

    private function staff(User $owner, string $role = 'program_coordinator'): User
    {
        return User::factory()->create(['role' => 'provider', 'parent_account_id' => $owner->id,
            'account_title' => $role, 'permissions' => $role === 'manager' ? User::PROVIDER_PERMISSIONS : ['manage_programs']]);
    }

    public function test_pending_organizations_show_a_clear_gate_for_owners_managers_and_coordinators(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update(['verification_status' => 'pending']);
        foreach ([$owner, $this->staff($owner, 'manager'), $this->staff($owner)] as $actor) {
            $this->actingAs($actor)->get('/provider/programs/create')->assertOk()
                ->assertViewIs('provider-program-access')
                ->assertViewHas('programAccess', fn ($access) => $access['verification_status'] === 'pending'
                    && $access['can_manage_profile'] === $actor->hasPortalPermission('manage_profile'))
                ->assertSee('providerProgramAccess', false);
        }
    }

    public function test_verified_organizations_keep_the_builder_available_to_each_authorized_role(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update(['verification_status' => 'approved']);
        foreach ([$owner, $this->staff($owner, 'manager'), $this->staff($owner)] as $actor) {
            $this->actingAs($actor)->get('/provider/programs/create')->assertOk()->assertViewIs('provider-program-form');
        }
    }

    public function test_the_organization_email_not_the_staff_email_controls_access(): void
    {
        $owner = User::factory()->unverified()->create(['role' => 'provider']);
        $owner->providerProfile()->update(['verification_status' => 'approved']);
        $staff = $this->staff($owner);
        $this->actingAs($staff)->get('/provider/programs/create')->assertOk()
            ->assertViewIs('provider-program-access')->assertViewHas('programAccess', fn ($access) => ! $access['email_verified']);
    }

    public function test_rejected_organizations_see_the_correction_state_and_cannot_bypass_the_api_gate(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update(['verification_status' => 'rejected']);
        $this->actingAs($owner)->get('/provider/programs/create')->assertOk()
            ->assertViewIs('provider-program-access')->assertViewHas('programAccess', fn ($access) => $access['verification_status'] === 'rejected');
        $this->actingAs($owner)->postJson('/provider/scholarships', ['title' => 'Cannot bypass verification', 'status' => 'draft'])->assertForbidden();
        $this->assertDatabaseCount('scholarships', 0);
    }

    public function test_other_roles_do_not_gain_create_access(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = User::factory()->create(['role' => 'provider', 'parent_account_id' => $owner->id, 'permissions' => ['verify_applications']]);
        $this->actingAs($reviewer)->get('/provider/programs/create')->assertForbidden();
    }

    public function test_unassigned_editing_remains_forbidden_even_when_organization_verification_is_pending(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update(['verification_status' => 'pending']);
        $staff = $this->staff($owner);
        $staff->update(['assigned_program_ids' => []]);
        $program = Scholarship::create(['provider_id' => $owner->id, 'title' => 'Unassigned draft', 'description' => 'Draft access test', 'status' => 'draft']);
        $this->actingAs($staff)->get('/provider/programs/'.$program->id.'/edit/basics')->assertForbidden();
    }
}
