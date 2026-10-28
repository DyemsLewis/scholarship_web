<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderApplicationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_focused_provider_roles_receive_their_own_application_workspace(): void
    {
        [$owner, $application] = $this->applicationRecord();

        $reviewer = $this->staff($owner, 'verify_applications');
        $this->actingAs($reviewer)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('workspace.mode', 'review')
            ->assertJsonPath('workspace.default_section', 'eligibility')
            ->assertJsonPath('workspace.capabilities.review_documents', true)
            ->assertJsonPath('workspace.capabilities.record_screening_result', true)
            ->assertJsonPath('workspace.capabilities.record_final_outcome', false)
            ->assertJsonPath('application.applicant.guardian_email', 'guardian@example.test');

        $selectionOfficer = $this->staff($owner, 'manage_selection_activities');
        $this->actingAs($selectionOfficer)
            ->getJson("/provider/applications/{$application->id}/data?workspace=decision")
            ->assertOk()
            ->assertJsonPath('workspace.mode', 'selection')
            ->assertJsonPath('workspace.default_section', 'decision')
            ->assertJsonPath('workspace.capabilities.record_current_stage', false)
            ->assertJsonPath('workspace.capabilities.record_selection_result', true)
            ->assertJsonPath('application.documents', [])
            ->assertJsonPath('application.eligibility_breakdown', null)
            ->assertJsonMissingPath('application.applicant.income_bracket')
            ->assertJsonMissingPath('application.applicant.birthdate_iso')
            ->assertJsonMissingPath('application.applicant.barangay')
            ->assertJsonMissingPath('application.applicant.guardian_email');

        $decisionOfficer = $this->staff($owner, 'record_final_decisions');
        $this->actingAs($decisionOfficer)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('workspace.mode', 'decision')
            ->assertJsonPath('workspace.capabilities.view_documents', true)
            ->assertJsonPath('workspace.capabilities.review_documents', false)
            ->assertJsonMissingPath('application.applicant.address')
            ->assertJsonMissingPath('application.applicant.guardian_contact');

        $recipientOfficer = $this->staff($owner, 'manage_recipients');
        $this->actingAs($recipientOfficer)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('workspace.mode', 'recipient')
            ->assertJsonPath('workspace.sections.0.key', 'decision')
            ->assertJsonPath('application.documents', [])
            ->assertJsonPath('application.application_answers', [])
            ->assertJsonMissingPath('application.applicant.income_bracket');
    }

    public function test_full_access_provider_can_request_a_task_context_they_are_allowed_to_use(): void
    {
        [$owner, $application] = $this->applicationRecord();

        $this->actingAs($owner)
            ->getJson("/provider/applications/{$application->id}/data?workspace=decision")
            ->assertOk()
            ->assertJsonPath('workspace.mode', 'decision')
            ->assertJsonPath('workspace.default_section', 'decision')
            ->assertJsonPath('workspace.capabilities.record_final_outcome', true);
    }

    public function test_reviewer_profile_has_structured_details_without_changing_role_redactions(): void
    {
        [$owner, $application] = $this->applicationRecord();
        $application->applicant->studentProfile()->update([
            'birthdate' => '2008-06-15', 'barangay' => 'San Isidro', 'city' => 'Antipolo City',
            'province' => 'Rizal', 'region' => 'Region IV-A',
        ]);
        $reviewer = $this->staff($owner, 'verify_applications');
        $this->actingAs($reviewer)->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.applicant.birthdate_iso', '2008-06-15')
            ->assertJsonPath('application.applicant.birthdate', 'Jun 15, 2008')
            ->assertJsonPath('application.applicant.city', 'Antipolo City')
            ->assertJsonPath('application.applicant.barangay', 'San Isidro')
            ->assertJsonPath('application.applicant.guardian_email', 'guardian@example.test');
        $decisionOfficer = $this->staff($owner, 'record_final_decisions');
        $this->actingAs($decisionOfficer)->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonMissingPath('application.applicant.address')
            ->assertJsonMissingPath('application.applicant.latitude')
            ->assertJsonMissingPath('application.applicant.longitude')
            ->assertJsonMissingPath('application.applicant.guardian_contact')
            ->assertJsonMissingPath('application.applicant.guardian_email');
    }

    /** @return array{User, ScholarshipApplication} */
    private function applicationRecord(): array
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create([
            'provider_id' => $owner->id,
            'title' => 'Focused Workflow Scholarship',
            'description' => 'Used to test role-specific application records.',
            'selection_stages' => ['screening', 'exam', 'decision'],
            'status' => 'published',
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->update([
            'education_level' => 'Senior High School',
            'school' => 'Antipolo City Senior High School',
            'course_or_strand' => 'STEM',
            'year_level' => 'Grade 12',
            'income_bracket' => 'PHP 10,000 - 20,000',
            'address' => 'Private home address',
            'guardian_contact' => '09175550000',
            'guardian_email' => 'guardian@example.test',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'workflow_stage' => 'screening',
            'application_state' => 'active',
            'application_answers' => [['question_id' => 1, 'prompt' => 'Why apply?', 'answer' => 'To continue studying.']],
            'submitted_at' => now(),
        ]);

        return [$owner, $application];
    }

    private function staff(User $owner, string $permission): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'permissions' => [$permission],
        ]);
    }
}
