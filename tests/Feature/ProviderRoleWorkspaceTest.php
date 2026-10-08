<?php

namespace Tests\Feature;

use App\Models\RecipientBenefitReceiptResponse;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringIntervention;
use App\Models\RecipientMonitoringPlan;
use App\Models\RecipientMonitoringSubmission;
use App\Models\ProviderServicePurchase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderRoleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_access_manager_enters_the_manager_overview_and_keeps_role_workspaces_available(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $manager = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'manager',
            'permissions' => User::PROVIDER_PERMISSIONS,
        ]);

        $this->actingAs($manager)
            ->get('/provider')
            ->assertOk()
            ->assertViewIs('provider');

        $this->assertNull($manager->fresh('providerProfile')->publicPayload()['provider_workspace_url']);

        foreach ([
            '/provider/workspaces/programs',
            '/provider/workspaces/reviews',
            '/provider/workspaces/selection',
            '/provider/workspaces/decisions',
            '/provider/workspaces/recipients',
            '/provider/workspaces/monitoring',
            '/provider/workspaces/releases',
            '/provider/workspaces/organization-profile',
            '/provider/workspaces/team',
            '/provider/workspaces/support',
            '/provider/workspaces/billing',
        ] as $workspace) {
            $this->actingAs($manager)->get($workspace)->assertOk();
        }
    }

    public function test_program_coordinator_enters_a_dedicated_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $coordinator = $this->programCoordinator($owner);

        $this->actingAs($coordinator)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/programs');

        $this->actingAs($coordinator)
            ->get('/provider/programs')
            ->assertRedirect('/provider/workspaces/programs');

        $this->actingAs($coordinator)
            ->get('/provider/workspaces/programs')
            ->assertOk()
            ->assertViewIs('provider-program-coordinator-workspace');

        foreach (['drafts', 'review', 'published', 'closed'] as $section) {
            $this->actingAs($coordinator)
                ->get("/provider/workspaces/programs/{$section}")
                ->assertOk()
                ->assertViewIs('provider-program-coordinator-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/programs',
            $coordinator->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_program_workbench_is_scoped_and_prioritizes_program_lifecycle_work(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $draft = $this->program($owner, 'Coordinator Draft', 'draft');
        $rejected = $this->program($owner, 'Coordinator Revision', 'rejected');
        $published = $this->program($owner, 'Coordinator Published', 'published', now()->addDays(7)->toDateString());
        $this->program($owner, 'Outside Assignment', 'closed');
        $coordinator = $this->programCoordinator($owner, [$draft->id, $rejected->id, $published->id]);

        $response = $this->actingAs($coordinator)
            ->getJson('/provider/workspaces/programs/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Program coordinator')
            ->assertJsonPath('workspace.program_access_mode', 'selected')
            ->assertJsonPath('summary.total', 3)
            ->assertJsonPath('summary.attention', 3)
            ->assertJsonPath('phases.drafts', 2)
            ->assertJsonPath('phases.review', 0)
            ->assertJsonPath('phases.published', 1)
            ->assertJsonPath('next_action.title', 'Address the requested program changes')
            ->assertJsonPath('next_action.href', "/provider/programs/{$rejected->id}/edit")
            ->assertJsonPath('section', 'overview')
            ->assertJsonCount(0, 'programs');

        $draftResponse = $this->actingAs($coordinator)
            ->getJson('/provider/workspaces/programs/data?section=drafts')
            ->assertOk()
            ->assertJsonPath('section', 'drafts')
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'programs');

        $publishedResponse = $this->actingAs($coordinator)
            ->getJson('/provider/workspaces/programs/data?section=published')
            ->assertOk()
            ->assertJsonPath('section', 'published')
            ->assertJsonPath('programs.0.id', $published->id)
            ->assertJsonCount(1, 'programs');

        $this->assertEqualsCanonicalizing(
            [$draft->id, $rejected->id],
            collect($draftResponse->json('programs'))->pluck('id')->all(),
        );

        $this->assertSame($published->id, $publishedResponse->json('programs.0.id'));
    }

    public function test_program_coordinator_queue_paginates_five_programs_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $programs = collect(range(1, 6))->map(
            fn (int $number) => $this->program($owner, "Coordinator Draft {$number}", 'draft'),
        );
        $coordinator = $this->programCoordinator($owner, $programs->pluck('id')->all());

        $this->actingAs($coordinator)
            ->getJson('/provider/workspaces/programs/data?section=drafts')
            ->assertOk()
            ->assertJsonCount(5, 'programs')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($coordinator)
            ->getJson('/provider/workspaces/programs/data?section=drafts&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'programs')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_non_program_staff_cannot_open_the_coordinator_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['verify_applications'],
        ]);

        $this->actingAs($reviewer)->get('/provider/workspaces/programs')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/programs/data')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/programs/drafts')->assertForbidden();
    }

    public function test_application_reviewer_enters_a_dedicated_verification_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Reviewer Program', 'published');
        $reviewer = $this->applicationReviewer($owner, [$program->id]);

        $this->actingAs($reviewer)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/reviews');

        $this->actingAs($reviewer)
            ->get('/provider/applications/review')
            ->assertRedirect('/provider/workspaces/reviews');

        $this->actingAs($reviewer)
            ->get("/provider/programs/{$program->id}/applications/review")
            ->assertRedirect("/provider/workspaces/reviews?program_id={$program->id}");

        $this->actingAs($reviewer)
            ->get('/provider/workspaces/reviews')
            ->assertOk()
            ->assertViewIs('provider-application-reviewer-workspace');

        foreach (['assigned', 'unassigned', 'returned', 'history'] as $section) {
            $this->actingAs($reviewer)
                ->get("/provider/workspaces/reviews/{$section}")
                ->assertOk()
                ->assertViewIs('provider-application-reviewer-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/reviews',
            $reviewer->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_application_reviewer_login_opens_the_dedicated_workspace_directly(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);
        $reviewer->forceFill([
            'password' => 'review-password123',
            'email_verified_at' => now(),
            'must_reset_password' => false,
        ])->save();

        $this->postJson('/login', [
            'email' => $reviewer->email,
            'password' => 'review-password123',
        ])->assertOk()
            ->assertJsonPath('redirect', '/provider/workspaces/reviews')
            ->assertJsonPath('user.provider_workspace_url', '/provider/workspaces/reviews');
    }

    public function test_specialist_keeps_the_primary_workspace_and_can_open_additional_granted_workspaces(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);
        $reviewer->update([
            'permissions' => ['verify_applications', 'manage_selection_activities'],
        ]);

        $this->actingAs($reviewer)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/reviews');

        $this->actingAs($reviewer)
            ->get('/provider/workspaces/reviews')
            ->assertOk()
            ->assertViewIs('provider-application-reviewer-workspace');

        $this->actingAs($reviewer)
            ->get('/provider/workspaces/selection')
            ->assertOk()
            ->assertViewIs('provider-selection-officer-workspace');

        $payload = $reviewer->fresh('providerProfile')->publicPayload();

        $this->assertSame('/provider/workspaces/reviews', $payload['provider_workspace_url']);
        $this->assertEqualsCanonicalizing(
            ['verify_applications', 'manage_selection_activities'],
            $payload['permissions'],
        );
    }

    public function test_verification_workspace_is_scoped_to_screening_work_and_reviewer_assignments(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assignedProgram = $this->program($owner, 'Assigned Review Program', 'published');
        $outsideProgram = $this->program($owner, 'Outside Review Program', 'published');
        $reviewer = $this->applicationReviewer($owner, [$assignedProgram->id]);
        $assignedApplicant = $this->applicant('Assigned', 'Applicant');
        $unassignedApplicant = $this->applicant('Open', 'Applicant');
        $activityApplicant = $this->applicant('Exam', 'Applicant');
        $outsideApplicant = $this->applicant('Outside', 'Applicant');
        $assignedApplication = $this->application($assignedProgram, $assignedApplicant, 'screening', $reviewer->id, now()->subDays(5));
        $this->application($assignedProgram, $unassignedApplicant, 'screening', null, now()->subDays(3));
        $this->application($assignedProgram, $activityApplicant, 'exam', null, now()->subDays(2));
        $this->application($outsideProgram, $outsideApplicant, 'screening', null, now()->subDay());

        $this->actingAs($reviewer)
            ->getJson('/provider/workspaces/reviews/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Application reviewer')
            ->assertJsonPath('workspace.program_access_mode', 'selected')
            ->assertJsonPath('summary.mine', 1)
            ->assertJsonPath('summary.unassigned', 1)
            ->assertJsonPath('summary.team', 2)
            ->assertJsonPath('next_review.id', $assignedApplication->id)
            ->assertJsonPath('applications.0.applicant.name', 'Assigned Applicant')
            ->assertJsonCount(1, 'applications');

        $this->actingAs($reviewer)
            ->getJson('/provider/workspaces/reviews/data?queue=unassigned')
            ->assertOk()
            ->assertJsonPath('applications.0.applicant.name', 'Open Applicant')
            ->assertJsonPath('applications.0.assignment.state', 'unassigned')
            ->assertJsonCount(1, 'applications');
    }

    public function test_application_review_queue_paginates_five_applications_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Reviewer Pagination Program', 'published');
        $reviewer = $this->applicationReviewer($owner, [$program->id]);

        foreach (range(1, 6) as $number) {
            $this->application(
                $program,
                $this->applicant("Review{$number}", 'Applicant'),
                'screening',
                $reviewer->id,
                now()->subDays($number),
            );
        }

        $this->actingAs($reviewer)
            ->getJson('/provider/workspaces/reviews/data')
            ->assertOk()
            ->assertJsonCount(5, 'applications')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($reviewer)
            ->getJson('/provider/workspaces/reviews/data?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'applications')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_application_reviewer_has_separate_returned_and_history_queues(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Reviewer Queue Program', 'published');
        $reviewer = $this->applicationReviewer($owner, [$program->id]);
        $returned = $this->application(
            $program,
            $this->applicant('Returned', 'Applicant'),
            'screening',
            $reviewer->id,
            now()->subDays(2),
        );
        $returned->update(['correction_status' => 'submitted']);
        $reviewed = $this->application(
            $program,
            $this->applicant('Reviewed', 'Applicant'),
            'exam',
            $reviewer->id,
            now()->subDays(5),
        );
        $reviewed->update([
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now()->subDay(),
        ]);

        $this->actingAs($reviewer)
            ->getJson('/provider/workspaces/reviews/data?queue=returned')
            ->assertOk()
            ->assertJsonPath('summary.returned', 1)
            ->assertJsonPath('applications.0.id', $returned->id)
            ->assertJsonPath('applications.0.evidence.state', 'returned')
            ->assertJsonCount(1, 'applications');

        $this->actingAs($reviewer)
            ->getJson('/provider/workspaces/reviews/data?queue=history')
            ->assertOk()
            ->assertJsonPath('summary.history', 1)
            ->assertJsonPath('applications.0.id', $reviewed->id)
            ->assertJsonPath('applications.0.reviewed_at', $reviewed->reviewed_at->format('M d, Y h:i A'))
            ->assertJsonCount(1, 'applications');
    }

    public function test_non_review_staff_cannot_open_the_verification_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $coordinator = $this->programCoordinator($owner);

        $this->actingAs($coordinator)->get('/provider/workspaces/reviews')->assertForbidden();
        $this->actingAs($coordinator)->get('/provider/workspaces/reviews/returned')->assertForbidden();
        $this->actingAs($coordinator)->getJson('/provider/workspaces/reviews/data')->assertForbidden();
    }

    public function test_selection_officer_enters_a_dedicated_activity_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Selection Program', 'published');
        $officer = $this->selectionOfficer($owner, [$program->id]);

        $this->actingAs($officer)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/selection');

        $this->actingAs($officer)
            ->get('/provider/applications/activities')
            ->assertRedirect('/provider/workspaces/selection?queue=setup');

        $this->actingAs($officer)
            ->get('/provider/applications/results')
            ->assertRedirect('/provider/workspaces/selection?queue=results');

        $this->actingAs($officer)
            ->get("/provider/programs/{$program->id}/applications/results")
            ->assertRedirect("/provider/workspaces/selection?queue=results&program_id={$program->id}");

        $this->actingAs($officer)
            ->get('/provider/workspaces/selection')
            ->assertOk()
            ->assertViewIs('provider-selection-officer-workspace');

        foreach (['setup', 'results', 'active'] as $section) {
            $this->actingAs($officer)
                ->get("/provider/workspaces/selection/{$section}")
                ->assertOk()
                ->assertViewIs('provider-selection-officer-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/selection',
            $officer->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_selection_workspace_separates_activity_setup_from_ready_results(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assignedProgram = $this->program($owner, 'Assigned Selection Program', 'published');
        $outsideProgram = $this->program($owner, 'Outside Selection Program', 'published');
        $officer = $this->selectionOfficer($owner, [$assignedProgram->id]);
        $exam = $this->application($assignedProgram, $this->applicant('Exam', 'Candidate'), 'exam', null, now()->subDays(3));
        $interview = $this->application($assignedProgram, $this->applicant('Interview', 'Candidate'), 'interview', null, now()->subDays(6));
        $this->application($assignedProgram, $this->applicant('Formal', 'Candidate'), 'formal_application', null, now()->subDays(4));
        $this->application($assignedProgram, $this->applicant('Screening', 'Candidate'), 'screening', null, now()->subDays(2));
        $this->application($outsideProgram, $this->applicant('Outside', 'Candidate'), 'exam', null, now()->subDay());
        $interview->schedules()->create([
            'type' => 'interview',
            'title' => 'Completed interview',
            'scheduled_at' => now()->subDay(),
            'mode' => 'online',
            'online_url' => 'https://meet.example.test/interview',
            'instructions' => 'Join the interview room.',
            'status' => 'completed',
            'attendance_status' => 'not_required',
            'completed_at' => now(),
            'created_by' => $officer->id,
            'updated_by' => $officer->id,
        ]);

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Selection officer')
            ->assertJsonPath('workspace.program_access_mode', 'selected')
            ->assertJsonPath('summary.setup', 1)
            ->assertJsonPath('summary.results', 2)
            ->assertJsonPath('summary.active', 3)
            ->assertJsonPath('stages.formal_application', 1)
            ->assertJsonPath('stages.exam', 1)
            ->assertJsonPath('stages.interview', 1)
            ->assertJsonPath('next_activity.id', $interview->id)
            ->assertJsonPath('applications.0.id', $exam->id)
            ->assertJsonPath('applications.0.activity.state', 'setup')
            ->assertJsonCount(1, 'applications');

        $resultResponse = $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/data?queue=results')
            ->assertOk()
            ->assertJsonCount(2, 'applications');

        $this->assertEqualsCanonicalizing(
            ['Formal Candidate', 'Interview Candidate'],
            collect($resultResponse->json('applications'))->pluck('applicant.name')->all(),
        );
    }

    public function test_selection_activity_queue_paginates_five_candidates_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Selection Pagination Program', 'published');
        $officer = $this->selectionOfficer($owner, [$program->id]);

        foreach (range(1, 6) as $number) {
            $this->application(
                $program,
                $this->applicant("Candidate{$number}", 'Selection'),
                'exam',
                null,
                now()->subDays($number),
            );
        }

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/data')
            ->assertOk()
            ->assertJsonCount(5, 'applications')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/data?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'applications')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_non_selection_staff_cannot_open_the_selection_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/selection')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/selection/results')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/selection/data')->assertForbidden();
    }

    public function test_decision_officer_enters_a_dedicated_final_decision_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Decision Program', 'published');
        $officer = $this->decisionOfficer($owner, [$program->id]);

        $this->actingAs($officer)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/decisions');

        $this->actingAs($officer)
            ->get('/provider/applications/decisions')
            ->assertRedirect('/provider/workspaces/decisions?queue=pending');

        $this->actingAs($officer)
            ->get('/provider/applications/waitlist')
            ->assertRedirect('/provider/workspaces/decisions?queue=waitlist');

        $this->actingAs($officer)
            ->get("/provider/programs/{$program->id}/applications/waitlist")
            ->assertRedirect("/provider/workspaces/decisions?queue=waitlist&program_id={$program->id}");

        $this->actingAs($officer)
            ->get('/provider/workspaces/decisions')
            ->assertOk()
            ->assertViewIs('provider-decision-officer-workspace');

        foreach (['pending', 'waitlist', 'recorded'] as $section) {
            $this->actingAs($officer)
                ->get("/provider/workspaces/decisions/{$section}")
                ->assertOk()
                ->assertViewIs('provider-decision-officer-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/decisions',
            $officer->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_decision_workspace_separates_pending_waitlist_and_recorded_outcomes(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assignedProgram = $this->program($owner, 'Assigned Decision Program', 'published');
        $assignedProgram->update(['slots_available' => 2]);
        $outsideProgram = $this->program($owner, 'Outside Decision Program', 'published');
        $officer = $this->decisionOfficer($owner, [$assignedProgram->id]);

        $pending = $this->application($assignedProgram, $this->applicant('Pending', 'Candidate'), 'decision', null, now()->subDays(6));
        $pending->update(['status' => 'approved', 'application_state' => 'awaiting_decision']);
        $waitlisted = $this->application($assignedProgram, $this->applicant('Waitlist', 'Candidate'), 'decision', null, now()->subDays(5));
        $waitlisted->update([
            'status' => 'waitlisted',
            'application_state' => 'awaiting_decision',
            'final_outcome' => 'waitlisted',
            'waitlist_position' => 1,
            'waitlisted_at' => now()->subDays(2),
        ]);
        $selected = $this->application($assignedProgram, $this->applicant('Selected', 'Candidate'), 'complete', null, now()->subDays(8));
        $selected->update([
            'status' => 'awarded',
            'application_state' => 'closed',
            'final_outcome' => 'selected',
            'outcome_at' => now()->subDay(),
        ]);
        $notSelected = $this->application($assignedProgram, $this->applicant('Closed', 'Candidate'), 'complete', null, now()->subDays(7));
        $notSelected->update([
            'status' => 'not_awarded',
            'application_state' => 'closed',
            'final_outcome' => 'not_selected',
            'outcome_at' => now()->subDay(),
        ]);
        $this->application($assignedProgram, $this->applicant('Selection', 'Candidate'), 'interview', null, now()->subDays(3));
        $this->application($outsideProgram, $this->applicant('Outside', 'Candidate'), 'decision', null, now()->subDay());

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/decisions/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Decision officer')
            ->assertJsonPath('workspace.program_access_mode', 'selected')
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.waitlist', 1)
            ->assertJsonPath('summary.recorded', 2)
            ->assertJsonPath('next_decision.id', $pending->id)
            ->assertJsonPath('applications.0.id', $pending->id)
            ->assertJsonPath('applications.0.capacity.total', 2)
            ->assertJsonPath('applications.0.capacity.occupied', 1)
            ->assertJsonPath('applications.0.capacity.remaining', 1)
            ->assertJsonCount(1, 'applications');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/decisions/data?queue=waitlist')
            ->assertOk()
            ->assertJsonPath('applications.0.id', $waitlisted->id)
            ->assertJsonPath('applications.0.decision.label', 'Waitlist #1')
            ->assertJsonCount(1, 'applications');

        $recordedResponse = $this->actingAs($officer)
            ->getJson('/provider/workspaces/decisions/data?queue=recorded')
            ->assertOk()
            ->assertJsonCount(2, 'applications');

        $this->assertEqualsCanonicalizing(
            [$selected->id, $notSelected->id],
            collect($recordedResponse->json('applications'))->pluck('id')->all(),
        );
    }

    public function test_decision_docket_paginates_five_candidates_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Decision Pagination Program', 'published');
        $officer = $this->decisionOfficer($owner, [$program->id]);

        foreach (range(1, 6) as $number) {
            $application = $this->application(
                $program,
                $this->applicant("Decision{$number}", 'Candidate'),
                'decision',
                null,
                now()->subDays($number),
            );
            $application->update(['status' => 'approved', 'application_state' => 'awaiting_decision']);
        }

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/decisions/data')
            ->assertOk()
            ->assertJsonCount(5, 'applications')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/decisions/data?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'applications')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_non_decision_staff_cannot_open_the_final_decision_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/decisions')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/decisions/waitlist')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/decisions/data')->assertForbidden();
    }

    public function test_recipient_officer_enters_a_dedicated_onboarding_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Recipient Program', 'published');
        $officer = $this->recipientOfficer($owner, [$program->id]);

        $this->actingAs($officer)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/recipients');

        $this->actingAs($officer)
            ->get('/provider/applications/recipients')
            ->assertRedirect('/provider/workspaces/recipients?queue=awaiting');

        $this->actingAs($officer)
            ->get("/provider/programs/{$program->id}/applications/recipients")
            ->assertRedirect("/provider/workspaces/recipients?queue=awaiting&program_id={$program->id}");

        $this->actingAs($officer)
            ->get('/provider/monitoring')
            ->assertRedirect('/provider/workspaces/recipients?queue=active');

        $this->actingAs($officer)
            ->get('/provider/workspaces/recipients')
            ->assertOk()
            ->assertViewIs('provider-recipient-officer-workspace');

        foreach (['agreements', 'active', 'declined', 'closed'] as $section) {
            $this->actingAs($officer)
                ->get("/provider/workspaces/recipients/{$section}")
                ->assertOk()
                ->assertViewIs('provider-recipient-officer-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/recipients',
            $officer->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_recipient_workspace_separates_agreement_and_support_states(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assignedProgram = $this->program($owner, 'Assigned Recipient Program', 'published');
        $assignedProgram->update([
            'award_amount' => 12000,
            'support_starts_at' => now()->addMonth()->toDateString(),
            'support_ends_at' => now()->addYear()->toDateString(),
        ]);
        $outsideProgram = $this->program($owner, 'Outside Recipient Program', 'published');
        $officer = $this->recipientOfficer($owner, [$assignedProgram->id]);

        $awaitingApplicant = $this->applicant('Awaiting', 'Recipient');
        $awaitingApplicant->studentProfile()->update(['profile_photo_path' => 'profile-photos/awaiting-recipient.jpg']);
        $awaiting = $this->selectedRecipient($assignedProgram, $awaitingApplicant->fresh('studentProfile'));
        $active = $this->selectedRecipient($assignedProgram, $this->applicant('Active', 'Recipient'), 'accepted');
        $declined = $this->selectedRecipient($assignedProgram, $this->applicant('Declined', 'Recipient'), 'declined');
        $declined->update(['student_response_note' => 'I cannot complete the current recipient terms.']);
        $closed = $this->selectedRecipient($assignedProgram, $this->applicant('Closed', 'Recipient'), 'accepted');
        $closed->supportDecisions()->create([
            'applicant_id' => $closed->applicant_id,
            'decision' => 'completed',
            'effective_on' => now()->toDateString(),
            'reason' => 'The recipient completed the program support period.',
            'decided_by' => $officer->id,
            'decided_at' => now(),
        ]);
        $this->selectedRecipient($outsideProgram, $this->applicant('Outside', 'Recipient'));

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/recipients/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Recipient officer')
            ->assertJsonPath('workspace.program_access_mode', 'selected')
            ->assertJsonPath('summary.awaiting', 1)
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.declined', 1)
            ->assertJsonPath('summary.closed', 1)
            ->assertJsonPath('next_recipient.id', $declined->id)
            ->assertJsonPath('next_recipient.onboarding.state', 'declined')
            ->assertJsonPath('recipients.0.id', $awaiting->id)
            ->assertJsonPath('recipients.0.onboarding.state', 'awaiting')
            ->assertJsonPath('recipients.0.applicant.profile_photo_url', route('provider.applications.profile-photo.view', $awaiting, false))
            ->assertJsonCount(1, 'recipients');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/recipients/data?queue=active')
            ->assertOk()
            ->assertJsonPath('recipients.0.id', $active->id)
            ->assertJsonPath('recipients.0.onboarding.state', 'active')
            ->assertJsonCount(1, 'recipients');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/recipients/data?queue=declined')
            ->assertOk()
            ->assertJsonPath('recipients.0.id', $declined->id)
            ->assertJsonPath('recipients.0.onboarding.response_note', 'I cannot complete the current recipient terms.')
            ->assertJsonCount(1, 'recipients');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/recipients/data?queue=closed')
            ->assertOk()
            ->assertJsonPath('recipients.0.id', $closed->id)
            ->assertJsonPath('recipients.0.onboarding.state', 'closed')
            ->assertJsonCount(1, 'recipients');
    }

    public function test_recipient_register_paginates_five_recipients_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Recipient Pagination Program', 'published');
        $officer = $this->recipientOfficer($owner, [$program->id]);

        foreach (range(1, 6) as $number) {
            $this->selectedRecipient($program, $this->applicant("Recipient{$number}", 'Awaiting'));
        }

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/recipients/data')
            ->assertOk()
            ->assertJsonCount(5, 'recipients')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/recipients/data?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'recipients')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_non_recipient_staff_cannot_open_the_recipient_workspace(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/recipients')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/recipients/active')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/recipients/data')->assertForbidden();
    }

    public function test_monitoring_officer_enters_a_dedicated_monitoring_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Monitoring Program', 'published');
        $officer = $this->monitoringOfficer($owner, [$program->id]);

        $this->actingAs($officer)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/monitoring');

        $this->actingAs($officer)
            ->get('/provider/monitoring')
            ->assertRedirect('/provider/workspaces/monitoring?queue=review');

        $this->actingAs($officer)
            ->get('/provider/workspaces/monitoring')
            ->assertOk()
            ->assertViewIs('provider-monitoring-officer-workspace');

        foreach (['review', 'follow-ups', 'awaiting', 'history'] as $section) {
            $this->actingAs($officer)
                ->get("/provider/workspaces/monitoring/{$section}")
                ->assertOk()
                ->assertViewIs('provider-monitoring-officer-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/monitoring',
            $officer->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_monitoring_workspace_assigns_each_check_in_one_primary_work_state(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assignedProgram = $this->program($owner, 'Assigned Monitoring Program', 'published');
        $outsideProgram = $this->program($owner, 'Outside Monitoring Program', 'published');
        $officer = $this->monitoringOfficer($owner, [$assignedProgram->id]);
        $recipient = $this->selectedRecipient($assignedProgram, $this->applicant('Monitored', 'Recipient'), 'accepted');
        $outsideRecipient = $this->selectedRecipient($outsideProgram, $this->applicant('Outside', 'Recipient'), 'accepted');
        $plan = RecipientMonitoringPlan::query()->create([
            'scholarship_id' => $assignedProgram->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'frequency' => 'semester',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'status' => 'active',
            'version' => 1,
            'activated_at' => now(),
        ]);
        $reviewCycle = $this->monitoringCycle($assignedProgram, $plan, 'Review check-in', now()->addDays(7)->toDateString());
        $reviewRequirement = $reviewCycle->requirements()->create([
            'type' => 'academic_progress',
            'title' => 'Academic progress',
            'required' => true,
            'requires_file' => true,
            'sort_order' => 0,
        ]);
        RecipientMonitoringSubmission::query()->create([
            'recipient_monitoring_cycle_id' => $reviewCycle->id,
            'recipient_monitoring_cycle_requirement_id' => $reviewRequirement->id,
            'scholarship_application_id' => $recipient->id,
            'applicant_id' => $recipient->applicant_id,
            'original_name' => 'report-card.pdf',
            'path' => 'monitoring/report-card.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'submitted_at' => now(),
            'review_status' => 'pending',
        ]);
        $followupCycle = $this->monitoringCycle($assignedProgram, $plan, 'Follow-up check-in', now()->addDays(14)->toDateString());
        $followupRequirement = $followupCycle->requirements()->create([
            'type' => 'enrollment',
            'title' => 'Enrollment status',
            'required' => true,
            'requires_file' => true,
            'sort_order' => 0,
        ]);
        RecipientMonitoringIntervention::query()->create([
            'recipient_monitoring_cycle_id' => $followupCycle->id,
            'recipient_monitoring_cycle_requirement_id' => $followupRequirement->id,
            'scholarship_application_id' => $recipient->id,
            'applicant_id' => $recipient->applicant_id,
            'created_by' => $officer->id,
            'type' => 'follow_up',
            'summary' => 'Confirm current enrollment status.',
            'status' => 'open',
        ]);
        $awaitingCycle = $this->monitoringCycle($assignedProgram, $plan, 'Awaiting check-in', now()->addMonth()->toDateString());
        $awaitingCycle->requirements()->create([
            'type' => 'enrollment',
            'title' => 'Current enrollment',
            'required' => true,
            'requires_file' => true,
            'sort_order' => 0,
        ]);
        $historyCycle = $this->monitoringCycle($assignedProgram, $plan, 'Past check-in', now()->subDay()->toDateString());
        $historyCycle->requirements()->create([
            'type' => 'enrollment',
            'title' => 'Past enrollment record',
            'required' => true,
            'requires_file' => true,
            'sort_order' => 0,
        ]);
        $outsidePlan = RecipientMonitoringPlan::query()->create([
            'scholarship_id' => $outsideProgram->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'frequency' => 'semester',
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'status' => 'active',
            'version' => 1,
            'activated_at' => now(),
        ]);
        $outsideCycle = $this->monitoringCycle($outsideProgram, $outsidePlan, 'Outside check-in', now()->addDays(5)->toDateString());
        $outsideRequirement = $outsideCycle->requirements()->create([
            'type' => 'academic_progress',
            'title' => 'Outside record',
            'required' => true,
            'requires_file' => true,
            'sort_order' => 0,
        ]);
        RecipientMonitoringSubmission::query()->create([
            'recipient_monitoring_cycle_id' => $outsideCycle->id,
            'recipient_monitoring_cycle_requirement_id' => $outsideRequirement->id,
            'scholarship_application_id' => $outsideRecipient->id,
            'applicant_id' => $outsideRecipient->applicant_id,
            'submitted_at' => now(),
            'review_status' => 'pending',
        ]);

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/monitoring/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Monitoring officer')
            ->assertJsonPath('workspace.program_access_mode', 'selected')
            ->assertJsonPath('summary.review', 1)
            ->assertJsonPath('summary.followup', 1)
            ->assertJsonPath('summary.awaiting', 1)
            ->assertJsonPath('summary.history', 1)
            ->assertJsonPath('next_task.id', $reviewCycle->id)
            ->assertJsonPath('next_task.work_state', 'review')
            ->assertJsonPath('check_ins.0.id', $reviewCycle->id)
            ->assertJsonPath('check_ins.0.counts.pending_reviews', 1)
            ->assertJsonCount(1, 'check_ins');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/monitoring/data?queue=followup')
            ->assertOk()
            ->assertJsonPath('check_ins.0.id', $followupCycle->id)
            ->assertJsonPath('check_ins.0.counts.open_interventions', 1)
            ->assertJsonCount(1, 'check_ins');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/monitoring/data?queue=awaiting')
            ->assertOk()
            ->assertJsonPath('check_ins.0.id', $awaitingCycle->id)
            ->assertJsonCount(1, 'check_ins');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/monitoring/data?queue=history')
            ->assertOk()
            ->assertJsonPath('check_ins.0.id', $historyCycle->id)
            ->assertJsonCount(1, 'check_ins');
    }

    public function test_monitoring_desk_paginates_five_check_ins_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Monitoring Pagination Program', 'published');
        $officer = $this->monitoringOfficer($owner, [$program->id]);
        $this->selectedRecipient($program, $this->applicant('Monitoring', 'Recipient'), 'accepted');
        $plan = RecipientMonitoringPlan::query()->create([
            'scholarship_id' => $program->id,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'frequency' => 'semester',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'status' => 'active',
            'version' => 1,
            'activated_at' => now(),
        ]);

        foreach (range(1, 6) as $number) {
            $cycle = $this->monitoringCycle(
                $program,
                $plan,
                "Awaiting check-in {$number}",
                now()->addDays(20 + $number)->toDateString(),
            );
            $cycle->requirements()->create([
                'type' => 'enrollment',
                'title' => "Enrollment record {$number}",
                'required' => true,
                'requires_file' => true,
                'sort_order' => 0,
            ]);
        }

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/monitoring/data?queue=awaiting')
            ->assertOk()
            ->assertJsonCount(5, 'check_ins')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/monitoring/data?queue=awaiting&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'check_ins')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_non_monitoring_staff_cannot_open_the_monitoring_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/monitoring')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/monitoring/follow-ups')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/monitoring/data')->assertForbidden();
    }

    public function test_benefit_release_officer_enters_a_dedicated_release_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Release Program', 'published');
        $officer = $this->benefitReleaseOfficer($owner, [$program->id]);

        $this->actingAs($officer)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/releases');

        $this->actingAs($officer)
            ->get('/provider/monitoring')
            ->assertRedirect('/provider/workspaces/releases?queue=issues');

        $this->actingAs($officer)
            ->get('/provider/workspaces/releases')
            ->assertOk()
            ->assertViewIs('provider-benefit-release-officer-workspace');

        foreach (['issues', 'record', 'upcoming', 'history'] as $section) {
            $this->actingAs($officer)
                ->get("/provider/workspaces/releases/{$section}")
                ->assertOk()
                ->assertViewIs('provider-benefit-release-officer-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/releases',
            $officer->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_release_workspace_assigns_each_distribution_one_primary_work_state(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assignedProgram = $this->program($owner, 'Assigned Release Program', 'published');
        $outsideProgram = $this->program($owner, 'Outside Release Program', 'published');
        $officer = $this->benefitReleaseOfficer($owner, [$assignedProgram->id]);
        $recipient = $this->selectedRecipient($assignedProgram, $this->applicant('Release', 'Recipient'), 'accepted');
        $outsideRecipient = $this->selectedRecipient($outsideProgram, $this->applicant('Outside', 'Recipient'), 'accepted');

        $issueRelease = $this->benefitRelease($assignedProgram, $owner, 'Issue release', now()->subDays(3), 'completed');
        $issueRecord = $this->benefitReleaseRecord($issueRelease, $recipient, 'released');
        RecipientBenefitReceiptResponse::query()->create([
            'recipient_benefit_release_record_id' => $issueRecord->id,
            'scholarship_application_id' => $recipient->id,
            'applicant_id' => $recipient->applicant_id,
            'response_type' => 'issue',
            'issue_type' => 'incomplete_benefit',
            'issue_details' => 'One listed item was not included.',
            'status' => 'open',
            'responded_at' => now()->subDay(),
        ]);
        $dueRelease = $this->benefitRelease($assignedProgram, $owner, 'Due release', now()->subDay());
        $this->benefitReleaseRecord($dueRelease, $recipient, 'scheduled');
        $upcomingRelease = $this->benefitRelease($assignedProgram, $owner, 'Upcoming release', now()->addWeek());
        $this->benefitReleaseRecord($upcomingRelease, $recipient, 'prepared');
        $historyRelease = $this->benefitRelease($assignedProgram, $owner, 'Completed release', now()->subMonth(), 'completed');
        $this->benefitReleaseRecord($historyRelease, $recipient, 'released');
        $outsideRelease = $this->benefitRelease($outsideProgram, $owner, 'Outside release', now()->subDay());
        $this->benefitReleaseRecord($outsideRelease, $outsideRecipient, 'scheduled');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/releases/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Benefit release officer')
            ->assertJsonPath('workspace.program_access_mode', 'selected')
            ->assertJsonPath('summary.issues', 1)
            ->assertJsonPath('summary.record', 1)
            ->assertJsonPath('summary.upcoming', 1)
            ->assertJsonPath('summary.history', 1)
            ->assertJsonPath('next_task.id', $issueRelease->id)
            ->assertJsonPath('next_task.work_state', 'issues')
            ->assertJsonPath('releases.0.id', $issueRelease->id)
            ->assertJsonPath('releases.0.counts.open_issues', 1)
            ->assertJsonCount(1, 'releases')
            ->assertJsonCount(1, 'programs');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/releases/data?queue=record')
            ->assertOk()
            ->assertJsonPath('releases.0.id', $dueRelease->id)
            ->assertJsonPath('releases.0.counts.pending', 1)
            ->assertJsonCount(1, 'releases');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/releases/data?queue=upcoming')
            ->assertOk()
            ->assertJsonPath('releases.0.id', $upcomingRelease->id)
            ->assertJsonCount(1, 'releases');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/releases/data?queue=history')
            ->assertOk()
            ->assertJsonPath('releases.0.id', $historyRelease->id)
            ->assertJsonCount(1, 'releases');
    }

    public function test_release_desk_paginates_five_distributions_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Release Pagination Program', 'published');
        $officer = $this->benefitReleaseOfficer($owner, [$program->id]);
        $recipient = $this->selectedRecipient($program, $this->applicant('Release', 'Pagination'), 'accepted');

        foreach (range(1, 6) as $number) {
            $release = $this->benefitRelease(
                $program,
                $owner,
                "Upcoming release {$number}",
                now()->addDays(10 + $number),
            );
            $this->benefitReleaseRecord($release, $recipient, 'prepared');
        }

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/releases/data?queue=upcoming')
            ->assertOk()
            ->assertJsonCount(5, 'releases')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/releases/data?queue=upcoming&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'releases')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_non_release_staff_cannot_open_the_release_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/releases')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/releases/record')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/releases/data')->assertForbidden();
    }

    public function test_organization_profile_manager_enters_a_dedicated_profile_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $manager = $this->organizationProfileManager($owner);

        $this->actingAs($manager)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/organization-profile');

        $this->actingAs($manager)
            ->get('/provider/workspaces/organization-profile')
            ->assertOk()
            ->assertViewIs('provider-organization-profile-manager-workspace');

        $this->actingAs($manager)
            ->get('/provider/workspaces/organization-profile/readiness')
            ->assertOk()
            ->assertViewIs('provider-organization-profile-manager-workspace');

        $this->assertSame(
            '/provider/workspaces/organization-profile',
            $manager->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_profile_workspace_prioritizes_rejected_proof_and_reports_readiness_by_area(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update([
            'provider_name' => 'Focused Education Foundation',
            'provider_type' => 'foundation',
            'provider_description' => null,
            'logo_path' => null,
            'provider_contact_email' => null,
            'provider_contact_number' => null,
            'provider_address' => null,
            'service_area' => null,
            'legal_name' => null,
            'registration_authority' => null,
            'registration_number' => null,
            'verification_status' => 'rejected',
            'verification_notes' => 'Upload a clearer registration certificate.',
        ]);
        $manager = $this->organizationProfileManager($owner);

        $this->actingAs($manager)
            ->getJson('/provider/workspaces/organization-profile/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Organization profile manager')
            ->assertJsonPath('workspace.organization_name', 'Focused Education Foundation')
            ->assertJsonPath('profile.verification_status', 'rejected')
            ->assertJsonPath('next_task.state', 'attention')
            ->assertJsonPath('next_task.title', 'Replace the rejected verification proof')
            ->assertJsonPath('sections.0.key', 'identity')
            ->assertJsonPath('sections.0.complete', 2)
            ->assertJsonPath('sections.0.total', 4)
            ->assertJsonPath('sections.3.key', 'verification')
            ->assertJsonPath('summary.ready_sections', 0)
            ->assertJsonPath('summary.total_sections', 4)
            ->assertJsonPath('summary.document_count', 0)
            ->assertJsonCount(4, 'sections');
    }

    public function test_profile_workspace_distinguishes_missing_proof_from_pending_review(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update([
            'provider_name' => 'Ready Profile Foundation',
            'provider_type' => 'foundation',
            'provider_description' => 'Supports local learners.',
            'logo_path' => 'uploads/providers/ready-profile.png',
            'provider_contact_email' => 'support@ready-profile.test',
            'provider_contact_number' => '09175550001',
            'provider_address' => 'Antipolo City, Rizal',
            'service_area' => 'Rizal Province',
            'legal_name' => 'Ready Profile Foundation, Inc.',
            'registration_authority' => 'SEC',
            'registration_number' => 'SEC-2026-001',
            'verification_status' => 'pending',
        ]);
        $manager = $this->organizationProfileManager($owner);

        $this->actingAs($manager)
            ->getJson('/provider/workspaces/organization-profile/data')
            ->assertOk()
            ->assertJsonPath('profile.verification_status', 'unsubmitted')
            ->assertJsonPath('profile.verification_status_label', 'Proof not submitted')
            ->assertJsonPath('next_task.title', 'Complete Legal verification')
            ->assertJsonPath('sections.3.missing.0', 'Verification proof')
            ->assertJsonPath('summary.ready_sections', 3)
            ->assertJsonPath('summary.document_count', 0);
    }

    public function test_profile_manager_uses_owner_branding_and_can_manage_organization_proof(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update([
            'provider_name' => 'Owner Brand Foundation',
            'logo_path' => 'uploads/providers/owner-brand.png',
        ]);
        $manager = $this->organizationProfileManager($owner);
        $manager->providerProfile()->update(['logo_path' => 'uploads/providers/stale-staff-logo.png']);

        $this->actingAs($manager)
            ->getJson('/provider/profile/data')
            ->assertOk()
            ->assertJsonPath('user.provider_name', 'Owner Brand Foundation')
            ->assertJsonPath('user.provider_logo_path', 'uploads/providers/owner-brand.png')
            ->assertJsonPath('user.provider_logo_url', asset('uploads/providers/owner-brand.png'));

        $this->actingAs($manager)
            ->patchJson('/provider/profile', [
                'profile_section' => 'organization',
                'provider_name' => 'Updated Owner Brand Foundation',
                'provider_type' => 'foundation',
                'provider_contact_email' => 'support@owner-brand.test',
                'provider_contact_number' => '09175550002',
            ])
            ->assertOk();

        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => $owner->id,
            'provider_name' => 'Updated Owner Brand Foundation',
        ]);

        $this->actingAs($manager)
            ->post('/provider/verification-documents', [
                'document_type' => 'organization_registration',
                'document_file' => UploadedFile::fake()->createWithContent('registration.pdf', '%PDF-1.4 profile proof'),
                'terms_accepted' => '1',
            ], ['HTTP_ACCEPT' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseHas('provider_verification_documents', [
            'provider_id' => $owner->id,
            'uploaded_by' => $manager->id,
            'original_name' => 'registration.pdf',
            'status' => 'submitted',
        ]);
    }

    public function test_non_profile_staff_cannot_open_the_organization_profile_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/organization-profile')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/organization-profile/readiness')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/organization-profile/data')->assertForbidden();
    }

    public function test_team_administrator_enters_a_dedicated_access_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $administrator = $this->teamAdministrator($owner);

        $this->actingAs($administrator)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/team');

        $this->actingAs($administrator)
            ->get('/provider/team')
            ->assertRedirect('/provider/workspaces/team');

        $this->actingAs($administrator)
            ->get('/provider/workspaces/team')
            ->assertOk()
            ->assertViewIs('provider-team-administrator-workspace');

        foreach (['setup', 'active', 'suspended'] as $section) {
            $this->actingAs($administrator)
                ->get("/provider/workspaces/team/{$section}")
                ->assertOk()
                ->assertViewIs('provider-team-administrator-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/team',
            $administrator->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_team_access_desk_separates_setup_active_and_suspended_accounts(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $administrator = $this->teamAdministrator($owner);
        $pending = $this->teamAdministrator($owner, [
            'email_verified_at' => null,
            'must_reset_password' => true,
        ]);
        $pending->providerProfile()->update([
            'first_name' => 'Pending',
            'middle_initial' => 'P',
            'last_name' => 'Member',
        ]);
        $active = $this->teamAdministrator($owner);
        $suspended = $this->teamAdministrator($owner, [
            'account_status' => 'suspended',
            'suspended_at' => now(),
        ]);
        $protected = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'manager',
            'permissions' => ['manage_team', 'manage_programs'],
        ]);

        $response = $this->actingAs($administrator)
            ->getJson('/provider/workspaces/team/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Team administrator')
            ->assertJsonPath('summary.attention', 1)
            ->assertJsonPath('summary.active', 3)
            ->assertJsonPath('summary.suspended', 1)
            ->assertJsonPath('summary.total', 5)
            ->assertJsonPath('next_task.title', 'Finish access setup for Pending P. Member')
            ->assertJsonPath('accounts.0.id', $pending->id)
            ->assertJsonPath('accounts.0.work_state', 'attention')
            ->assertJsonPath('accounts.0.can_manage', true)
            ->assertJsonCount(1, 'accounts');

        $this->assertSame($pending->id, $response->json('accounts.0.id'));

        $activeResponse = $this->actingAs($administrator)
            ->getJson('/provider/workspaces/team/data?queue=active')
            ->assertOk()
            ->assertJsonCount(3, 'accounts');

        $protectedPayload = collect($activeResponse->json('accounts'))->firstWhere('id', $protected->id);
        $this->assertNotNull($protectedPayload);
        $this->assertFalse($protectedPayload['can_manage']);
        $this->assertNull($protectedPayload['action_url']);
        $this->assertSame('This account has access beyond your administrative scope.', $protectedPayload['protected_reason']);
        $this->assertNotNull(collect($activeResponse->json('accounts'))->firstWhere('id', $active->id));

        $this->actingAs($administrator)
            ->getJson('/provider/workspaces/team/data?queue=suspended')
            ->assertOk()
            ->assertJsonPath('accounts.0.id', $suspended->id)
            ->assertJsonPath('accounts.0.work_state', 'suspended')
            ->assertJsonCount(1, 'accounts');
    }

    public function test_team_access_registry_paginates_five_staff_accounts_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $administrator = $this->teamAdministrator($owner);

        foreach (range(1, 5) as $index) {
            $this->teamAdministrator($owner, [
                'email' => "registry.staff.{$index}@example.test",
                'username' => "registry_staff_{$index}",
            ]);
        }

        $firstPage = $this->actingAs($administrator)
            ->getJson('/provider/workspaces/team/data?queue=active')
            ->assertOk()
            ->assertJsonCount(5, 'accounts')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 5);

        $secondPage = $this->actingAs($administrator)
            ->getJson('/provider/workspaces/team/data?queue=active&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'accounts')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);

        $this->assertNotSame(
            $firstPage->json('accounts.0.id'),
            $secondPage->json('accounts.0.id'),
        );
    }

    public function test_non_team_staff_cannot_open_the_team_access_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/team')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/team/active')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/team/data')->assertForbidden();
    }

    public function test_support_staff_enters_a_dedicated_case_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $staff = $this->supportStaff($owner);

        $this->actingAs($staff)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/support');

        $this->actingAs($staff)
            ->get('/provider/reports')
            ->assertRedirect('/provider/workspaces/support');

        $this->actingAs($staff)
            ->get('/provider/workspaces/support')
            ->assertOk()
            ->assertViewIs('provider-support-staff-workspace');

        foreach (['needs-response', 'waiting', 'platform', 'resolved'] as $section) {
            $this->actingAs($staff)
                ->get("/provider/workspaces/support/{$section}")
                ->assertOk()
                ->assertViewIs('provider-support-staff-workspace');
        }

        $this->assertSame(
            '/provider/workspaces/support',
            $staff->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_support_desk_separates_case_states_and_respects_program_scope(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assignedProgram = $this->program($owner, 'Assigned Support Program', 'published');
        $outsideProgram = $this->program($owner, 'Outside Support Program', 'published');
        $staff = $this->supportStaff($owner, [$assignedProgram->id]);
        $applicant = $this->applicant('Support', 'Applicant');
        $needsAction = $this->supportReport($owner, $applicant, $assignedProgram, [
            'subject' => 'Clarify the required school record',
        ]);
        $waiting = $this->supportReport($owner, $applicant, $assignedProgram, [
            'subject' => 'Provider response already recorded',
            'provider_status' => 'resolved',
            'provider_resolved_by' => $staff->id,
            'provider_resolved_at' => now(),
        ]);
        $resolved = $this->supportReport($owner, $applicant, $assignedProgram, [
            'subject' => 'Completed applicant concern',
            'status' => 'resolved',
            'provider_status' => 'resolved',
            'provider_resolved_by' => $staff->id,
            'provider_resolved_at' => now(),
            'admin_status' => 'resolved',
            'admin_resolved_at' => now(),
            'resolved_by' => $staff->id,
            'resolved_at' => now(),
        ]);
        $submitted = SupportReport::query()->create([
            'applicant_id' => $staff->id,
            'provider_id' => $owner->id,
            'scholarship_id' => $assignedProgram->id,
            'assigned_role' => 'admin',
            'category' => 'technical',
            'subject' => 'Applications page does not refresh',
            'description' => 'The provider applications page remains stale after a refresh.',
            'status' => 'open',
            'provider_status' => 'not_required',
            'admin_status' => 'open',
        ]);
        $outside = $this->supportReport($owner, $applicant, $outsideProgram, [
            'subject' => 'Outside assigned support scope',
        ]);

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/support/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Support staff')
            ->assertJsonPath('workspace.program_access_mode', 'selected')
            ->assertJsonPath('summary.needs_action', 1)
            ->assertJsonPath('summary.waiting', 1)
            ->assertJsonPath('summary.submitted', 1)
            ->assertJsonPath('summary.resolved', 1)
            ->assertJsonPath('summary.total', 4)
            ->assertJsonPath('next_task.report_id', $needsAction->id)
            ->assertJsonPath('reports.0.id', $needsAction->id)
            ->assertJsonPath('reports.0.work_state', 'needs_action')
            ->assertJsonStructure(['reports' => [['applicant' => ['profile_photo_url']]]])
            ->assertJsonCount(1, 'reports')
            ->assertJsonCount(1, 'programs');

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/support/data?queue=waiting')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $waiting->id)
            ->assertJsonPath('reports.0.work_state', 'waiting')
            ->assertJsonCount(1, 'reports');

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/support/data?queue=submitted')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $submitted->id)
            ->assertJsonPath('reports.0.work_state', 'submitted')
            ->assertJsonCount(1, 'reports');

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/support/data?queue=resolved')
            ->assertOk()
            ->assertJsonPath('reports.0.id', $resolved->id)
            ->assertJsonPath('reports.0.work_state', 'resolved')
            ->assertJsonCount(1, 'reports');

        $this->actingAs($staff)
            ->patchJson("/provider/reports/{$outside->id}/status", ['status' => 'resolved'])
            ->assertForbidden();
    }

    public function test_support_case_desk_paginates_five_cases_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $program = $this->program($owner, 'Support Pagination Program', 'published');
        $staff = $this->supportStaff($owner, [$program->id]);
        $applicant = $this->applicant('Support', 'Pagination');

        foreach (range(1, 6) as $number) {
            $this->supportReport($owner, $applicant, $program, [
                'subject' => "Support pagination case {$number}",
            ]);
        }

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/support/data')
            ->assertOk()
            ->assertJsonCount(5, 'reports')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/support/data?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'reports')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_non_support_staff_cannot_open_the_provider_support_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/support')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/support/waiting')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/support/data')->assertForbidden();
    }

    public function test_billing_staff_enters_a_dedicated_service_request_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $staff = $this->billingStaff($owner);

        $this->actingAs($staff)
            ->get('/provider')
            ->assertRedirect('/provider/workspaces/billing');

        $this->actingAs($staff)
            ->get('/provider/workspaces/billing')
            ->assertOk()
            ->assertViewIs('provider-billing-staff-workspace');

        foreach (['action', 'active', 'waiting', 'completed'] as $section) {
            $this->actingAs($staff)
                ->get("/provider/workspaces/billing/{$section}")
                ->assertOk()
                ->assertViewIs('provider-billing-staff-workspace');
        }

        $this->actingAs($staff)
            ->get('/provider/workspaces/billing/services')
            ->assertOk()
            ->assertViewIs('provider-billing');

        $this->assertSame(
            '/provider/workspaces/billing',
            $staff->fresh('providerProfile')->publicPayload()['provider_workspace_url'],
        );
    }

    public function test_billing_desk_separates_action_active_waiting_and_completed_requests(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $staff = $this->billingStaff($owner);
        $pending = $this->servicePurchase($owner, ['status' => 'pending', 'fulfillment_status' => 'ready']);
        $needsInformation = $this->servicePurchase($owner, ['fulfillment_status' => 'needs_information']);
        $providerReview = $this->servicePurchase($owner, ['fulfillment_status' => 'provider_review']);
        $active = $this->servicePurchase($owner, ['fulfillment_status' => 'in_progress']);
        $waiting = $this->servicePurchase($owner, ['fulfillment_status' => 'ready']);
        $completed = $this->servicePurchase($owner, ['fulfillment_status' => 'completed']);
        $outsideOwner = User::factory()->create(['role' => 'provider']);
        $this->servicePurchase($outsideOwner, ['fulfillment_status' => 'provider_review']);

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/billing/data')
            ->assertOk()
            ->assertJsonPath('workspace.role', 'Billing staff')
            ->assertJsonPath('summary.needs_action', 3)
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.waiting', 1)
            ->assertJsonPath('summary.completed', 1)
            ->assertJsonPath('summary.total', 6)
            ->assertJsonPath('next_task.id', $providerReview->id)
            ->assertJsonCount(3, 'purchases');

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/billing/data?queue=active')
            ->assertOk()
            ->assertJsonPath('purchases.0.id', $active->id)
            ->assertJsonPath('purchases.0.work_state', 'active')
            ->assertJsonCount(1, 'purchases');

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/billing/data?queue=waiting')
            ->assertOk()
            ->assertJsonPath('purchases.0.id', $waiting->id)
            ->assertJsonCount(1, 'purchases');

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/billing/data?queue=completed')
            ->assertOk()
            ->assertJsonPath('purchases.0.id', $completed->id)
            ->assertJsonCount(1, 'purchases');

        $this->assertNotNull($pending);
        $this->assertNotNull($needsInformation);
    }

    public function test_service_request_desk_paginates_five_requests_per_page(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $staff = $this->billingStaff($owner);

        foreach (range(1, 6) as $number) {
            $this->servicePurchase($owner, [
                'plan_name' => "Pagination service {$number}",
                'status' => 'pending',
                'fulfillment_status' => 'ready',
            ]);
        }

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/billing/data')
            ->assertOk()
            ->assertJsonCount(5, 'purchases')
            ->assertJsonPath('pagination.per_page', 5)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 2);

        $this->actingAs($staff)
            ->getJson('/provider/workspaces/billing/data?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'purchases')
            ->assertJsonPath('pagination.from', 6)
            ->assertJsonPath('pagination.to', 6);
    }

    public function test_non_billing_staff_cannot_open_the_service_request_desk(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = $this->applicationReviewer($owner);

        $this->actingAs($reviewer)->get('/provider/workspaces/billing')->assertForbidden();
        $this->actingAs($reviewer)->get('/provider/workspaces/billing/active')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/workspaces/billing/data')->assertForbidden();
    }

    private function programCoordinator(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function applicationReviewer(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['verify_applications'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function selectionOfficer(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'selection_officer',
            'permissions' => ['manage_selection_activities'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function decisionOfficer(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'decision_officer',
            'permissions' => ['record_final_decisions'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function recipientOfficer(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'recipient_officer',
            'permissions' => ['manage_recipients'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function monitoringOfficer(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'monitoring_officer',
            'permissions' => ['manage_monitoring'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function benefitReleaseOfficer(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'benefit_release_officer',
            'permissions' => ['manage_benefit_releases'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function organizationProfileManager(User $owner): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'organization_profile_manager',
            'permissions' => ['manage_profile'],
        ]);
    }

    private function teamAdministrator(User $owner, array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'team_administrator',
            'permissions' => ['manage_team'],
            ...$attributes,
        ]);
    }

    private function supportStaff(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'support_staff',
            'permissions' => ['manage_reports'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function billingStaff(User $owner): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'billing_staff',
            'permissions' => ['manage_billing'],
        ]);
    }

    private function servicePurchase(User $owner, array $attributes = []): ProviderServicePurchase
    {
        return ProviderServicePurchase::query()->create([
            'provider_id' => $owner->id,
            'created_by' => $owner->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Assisted program setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'ready',
            'reference_number' => 'PS-'.strtoupper(fake()->unique()->bothify('########')),
            'paid_at' => now()->subDay(),
            ...$attributes,
        ]);
    }

    private function supportReport(
        User $owner,
        User $applicant,
        Scholarship $program,
        array $attributes = [],
    ): SupportReport {
        return SupportReport::query()->create([
            'applicant_id' => $applicant->id,
            'provider_id' => $owner->id,
            'scholarship_id' => $program->id,
            'assigned_role' => 'provider',
            'category' => 'program',
            'subject' => 'Applicant program concern',
            'description' => 'The applicant needs a clear response from the scholarship provider.',
            'status' => 'open',
            'provider_status' => 'open',
            'admin_status' => 'open',
            ...$attributes,
        ]);
    }

    private function benefitRelease(
        Scholarship $program,
        User $creator,
        string $title,
        mixed $releaseAt,
        string $status = 'scheduled',
    ): RecipientBenefitRelease {
        return RecipientBenefitRelease::query()->create([
            'scholarship_id' => $program->id,
            'created_by' => $creator->id,
            'title' => $title,
            'release_at' => $releaseAt,
            'benefit_description' => 'Learning support allowance',
            'amount' => 2500,
            'release_method' => 'bank_transfer',
            'requires_original_verification' => false,
            'status' => $status,
            'published_at' => now()->subWeek(),
        ]);
    }

    private function benefitReleaseRecord(
        RecipientBenefitRelease $release,
        ScholarshipApplication $application,
        string $status,
    ): RecipientBenefitReleaseRecord {
        return RecipientBenefitReleaseRecord::query()->create([
            'recipient_benefit_release_id' => $release->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'status' => $status,
            'originals_verified' => $status === 'released',
            'notes' => $status === 'released' ? 'Distribution confirmed by the provider.' : null,
            'recorded_by' => $status === 'released' ? $release->created_by : null,
            'recorded_at' => $status === 'released' ? now()->subDay() : null,
            'released_at' => $status === 'released' ? now()->subDay() : null,
        ]);
    }

    private function monitoringCycle(
        Scholarship $program,
        RecipientMonitoringPlan $plan,
        string $title,
        string $dueAt,
    ): RecipientMonitoringCycle {
        return RecipientMonitoringCycle::query()->create([
            'scholarship_id' => $program->id,
            'recipient_monitoring_plan_id' => $plan->id,
            'monitoring_plan_version' => $plan->version,
            'grace_period_days' => $plan->grace_period_days,
            'allow_exception_requests' => $plan->allow_exception_requests,
            'created_by' => $plan->created_by,
            'title' => $title,
            'period_type' => 'semester',
            'academic_period' => 'First semester',
            'school_year' => '2026-2027',
            'opens_at' => now()->subWeek()->toDateString(),
            'due_at' => $dueAt,
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'open',
            'published_at' => now(),
        ]);
    }

    private function selectedRecipient(
        Scholarship $program,
        User $applicant,
        ?string $agreementResponse = null,
    ): ScholarshipApplication {
        $application = $this->application($program, $applicant, 'complete', null, now()->subDays(5));
        $respondedAt = $agreementResponse ? now()->subDay() : null;
        $application->update([
            'status' => 'awarded',
            'application_state' => 'closed',
            'final_outcome' => 'selected',
            'outcome_at' => now()->subDays(3),
            'provider_contract_terms_snapshot' => [
                'program_title' => $program->title,
                'award_amount' => $program->award_amount,
                'recipient_expectation' => [],
            ],
            'provider_contract_terms_version' => 'recipient-agreement-v2-test',
            'student_response_status' => $agreementResponse,
            'student_responded_at' => $respondedAt,
            'student_response_terms_accepted_at' => $agreementResponse === 'accepted' ? $respondedAt : null,
            'provider_contract_terms_accepted_at' => $agreementResponse === 'accepted' ? $respondedAt : null,
        ]);

        return $application->fresh();
    }

    private function applicant(string $firstName, string $lastName): User
    {
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->update([
            'first_name' => $firstName,
            'middle_initial' => null,
            'last_name' => $lastName,
        ]);

        return $applicant->fresh('studentProfile');
    }

    private function application(
        Scholarship $program,
        User $applicant,
        string $stage,
        ?int $reviewerId,
        $submittedAt,
    ): ScholarshipApplication {
        return ScholarshipApplication::query()->create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'application_state' => 'active',
            'workflow_stage' => $stage,
            'assigned_reviewer_id' => $reviewerId,
            'submitted_at' => $submittedAt,
        ]);
    }

    private function program(User $owner, string $title, string $status, ?string $deadline = null): Scholarship
    {
        return Scholarship::query()->create([
            'provider_id' => $owner->id,
            'title' => $title,
            'description' => 'A program used to verify the coordinator workspace.',
            'status' => $status,
            'deadline' => $deadline,
        ]);
    }
}
