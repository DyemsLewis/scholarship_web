<?php

namespace Tests\Feature;

use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringCycleRequirement;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantDashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_applicant_receives_a_focused_onboarding_dashboard(): void
    {
        $applicant = User::factory()->create();

        $this->actingAs($applicant)
            ->getJson('/dashboard/overview-data')
            ->assertOk()
            ->assertJsonPath('user.role', 'applicant')
            ->assertJsonPath('dashboard.mode', 'onboarding')
            ->assertJsonPath('dashboard.primary_action.kind', 'profile')
            ->assertJsonPath('dashboard.primary_action.cta', 'Continue profile')
            ->assertJsonCount(3, 'dashboard.onboarding')
            ->assertJsonCount(0, 'dashboard.secondary_actions')
            ->assertJsonCount(0, 'dashboard.applications')
            ->assertJsonMissingPath('scholarships')
            ->assertJsonMissingPath('action_alerts');
    }

    public function test_correction_request_is_ranked_above_other_dashboard_work(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship($provider, 'Community Learning Grant');
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'correction_status' => 'requested',
            'correction_message' => 'Replace the unreadable enrollment record.',
            'correction_requested_at' => now(),
            'submitted_at' => now()->subDay(),
        ]);

        $this->actingAs($applicant)
            ->getJson('/dashboard/overview-data')
            ->assertOk()
            ->assertJsonPath('dashboard.mode', 'applying')
            ->assertJsonPath('dashboard.primary_action.kind', 'correction')
            ->assertJsonPath('dashboard.primary_action.title', 'Update your application')
            ->assertJsonPath('dashboard.primary_action.context', $scholarship->title)
            ->assertJsonPath('dashboard.applications.0.id', $application->id)
            ->assertJsonPath('dashboard.applications.0.progress_percent', 0)
            ->assertJsonPath('dashboard.applications.0.progress_label', '0 of 3 stages complete')
            ->assertJsonPath('dashboard.applications.0.needs_action', true);
    }

    public function test_ready_applicant_receives_one_compact_recommendation(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship($provider, 'Future Scholars Award');

        $this->actingAs($applicant)
            ->getJson('/dashboard/overview-data')
            ->assertOk()
            ->assertJsonPath('dashboard.mode', 'discovery')
            ->assertJsonPath('dashboard.primary_action.kind', 'browse')
            ->assertJsonPath('dashboard.recommendation.id', $scholarship->id)
            ->assertJsonPath('dashboard.recommendation.title', $scholarship->title)
            ->assertJsonCount(0, 'dashboard.onboarding');
    }

    public function test_formal_application_without_a_schedule_still_loads_the_dashboard(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship($provider, 'Provider Application Grant');
        $scholarship->update([
            'selection_stages' => ['screening', 'formal_application', 'decision'],
        ]);
        ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'approved',
            'application_state' => 'in_provider_process',
            'workflow_stage' => 'formal_application',
            'decision_reason' => 'passed_prescreening',
            'submitted_at' => now()->subDay(),
        ]);

        $this->actingAs($applicant)
            ->getJson('/dashboard/overview-data')
            ->assertOk()
            ->assertJsonPath('dashboard.mode', 'applying')
            ->assertJsonPath('dashboard.primary_action.kind', 'application')
            ->assertJsonPath('dashboard.primary_action.cta', 'Open application')
            ->assertJsonPath('dashboard.primary_action.due_at', null)
            ->assertJsonPath('dashboard.primary_action.due_label', null);
    }

    public function test_selected_recipient_sees_an_open_monitoring_requirement_first(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeApplicant();
        $scholarship = $this->publishedScholarship($provider, 'Continuing Scholar Grant');
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'application_state' => 'closed',
            'workflow_stage' => 'complete',
            'final_outcome' => 'selected',
            'student_response_status' => 'accepted',
            'provider_contract_terms_snapshot' => ['program_title' => $scholarship->title],
            'provider_contract_terms_version' => 'test-v1',
            'submitted_at' => now()->subMonth(),
        ]);
        $cycle = RecipientMonitoringCycle::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'First semester check-in',
            'period_type' => 'semester',
            'academic_period' => 'First semester',
            'school_year' => '2026-2027',
            'opens_at' => now()->subDay()->toDateString(),
            'due_at' => now()->addWeek()->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'open',
            'published_at' => now(),
        ]);
        RecipientMonitoringCycleRequirement::create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'type' => 'academic_progress',
            'title' => 'Current grade record',
            'required' => true,
            'requires_file' => true,
            'requires_original_verification' => false,
            'sort_order' => 1,
        ]);

        $this->actingAs($applicant)
            ->getJson('/dashboard/overview-data')
            ->assertOk()
            ->assertJsonPath('dashboard.mode', 'recipient')
            ->assertJsonPath('dashboard.primary_action.kind', 'monitoring')
            ->assertJsonPath('dashboard.primary_action.title', 'Complete First semester check-in')
            ->assertJsonPath('dashboard.primary_action.cta', 'Open check-in')
            ->assertJsonPath('dashboard.primary_action.due_label', 'Due '.$cycle->due_at->format('M d, Y'))
            ->assertJsonPath('dashboard.applications.0.id', $application->id);
    }

    public function test_non_applicant_cannot_open_applicant_dashboard_overview(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->getJson('/dashboard/overview-data')
            ->assertForbidden();
    }

    private function completeApplicant(): User
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2000-05-10',
            'account_managed_by' => 'learner',
            'education_level' => 'college',
            'school' => 'Sample University',
            'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'academic_year' => '2026-2027',
            'academic_term' => 'first_semester',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'household_size' => 4,
            'current_scholarship_status' => 'none',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
            'profile_photo_path' => 'profile-photos/tests/dashboard-applicant.jpg',
        ]);

        return $applicant->fresh();
    }

    private function publishedScholarship(User $provider, string $title): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => $title,
            'category' => 'Academic merit',
            'description' => 'A scholarship used to verify the applicant dashboard.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
    }
}
