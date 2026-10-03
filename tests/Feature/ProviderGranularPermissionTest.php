<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderGranularPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_application_permission_expands_to_the_new_workflow_permissions(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $legacyReviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'permissions' => ['review_applications'],
        ]);

        foreach ([
            'verify_applications',
            'manage_selection_activities',
            'record_final_decisions',
            'manage_recipients',
            'manage_monitoring',
            'manage_benefit_releases',
        ] as $permission) {
            $this->assertTrue($legacyReviewer->hasPortalPermission($permission));
        }
    }

    public function test_provider_workflow_roles_only_open_their_route_groups(): void
    {
        [$owner, $program] = $this->approvedProviderProgram();

        $verifier = $this->providerStaff($owner, 'verify_applications');
        $selectionOfficer = $this->providerStaff($owner, 'manage_selection_activities');
        $decisionOfficer = $this->providerStaff($owner, 'record_final_decisions');
        $recipientOfficer = $this->providerStaff($owner, 'manage_recipients');
        $monitoringOfficer = $this->providerStaff($owner, 'manage_monitoring');
        $releaseOfficer = $this->providerStaff($owner, 'manage_benefit_releases');

        $this->actingAs($verifier)
            ->get("/provider/programs/{$program->id}/applications/review")
            ->assertRedirect("/provider/workspaces/reviews?program_id={$program->id}");
        $this->actingAs($verifier)->get("/provider/programs/{$program->id}/applications/activities")->assertForbidden();
        $this->actingAs($verifier)->get('/provider/monitoring')->assertForbidden();

        $this->actingAs($selectionOfficer)
            ->get("/provider/programs/{$program->id}/applications/activities")
            ->assertRedirect("/provider/workspaces/selection?queue=setup&program_id={$program->id}");
        $this->actingAs($selectionOfficer)->get("/provider/programs/{$program->id}/applications/review")->assertForbidden();
        $this->actingAs($selectionOfficer)->get("/provider/programs/{$program->id}/applications/decisions")->assertForbidden();

        $this->actingAs($decisionOfficer)
            ->get("/provider/programs/{$program->id}/applications/decisions")
            ->assertRedirect("/provider/workspaces/decisions?queue=pending&program_id={$program->id}");
        $this->actingAs($decisionOfficer)->get("/provider/programs/{$program->id}/applications/activities")->assertForbidden();

        $this->actingAs($recipientOfficer)
            ->get("/provider/programs/{$program->id}/applications/recipients")
            ->assertRedirect("/provider/workspaces/recipients?queue=awaiting&program_id={$program->id}");
        $this->actingAs($recipientOfficer)->get("/provider/monitoring/{$program->id}/academic")->assertForbidden();

        $this->actingAs($monitoringOfficer)
            ->get('/provider/monitoring')
            ->assertRedirect('/provider/workspaces/monitoring?queue=review');
        $this->actingAs($monitoringOfficer)->get("/provider/monitoring/{$program->id}/academic")->assertOk();
        $this->actingAs($monitoringOfficer)->get("/provider/monitoring/{$program->id}/releases")->assertForbidden();

        $this->actingAs($releaseOfficer)->get("/provider/monitoring/{$program->id}/releases")->assertOk();
        $this->actingAs($releaseOfficer)
            ->get('/provider/monitoring')
            ->assertRedirect('/provider/workspaces/releases?queue=issues');
        $this->actingAs($releaseOfficer)->get("/provider/monitoring/{$program->id}/academic")->assertForbidden();
    }

    public function test_shared_stage_result_endpoint_enforces_the_permission_for_the_actual_stage(): void
    {
        [$owner, $program] = $this->approvedProviderProgram();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'workflow_stage' => 'exam',
            'application_state' => 'provider_stage',
            'submitted_at' => now(),
        ]);
        $verifier = $this->providerStaff($owner, 'verify_applications');
        $selectionOfficer = $this->providerStaff($owner, 'manage_selection_activities');

        $this->actingAs($verifier)
            ->patchJson("/provider/applications/{$application->id}/stages/exam/result", ['result' => 'passed'])
            ->assertForbidden();

        $application->forceFill(['workflow_stage' => 'screening'])->save();

        $this->actingAs($selectionOfficer)
            ->patchJson("/provider/applications/{$application->id}/stages/screening/result", ['result' => 'passed'])
            ->assertForbidden();
    }

    /** @return array{User, Scholarship} */
    private function approvedProviderProgram(): array
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update(['verification_status' => 'approved']);
        $program = Scholarship::create([
            'provider_id' => $owner->id,
            'title' => 'Permission Boundary Program',
            'description' => 'Used to verify focused provider role access.',
            'status' => 'published',
        ]);

        return [$owner, $program];
    }

    private function providerStaff(User $owner, string $permission): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'permissions' => [$permission],
        ]);
    }
}
