<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderGovernanceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_owner_and_staff_enter_the_reset_provider_shell(): void
    {
        $owner = $this->governanceOwner();
        $staff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'permissions' => ['manage_programs'],
        ]);

        $this->actingAs($owner)
            ->get('/provider')
            ->assertOk()
            ->assertViewIs('provider');

        $this->actingAs($staff)
            ->get('/provider')
            ->assertOk()
            ->assertViewIs('provider-program-coordinator-dashboard');

        $this->actingAs($staff)->get('/provider/governance')->assertForbidden();
        $this->actingAs($staff)->getJson('/provider/governance/data')->assertForbidden();
    }

    public function test_governance_owner_controls_profile_and_team_without_automatic_operational_access(): void
    {
        $owner = $this->governanceOwner();
        $payload = $owner->fresh('providerProfile')->publicPayload();

        $this->assertSame(User::PROVIDER_GOVERNANCE_PERMISSIONS, $payload['permissions']);
        $this->assertSame('governance', $payload['provider_operating_mode']);
        $this->assertFalse($payload['has_full_access']);
        $this->assertTrue($payload['is_organization_owner']);

        $this->actingAs($owner)
            ->get('/provider/team')
            ->assertRedirect('/provider/workspaces/team');
        $this->actingAs($owner)
            ->get('/provider/profile/details')
            ->assertRedirect('/provider/workspaces/organization-profile/public');
        $this->actingAs($owner)->get('/provider/programs')->assertForbidden();
        $this->actingAs($owner)->get('/provider/applications')->assertForbidden();
        $this->actingAs($owner)->get('/provider/monitoring')->assertForbidden();

        $this->actingAs($owner)
            ->getJson('/provider/team/data')
            ->assertOk()
            ->assertJsonPath('available_permissions', User::PROVIDER_PERMISSIONS);
    }

    public function test_governance_data_reports_role_coverage_and_access_health(): void
    {
        $owner = $this->governanceOwner();
        User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs'],
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/provider/governance/data')
            ->assertOk()
            ->assertJsonPath('operating_mode', 'governance')
            ->assertJsonPath('summary.active_staff_count', 1)
            ->assertJsonPath('summary.coverage_count', 1)
            ->assertJsonPath('summary.coverage_total', 8)
            ->assertJsonPath('summary.gap_count', 7);

        $coverage = collect($response->json('coverage'));

        $this->assertSame('covered', $coverage->firstWhere('permission', 'manage_programs')['status']);
        $this->assertSame('unassigned', $coverage->firstWhere('permission', 'verify_applications')['status']);
    }

    public function test_dashboard_actions_follow_actual_permissions_without_granting_program_access(): void
    {
        foreach ([
            [User::PROVIDER_GOVERNANCE_PERMISSIONS, '/provider/workspaces/team'],
            [['manage_profile'], '/provider/workspaces/organization-profile'],
            [['manage_team'], '/provider/workspaces/team'],
            [User::PROVIDER_PERMISSIONS, '/provider/programs'],
            [['verify_applications', 'manage_selection_activities'], '/provider/workspaces/reviews/all'],
        ] as [$permissions, $expected]) {
            $owner = User::factory()->create(['role' => 'provider', 'permissions' => $permissions]);
            $response = $this->actingAs($owner)->getJson('/provider/dashboard/data')
                ->assertOk()
                ->assertJsonPath('manager_overview.primary_action.href', $expected)
                ->assertJsonPath('manager_overview.priority_action.href', $expected);

            $this->get($response->json('manager_overview.primary_action.href'))->assertStatus(
                in_array($expected, ['/provider/workspaces/team', '/provider/workspaces/organization-profile'], true) ? 302 : 200,
            );
            $this->assertSame($permissions, $owner->fresh()->permissions);
            if (! in_array('manage_programs', $permissions, true)) {
                $this->get('/provider/programs?page=1')->assertForbidden();
            } else {
                $this->get('/provider/programs?page=1')->assertOk();
            }
        }
    }

    public function test_unapproved_operational_staff_receive_a_profile_link_not_a_blocked_queue(): void
    {
        $owner = $this->governanceOwner();
        $owner->providerProfile()->update(['verification_status' => 'pending']);
        $staff = User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $owner->id,
            'permissions' => ['verify_applications', 'manage_selection_activities'],
        ]);

        $this->actingAs($staff)->getJson('/provider/dashboard/data')->assertOk()
            ->assertJsonPath('manager_overview.primary_action.href', '/provider/account')
            ->assertJsonPath('manager_overview.priority_action.href', '/provider/account');
        $this->get('/provider/account')->assertOk();
        $this->get('/provider/workspaces/reviews/all')->assertForbidden();
    }

    public function test_owner_can_explicitly_switch_between_solo_and_governance_models(): void
    {
        $owner = $this->governanceOwner();

        $this->actingAs($owner)
            ->patchJson('/provider/governance/operating-mode', [
                'mode' => 'solo_operator',
                'current_password' => 'incorrect-password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->actingAs($owner)
            ->patchJson('/provider/governance/operating-mode', [
                'mode' => 'solo_operator',
                'current_password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('user.provider_operating_mode', 'solo_operator')
            ->assertJsonPath('user.has_full_access', true);

        $this->assertSame(User::PROVIDER_PERMISSIONS, $owner->fresh()->permissions);
        $this->actingAs($owner->fresh())->get('/provider/programs')->assertOk();

        $this->actingAs($owner->fresh())
            ->patchJson('/provider/governance/operating-mode', [
                'mode' => 'governance',
                'current_password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('user.provider_operating_mode', 'governance')
            ->assertJsonPath('user.has_full_access', false);

        $this->assertSame(User::PROVIDER_GOVERNANCE_PERMISSIONS, $owner->fresh()->permissions);
        $this->actingAs($owner->fresh())->get('/provider/programs')->assertForbidden();
    }

    private function governanceOwner(): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'permissions' => [],
        ]);
    }
}
