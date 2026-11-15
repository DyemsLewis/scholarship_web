<?php

namespace Tests\Feature;

use App\Models\ProviderProfile;
use App\Models\ProviderWorkspaceGuideProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderWorkspaceGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_is_read_only_and_scoped_to_the_logged_in_account(): void
    {
        $staff = $this->coordinator();
        $this->actingAs($staff)->getJson('/provider/workspace-guides/programs')
            ->assertOk()->assertJsonPath('auto_offer', true)->assertJsonPath('status', null)
            ->assertJsonPath('guide.version', 1)->assertJsonCount(4, 'guide.steps')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('provider_workspace_guide_progress', 0);
        $this->postJson('/provider/workspace-guides/programs/progress', ['version' => 1, 'status' => 'dismissed'])
            ->assertOk()->assertJsonPath('status', 'dismissed');
        $this->getJson('/provider/workspace-guides/programs')->assertJsonPath('auto_offer', false);
        $this->actingAs($this->coordinator())->getJson('/provider/workspace-guides/programs')->assertJsonPath('auto_offer', true);
        $this->assertDatabaseCount('provider_workspace_guide_progress', 1);
        $this->assertDatabaseHas('provider_workspace_guide_progress', ['user_id' => $staff->id, 'status' => 'dismissed']);
    }

    public function test_completion_is_idempotent_and_late_seen_or_dismissed_requests_do_not_undo_it(): void
    {
        $this->actingAs($this->coordinator());
        foreach (['seen', 'dismissed', 'completed', 'seen', 'dismissed', 'completed'] as $status) {
            $this->postJson('/provider/workspace-guides/programs/progress', ['version' => 1, 'status' => $status])->assertOk();
        }
        $this->assertDatabaseCount('provider_workspace_guide_progress', 1);
        $this->getJson('/provider/workspace-guides/programs')->assertJsonPath('status', 'completed')->assertJsonPath('auto_offer', false);
    }

    public function test_permissions_account_status_and_guide_keys_are_enforced(): void
    {
        $this->getJson('/provider/workspace-guides/programs')->assertUnauthorized();
        foreach (['applicant', 'admin'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->getJson('/provider/workspace-guides/programs')->assertForbidden();
        }
        $reviewer = $this->coordinator();
        $reviewer->update(['account_title' => 'application_reviewer', 'permissions' => ['verify_applications']]);
        $this->actingAs($reviewer)->getJson('/provider/workspace-guides/programs')->assertForbidden();
        $this->postJson('/provider/workspace-guides/programs/progress', ['version' => 1, 'status' => 'completed'])->assertForbidden();
        $staff = $this->coordinator();
        $this->actingAs($staff)->getJson('/provider/workspace-guides/not-a-workspace')->assertNotFound();
        $staff->update(['account_status' => 'suspended']);
        $this->getJson('/provider/workspace-guides/programs')->assertForbidden();
        $this->assertDatabaseCount('provider_workspace_guide_progress', 0);
    }

    public function test_progress_rejects_stale_versions_invalid_statuses_and_another_user_id(): void
    {
        $this->actingAs($this->coordinator());
        foreach ([['version' => 0, 'status' => 'seen'], ['version' => 1, 'status' => 'published'], ['version' => 1, 'status' => 'seen', 'user_id' => 999]] as $data) {
            $this->postJson('/provider/workspace-guides/programs/progress', $data)->assertUnprocessable();
        }
        $this->assertDatabaseCount('provider_workspace_guide_progress', 0);
    }

    public function test_managers_and_owners_can_read_the_guide_without_automatic_popups(): void
    {
        $manager = $this->coordinator();
        $manager->update(['account_title' => 'manager']);
        $this->actingAs($manager)->getJson('/provider/workspace-guides/programs')->assertOk()->assertJsonPath('auto_offer', false);
        $owner = User::factory()->create(['role' => 'provider', 'permissions' => ['manage_programs']]);
        $this->actingAs($owner)->getJson('/provider/workspace-guides/programs')->assertOk()->assertJsonPath('auto_offer', false);
    }

    public function test_new_versions_remain_on_demand_for_accounts_that_already_used_the_guide(): void
    {
        $staff = $this->coordinator();
        ProviderWorkspaceGuideProgress::query()->create(['user_id' => $staff->id, 'workspace_key' => 'programs', 'guide_version' => 0, 'status' => 'dismissed']);
        $this->actingAs($staff)->getJson('/provider/workspace-guides/programs')->assertOk()->assertJsonPath('status', null)->assertJsonPath('auto_offer', false);
    }

    public function test_prerequisites_reflect_the_organization_not_the_staff_profile_without_changing_business_records(): void
    {
        $staff = $this->coordinator();
        $staff->update(['assigned_program_ids' => []]);
        ProviderProfile::query()->updateOrCreate(['user_id' => $staff->parent_account_id], ['verification_status' => 'pending']);
        $this->actingAs($staff)->getJson('/provider/workspace-guides/programs')->assertJsonPath('guide.ready', false)
            ->assertJsonFragment(['scope_note' => 'You only see programs assigned to your account. Ask your manager if a program is missing.']);
        ProviderProfile::query()->where('user_id', $staff->parent_account_id)->update(['verification_status' => 'approved']);
        $before = User::query()->get()->toJson();
        $this->getJson('/provider/workspace-guides/programs')->assertJsonPath('guide.ready', true);
        $this->postJson('/provider/workspace-guides/programs/progress', ['version' => 1, 'status' => 'completed'])->assertOk();
        $this->assertSame($before, User::query()->get()->toJson());
        $this->assertDatabaseCount('scholarships', 0);
        $this->assertDatabaseCount('scholarship_applications', 0);
        $this->assertDatabaseHas('provider_profiles', ['user_id' => $staff->parent_account_id, 'verification_status' => 'approved']);
    }

    public function test_operational_guides_require_their_own_permissions_and_track_each_staff_account(): void
    {
        foreach ([
            'reviews' => ['application_reviewer', 'verify_applications', 'Application Review workspace'],
            'selection' => ['selection_officer', 'manage_selection_activities', 'Selection Activities workspace'],
            'decisions' => ['decision_officer', 'record_final_decisions', 'Final Decisions workspace'],
            'recipients' => ['recipient_officer', 'manage_recipients', 'Recipients workspace'],
            'monitoring' => ['monitoring_officer', 'manage_monitoring', 'Monitoring workspace'],
            'releases' => ['benefit_release_officer', 'manage_benefit_releases', 'Benefit Releases workspace'],
            'organization-profile' => ['organization_profile_manager', 'manage_profile', 'Organization Profile workspace'],
            'team' => ['team_administrator', 'manage_team', 'Team Access workspace'],
            'support' => ['support_staff', 'manage_reports', 'Support workspace'],
            'billing' => ['billing_staff', 'manage_billing', 'Platform Services workspace'],
        ] as $workspace => [$title, $permission, $heading]) {
            $staff = $this->coordinator();
            $staff->update(['account_title' => $title, 'permissions' => [$permission]]);
            $this->actingAs($staff)->getJson("/provider/workspace-guides/{$workspace}")
                ->assertOk()->assertJsonPath('guide.title', $heading)->assertJsonPath('auto_offer', true)
                ->assertJsonCount(4, 'guide.steps')->assertJsonPath('guide.ready', true);
            $other = $workspace === 'reviews' ? 'selection' : 'reviews';
            $this->getJson("/provider/workspace-guides/{$other}")->assertForbidden();
            $this->postJson("/provider/workspace-guides/{$other}/progress", ['version' => 1, 'status' => 'seen'])->assertForbidden();
            foreach (['seen', 'dismissed', 'completed', 'seen'] as $status) {
                $this->postJson("/provider/workspace-guides/{$workspace}/progress", ['version' => 1, 'status' => $status])->assertOk();
            }
            $this->getJson("/provider/workspace-guides/{$workspace}")->assertJsonPath('status', 'completed')->assertJsonPath('auto_offer', false);
        }
        $this->assertDatabaseCount('provider_workspace_guide_progress', 10);
        $this->assertDatabaseCount('scholarships', 0);
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_multi_workspace_managers_keep_separate_progress_without_automatic_guides(): void
    {
        $manager = $this->coordinator();
        $manager->update(['account_title' => 'Provider Manager', 'permissions' => ['manage_programs', 'verify_applications', 'manage_selection_activities', 'record_final_decisions', 'manage_recipients', 'manage_monitoring', 'manage_benefit_releases', 'manage_profile', 'manage_team', 'manage_reports', 'manage_billing']]);
        $this->actingAs($manager);
        foreach (['programs', 'reviews', 'selection', 'decisions', 'recipients', 'monitoring', 'releases', 'organization-profile', 'team', 'support', 'billing'] as $workspace) {
            $this->getJson("/provider/workspace-guides/{$workspace}")->assertOk()->assertJsonPath('auto_offer', false)->assertJsonPath('status', null);
        }
        $this->postJson('/provider/workspace-guides/reviews/progress', ['version' => 1, 'status' => 'completed'])->assertOk();
        $this->getJson('/provider/workspace-guides/selection')->assertJsonPath('status', null);
        $this->getJson('/provider/workspace-guides/programs')->assertJsonPath('status', null);
        $this->postJson('/provider/workspace-guides/decisions/progress', ['version' => 1, 'status' => 'completed'])->assertOk();
        $this->getJson('/provider/workspace-guides/recipients')->assertJsonPath('status', null);
        $this->postJson('/provider/workspace-guides/monitoring/progress', ['version' => 1, 'status' => 'completed'])->assertOk();
        $this->getJson('/provider/workspace-guides/releases')->assertJsonPath('status', null);
        $this->postJson('/provider/workspace-guides/organization-profile/progress', ['version' => 1, 'status' => 'completed'])->assertOk();
        $this->getJson('/provider/workspace-guides/team')->assertJsonPath('status', null);
        $this->postJson('/provider/workspace-guides/selection/progress', ['version' => 9, 'status' => 'completed'])->assertUnprocessable();
        $manager->update(['permissions' => ['manage_programs']]);
        $this->getJson('/provider/workspace-guides/reviews')->assertForbidden();
        $this->assertDatabaseCount('provider_workspace_guide_progress', 4);
    }

    public function test_guides_explain_pending_approval_without_bypassing_workspace_approval_or_permissions(): void
    {
        $staff = $this->coordinator();
        $staff->update(['account_title' => 'application_reviewer', 'permissions' => ['verify_applications', 'manage_selection_activities', 'record_final_decisions', 'manage_recipients', 'manage_monitoring', 'manage_benefit_releases', 'manage_reports', 'manage_billing']]);
        ProviderProfile::query()->where('user_id', $staff->parent_account_id)->update(['verification_status' => 'pending']);
        $this->actingAs($staff);
        foreach (['reviews', 'selection', 'decisions', 'recipients', 'monitoring', 'releases', 'support', 'billing'] as $workspace) {
            $this->getJson("/provider/workspace-guides/{$workspace}")->assertOk()->assertJsonPath('guide.ready', false);
            $this->getJson("/provider/workspaces/{$workspace}/data")->assertForbidden();
        }
        $staff->update(['permissions' => []]);
        $this->getJson('/provider/workspace-guides/reviews')->assertForbidden();
        $this->getJson('/provider/workspace-guides/future-workspace')->assertNotFound();
        $this->assertDatabaseCount('provider_workspace_guide_progress', 0);
    }

    public function test_decision_and_recipient_guides_do_not_grant_each_others_access_or_change_awards(): void
    {
        $staff = $this->coordinator();
        $staff->update(['account_title' => 'decision_officer', 'permissions' => ['record_final_decisions']]);
        $this->actingAs($staff)->getJson('/provider/workspace-guides/decisions')->assertOk()->assertJsonPath('auto_offer', true);
        $this->getJson('/provider/workspace-guides/recipients')->assertForbidden();
        $this->postJson('/provider/workspace-guides/recipients/progress', ['version' => 1, 'status' => 'completed'])->assertForbidden();
        $staff->update(['account_title' => 'recipient_officer', 'permissions' => ['manage_recipients']]);
        $this->getJson('/provider/workspace-guides/recipients')->assertOk()->assertJsonPath('auto_offer', true);
        $this->getJson('/provider/workspace-guides/decisions')->assertForbidden();
        $this->postJson('/provider/workspace-guides/decisions/progress', ['version' => 1, 'status' => 'completed'])->assertForbidden();
        $this->postJson('/provider/workspace-guides/recipients/progress', ['version' => 1, 'status' => 'completed', 'user_id' => $staff->parent_account_id])->assertUnprocessable();
        $this->postJson('/provider/workspace-guides/recipients/progress', ['version' => 0, 'status' => 'completed'])->assertUnprocessable();
        $this->assertDatabaseCount('provider_workspace_guide_progress', 0);
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_monitoring_and_release_guides_keep_planning_payment_and_case_permissions_separate(): void
    {
        $staff = $this->coordinator();
        $this->actingAs($staff)->getJson('/provider/workspace-guides/monitoring')->assertForbidden();
        foreach ([
            'monitoring' => ['monitoring_officer', 'manage_monitoring', 'releases'],
            'releases' => ['benefit_release_officer', 'manage_benefit_releases', 'monitoring'],
        ] as $workspace => [$title, $permission, $other]) {
            $staff->update(['account_title' => $title, 'permissions' => [$permission]]);
            $this->getJson("/provider/workspace-guides/{$workspace}")->assertOk()->assertJsonPath('auto_offer', true);
            $this->getJson("/provider/workspace-guides/{$other}")->assertForbidden();
            $this->postJson("/provider/workspace-guides/{$other}/progress", ['version' => 1, 'status' => 'completed'])->assertForbidden();
            $this->postJson("/provider/workspace-guides/{$workspace}/progress", ['version' => 1, 'status' => 'completed', 'user_id' => $staff->parent_account_id])->assertUnprocessable();
            $this->postJson("/provider/workspace-guides/{$workspace}/progress", ['version' => 0, 'status' => 'completed'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('provider_workspace_guide_progress', 0);
        $this->assertDatabaseCount('recipient_monitoring_cycles', 0);
        $this->assertDatabaseCount('recipient_benefit_releases', 0);
    }

    public function test_governance_guides_explain_work_that_is_allowed_before_organization_approval(): void
    {
        $staff = $this->coordinator();
        $staff->update(['assigned_program_ids' => []]);
        User::query()->whereKey($staff->parent_account_id)->update(['email_verified_at' => null]);
        ProviderProfile::query()->where('user_id', $staff->parent_account_id)->update(['verification_status' => 'pending']);
        foreach ([
            'organization-profile' => ['organization_profile_manager', 'manage_profile', 'You can complete this profile while approval is pending.'],
            'team' => ['team_administrator', 'manage_team', 'You can prepare staff access while organization approval is pending.'],
        ] as $workspace => [$title, $permission, $instruction]) {
            $staff->update(['account_title' => $title, 'permissions' => [$permission]]);
            $this->actingAs($staff);
            $before = User::query()->get()->toJson();
            $guide = $this->getJson("/provider/workspace-guides/{$workspace}")->assertOk()
                ->assertJsonPath('auto_offer', true)->assertJsonPath('guide.ready', false);
            $this->assertStringStartsWith($instruction, $guide->json('guide.prerequisite'));
            $this->assertStringNotContainsString('You only see programs assigned', $guide->json('guide.scope_note'));
            $this->getJson("/provider/workspaces/{$workspace}/data")->assertOk();
            $other = $workspace === 'team' ? 'organization-profile' : 'team';
            $this->getJson("/provider/workspace-guides/{$other}")->assertForbidden();
            $this->postJson("/provider/workspace-guides/{$other}/progress", ['version' => 1, 'status' => 'completed'])->assertForbidden();
            $this->postJson("/provider/workspace-guides/{$workspace}/progress", ['version' => 1, 'status' => 'completed'])->assertOk();
            $this->assertSame($before, User::query()->get()->toJson());
        }
        $this->assertDatabaseHas('provider_profiles', ['user_id' => $staff->parent_account_id, 'verification_status' => 'pending']);
        $this->assertDatabaseCount('provider_workspace_guide_progress', 2);
        $this->assertDatabaseCount('scholarships', 0);
    }

    public function test_main_dashboard_guide_matches_real_routing_and_does_not_grant_workspace_access(): void
    {
        $manager = $this->coordinator();
        $manager->update(['account_title' => 'Provider Manager', 'permissions' => ['manage_programs', 'manage_reports']]);
        $owner = User::factory()->create(['role' => 'provider', 'permissions' => []]);
        $custom = $this->coordinator();
        $custom->update(['account_title' => 'Operations lead', 'permissions' => ['manage_reports', 'manage_billing']]);
        foreach ([$manager, $owner, $custom] as $user) {
            $before = $user->fresh()->toJson();
            $this->actingAs($user)->getJson('/provider/workspace-guides/manager')
                ->assertOk()->assertJsonPath('auto_offer', true)->assertJsonCount(4, 'guide.steps');
            $this->postJson('/provider/workspace-guides/manager/progress', ['version' => 1, 'status' => 'completed'])->assertOk();
            $this->getJson('/provider/workspace-guides/manager')->assertJsonPath('auto_offer', false)->assertJsonPath('status', 'completed');
            $this->getJson('/provider/workspace-guides/decisions')->assertForbidden();
            $this->assertSame($before, $user->fresh()->toJson());
        }
        $dedicated = $this->coordinator();
        $dedicated->update(['account_title' => 'support_staff', 'permissions' => ['manage_reports', 'manage_billing']]);
        foreach ([$dedicated, User::factory()->create(['role' => 'admin']), User::factory()->create(['role' => 'applicant'])] as $user) {
            $this->actingAs($user)->getJson('/provider/workspace-guides/manager')->assertForbidden();
            $this->postJson('/provider/workspace-guides/manager/progress', ['version' => 1, 'status' => 'seen'])->assertForbidden();
        }
        $this->assertDatabaseCount('provider_workspace_guide_progress', 3);
        $this->assertDatabaseCount('scholarships', 0);
        $this->assertDatabaseCount('provider_service_purchases', 0);
    }

    private function coordinator(): User
    {
        $owner = User::factory()->create(['role' => 'provider']);

        return User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $owner->id,
            'account_title' => 'program_coordinator', 'permissions' => ['manage_programs'],
        ]);
    }
}
