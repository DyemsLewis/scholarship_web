<?php

namespace Tests\Feature;

use App\Models\ApplicationStageSession;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantApplicationDetailContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_contract_returns_one_decision_and_a_focused_record(): void
    {
        [$provider, $applicant, , $application] = $this->application();

        $this->actingAs($applicant)
            ->get(route('dashboard.applications.show', $application))
            ->assertOk()
            ->assertSee('data-page="dashboardApplicationOverview"', false)
            ->assertSee('data-application-section="summary"', false);

        $response = $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application));

        $response
            ->assertOk()
            ->assertJsonPath('detail.version', 1)
            ->assertJsonPath('detail.view', 'summary')
            ->assertJsonPath('detail.identity.provider.name', 'Harborlight Education Foundation')
            ->assertJsonPath('detail.identity.provider.verified', true)
            ->assertJsonPath('detail.decision.state', 'action_required')
            ->assertJsonPath('detail.decision.actor', 'applicant')
            ->assertJsonPath('detail.progress.current_stage', 'screening')
            ->assertJsonCount(5, 'detail.navigation')
            ->assertJsonCount(4, 'detail.snapshot')
            ->assertJsonPath('application.status', 'submitted')
            ->assertJsonMissingPath('detail.identity.id');

        $this->assertSame($provider->id, $application->scholarship->provider_id);
    }

    public function test_each_focused_view_has_a_page_route_and_scoped_content(): void
    {
        [, $applicant, , $application] = $this->application();

        foreach (['submission', 'activities', 'outcome', 'history'] as $view) {
            $pageResponse = $this->actingAs($applicant)
                ->get(route('dashboard.applications.show.section', [
                    'application' => $application,
                    'section' => $view,
                ]))
                ->assertOk()
                ->assertSee('data-application-section="'.$view.'"', false);

            if ($view === 'submission') {
                $pageResponse->assertSee('data-page="dashboardApplicationSubmission"', false);
            }

            if ($view === 'activities') {
                $pageResponse->assertSee('data-page="dashboardApplicationActivities"', false);
            }

            if ($view === 'outcome') {
                $pageResponse->assertSee('data-page="dashboardApplicationOutcome"', false);
            }

            if ($view === 'history') {
                $pageResponse->assertSee('data-page="dashboardApplicationHistory"', false);
            }

            $this->actingAs($applicant)
                ->getJson(route('dashboard.applications.show.data', $application).'?view='.$view)
                ->assertOk()
                ->assertJsonPath('detail.view', $view)
                ->assertJsonPath('detail.navigation.0.key', 'summary')
                ->assertJsonStructure(['detail' => ['page', 'identity', 'decision', 'progress', 'content']]);
        }
    }

    public function test_new_contract_does_not_expose_internal_scoring_or_staff_notes(): void
    {
        [$provider, $applicant, , $application] = $this->application();
        $application->update([
            'review_notes' => 'PRIVATE REVIEW NOTE',
            'dss_breakdown' => ['private' => 'PRIVATE DSS VALUE'],
            'review_rubric_snapshot' => [[
                'key' => 'private_criterion',
                'label' => 'PRIVATE RUBRIC VALUE',
                'description' => 'Internal reviewer criterion.',
                'weight' => 100,
                'scoring_guide' => 'Provider use only.',
            ]],
            'rubric_scores' => ['private_criterion' => 99],
            'eligibility_breakdown' => ['private' => 'PRIVATE ELIGIBILITY VALUE'],
        ]);
        $application->statusHistories()->create([
            'changed_by' => $provider->id,
            'from_status' => 'submitted',
            'to_status' => 'under_review',
            'decision_reason' => 'internal_reason_code',
            'review_notes' => 'PRIVATE HISTORY NOTE',
            'changed_at' => now(),
        ]);

        foreach (['summary', 'submission', 'activities', 'outcome', 'history'] as $view) {
            $detail = $this->actingAs($applicant)
                ->getJson(route('dashboard.applications.show.data', $application).'?view='.$view)
                ->assertOk()
                ->json('detail');
            $encoded = json_encode($detail, JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString('PRIVATE REVIEW NOTE', $encoded);
            $this->assertStringNotContainsString('PRIVATE DSS VALUE', $encoded);
            $this->assertStringNotContainsString('PRIVATE RUBRIC VALUE', $encoded);
            $this->assertStringNotContainsString('PRIVATE ELIGIBILITY VALUE', $encoded);
            $this->assertStringNotContainsString('PRIVATE HISTORY NOTE', $encoded);
            $this->assertStringNotContainsString('internal_reason_code', $encoded);
        }
    }

    public function test_correction_request_becomes_the_single_next_action(): void
    {
        [$provider, $applicant, , $application] = $this->application();
        app(ApplicationWorkflowService::class)->requestCorrection(
            $application,
            $provider,
            'Replace the unreadable report card.',
            ['application_files'],
        );

        $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application).'?view=submission')
            ->assertOk()
            ->assertJsonPath('detail.decision.state', 'action_required')
            ->assertJsonPath('detail.decision.title', 'Complete the requested update')
            ->assertJsonPath('detail.decision.action.target', 'correction')
            ->assertJsonPath('detail.content.correction.message', 'Replace the unreadable report card.')
            ->assertJsonPath('detail.content.correction.targets.0.key', 'application_files')
            ->assertJsonPath('detail.permissions.can_respond_to_correction', true);
    }

    public function test_activities_contract_only_contains_published_applicant_details(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->application([
            'selection_stages' => ['screening', 'exam', 'decision'],
        ]);
        $application = app(ApplicationWorkflowService::class)
            ->recordStageResult($application, 'screening', 'passed', $provider);
        $published = $this->stageSession($scholarship, $provider, [
            'meeting_url' => 'https://meet.example.test/exam-room',
            'published_at' => now(),
        ]);
        $published->assignments()->create([
            'scholarship_application_id' => $application->id,
            'status' => 'assigned',
            'notes' => 'PRIVATE ASSIGNMENT NOTE',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
        ])->attendance()->create(['status' => 'pending']);
        $draft = $this->stageSession($scholarship, $provider, [
            'title' => 'PRIVATE DRAFT SESSION',
            'status' => 'draft',
            'published_at' => null,
        ]);
        $draft->assignments()->create([
            'scholarship_application_id' => $application->id,
            'status' => 'assigned',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application).'?view=activities')
            ->assertOk()
            ->assertJsonCount(1, 'detail.content.active')
            ->assertJsonPath('detail.content.active.0.type', 'exam')
            ->assertJsonPath('detail.content.active.0.online_url', 'https://meet.example.test/exam-room')
            ->assertJsonPath('detail.content.active.0.requires_applicant_acknowledgment', true)
            ->assertJsonPath('detail.decision.action.href', route('dashboard.applications.show.section', [
                'application' => $application,
                'section' => 'activities',
            ], false));

        $encoded = $response->getContent();
        $this->assertStringNotContainsString('PRIVATE ASSIGNMENT NOTE', $encoded);
        $this->assertStringNotContainsString('PRIVATE DRAFT SESSION', $encoded);
    }

    public function test_formal_application_handoff_is_part_of_the_activities_record(): void
    {
        [$provider, $applicant, , $application] = $this->application([
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'post_qualification_requirements' => "Original school ID\nPrinted application form",
            'handoff_mode' => 'hybrid',
            'handoff_instructions' => 'Bring the listed records to the foundation office.',
            'handoff_deadline' => now()->addWeeks(2)->toDateString(),
            'handoff_location_name' => 'Harborlight Student Desk',
            'handoff_location_address' => 'Antipolo City, Rizal',
            'handoff_url' => 'https://apply.example.test/harborlight',
            'contact_person' => 'Scholarship Coordinator',
            'contact_email' => 'scholarships@example.test',
        ]);
        $application = app(ApplicationWorkflowService::class)
            ->recordStageResult($application, 'screening', 'passed', $provider);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application).'?view=activities')
            ->assertOk()
            ->assertJsonPath('detail.content.formal_application.mode', 'hybrid')
            ->assertJsonPath('detail.content.formal_application.location_name', 'Harborlight Student Desk')
            ->assertJsonPath('detail.content.formal_application.requirements.0', 'Original school ID')
            ->assertJsonPath('detail.content.formal_application.contact.email', 'scholarships@example.test')
            ->assertJsonPath('detail.content.formal_application.notice', 'This step continues the provider application process. It is not a final scholarship award.');
    }

    public function test_selected_applicant_is_directed_to_the_agreement_then_recipient_support(): void
    {
        [$provider, $applicant, , $application] = $this->application([
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'recipient_agreement' => [
                'commitment_type' => 'reporting',
                'responsibilities' => 'Submit one academic update.',
                'required_evidence' => 'Official report card.',
                'release_conditions' => 'Release follows enrollment verification.',
                'duration' => 'One school year.',
                'noncompliance_consequence' => 'Support may be reviewed.',
                'exit_or_exception_process' => 'Contact the provider.',
            ],
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $application->update(['awarded_amount' => 12000]);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application).'?view=outcome')
            ->assertOk()
            ->assertJsonPath('detail.decision.title', 'Review the recipient agreement')
            ->assertJsonPath('detail.decision.action.target', 'agreement')
            ->assertJsonPath('detail.content.result.label', 'Selected')
            ->assertJsonPath('detail.content.agreement.can_respond', true)
            ->assertJsonPath('detail.permissions.can_respond_to_agreement', true);

        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'provider_contract_terms_accepted_at' => now(),
        ]);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application))
            ->assertOk()
            ->assertJsonPath('detail.decision.state', 'recipient_support')
            ->assertJsonPath('detail.decision.action.href', route('dashboard.monitoring.show', $application, false));
    }

    public function test_outcome_view_shows_only_one_pending_state_before_a_final_result(): void
    {
        [$provider, $applicant, , $application] = $this->application([
            'selection_stages' => ['screening', 'formal_application', 'decision'],
        ]);
        $application = app(ApplicationWorkflowService::class)
            ->recordStageResult($application, 'screening', 'passed', $provider);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application).'?view=outcome')
            ->assertOk()
            ->assertJsonPath('detail.content.available', false)
            ->assertJsonPath('detail.content.result.key', null)
            ->assertJsonPath('detail.content.pending.title', 'No final result yet')
            ->assertJsonPath('detail.content.agreement', null)
            ->assertJsonPath('detail.content.recipient_support', null);
    }

    public function test_prescreening_rejection_is_a_recorded_outcome_instead_of_a_pending_result(): void
    {
        [$provider, $applicant, , $application] = $this->application();
        $application = app(ApplicationWorkflowService::class)
            ->recordStageResult($application, 'screening', 'not_passed', $provider);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application).'?view=outcome')
            ->assertOk()
            ->assertJsonPath('detail.content.available', true)
            ->assertJsonPath('detail.content.result.key', 'not_qualified')
            ->assertJsonPath('detail.content.result.label', 'Not qualified')
            ->assertJsonPath('detail.content.result.tone', 'negative')
            ->assertJsonPath('detail.content.pending', null)
            ->assertJsonPath('detail.content.agreement', null);
    }

    public function test_waitlisted_and_withdrawn_records_have_complete_outcome_states(): void
    {
        [$provider, $waitlistedApplicant, , $waitlistedApplication] = $this->application([
            'selection_stages' => ['screening', 'formal_application', 'decision'],
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $waitlistedApplication = $workflow->recordStageResult($waitlistedApplication, 'screening', 'passed', $provider);
        $waitlistedApplication = $workflow->recordStageResult($waitlistedApplication, 'formal_application', 'passed', $provider);
        $waitlistedApplication = $workflow->recordFinalOutcome($waitlistedApplication, 'waitlisted', $provider);

        $this->actingAs($waitlistedApplicant)
            ->getJson(route('dashboard.applications.show.data', $waitlistedApplication).'?view=outcome')
            ->assertOk()
            ->assertJsonPath('detail.content.available', true)
            ->assertJsonPath('detail.content.result.key', 'waitlisted')
            ->assertJsonPath('detail.content.result.tone', 'attention')
            ->assertJsonPath('detail.content.result.waitlist_position', 1)
            ->assertJsonPath('detail.content.pending', null)
            ->assertJsonPath('detail.content.agreement', null)
            ->assertJsonPath('detail.content.recipient_support', null)
            ->assertJson(fn ($json) => $json
                ->whereType('detail.content.result.recorded_at', 'string')
                ->etc());

        [, $withdrawnApplicant, , $withdrawnApplication] = $this->application();
        $withdrawnApplication = $workflow->withdraw($withdrawnApplication, $withdrawnApplicant, 'I no longer want to continue.');

        $this->actingAs($withdrawnApplicant)
            ->getJson(route('dashboard.applications.show.data', $withdrawnApplication).'?view=outcome')
            ->assertOk()
            ->assertJsonPath('detail.content.available', true)
            ->assertJsonPath('detail.content.result.key', 'withdrawn')
            ->assertJsonPath('detail.content.result.label', 'Withdrawn')
            ->assertJsonPath('detail.content.result.tone', 'neutral')
            ->assertJsonPath('detail.content.pending', null)
            ->assertJsonPath('detail.content.agreement', null)
            ->assertJsonPath('detail.content.recipient_support', null);
    }

    public function test_history_contract_returns_concise_applicant_facing_milestones(): void
    {
        [$provider, $applicant, , $application] = $this->application([
            'selection_stages' => ['screening', 'exam', 'decision'],
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'exam', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);

        $response = $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application).'?view=history')
            ->assertOk()
            ->assertJsonPath('detail.content.summary.stage_count', 3)
            ->assertJsonPath('detail.content.events.0.category', 'outcome')
            ->assertJsonPath('detail.content.events.0.title', 'Selected for scholarship support')
            ->assertJsonFragment([
                'title' => 'Pre-screening completed',
                'category_label' => 'Selection stage',
            ])
            ->assertJsonFragment([
                'title' => 'Exam completed',
                'category_label' => 'Selection stage',
            ])
            ->assertJsonFragment([
                'title' => 'Formal application completed',
                'category_label' => 'Selection stage',
            ]);

        $events = collect($response->json('detail.content.events'));

        $this->assertSame(5, $events->count());
        $this->assertSame(1, $events->where('category', 'outcome')->count());
        $this->assertSame(0, $events->where('title', 'Status changed to Awarded')->count());
        $this->assertTrue($events->every(fn (array $event): bool => array_key_exists('details', $event)));
    }

    public function test_history_does_not_repeat_a_failed_stage_as_a_second_outcome_event(): void
    {
        [$provider, $applicant, , $application] = $this->application([
            'selection_stages' => ['screening', 'exam', 'decision'],
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'exam', 'not_passed', $provider);

        $response = $this->actingAs($applicant)
            ->getJson(route('dashboard.applications.show.data', $application).'?view=history')
            ->assertOk()
            ->assertJsonPath('detail.content.summary.event_count', 3)
            ->assertJsonFragment([
                'title' => 'Exam not passed',
                'category_label' => 'Selection stage',
            ]);

        $events = collect($response->json('detail.content.events'));

        $this->assertSame(0, $events->where('category', 'outcome')->count());
        $this->assertSame(1, $events->where('title', 'Exam not passed')->count());
    }

    public function test_applicant_cannot_open_another_applicants_contract_or_section(): void
    {
        [, , , $application] = $this->application();
        $otherApplicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($otherApplicant)
            ->getJson(route('dashboard.applications.show.data', $application))
            ->assertForbidden();

        $this->actingAs($otherApplicant)
            ->get(route('dashboard.applications.show.section', [
                'application' => $application,
                'section' => 'submission',
            ]))
            ->assertForbidden();
    }

    private function application(array $scholarshipOverrides = []): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'provider_name' => 'Harborlight Education Foundation',
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Harborlight Student Grant',
            'description' => 'A focused application-detail contract test program.',
            'selection_stages' => ['screening', 'formal_application', 'exam', 'interview', 'decision'],
            'application_mode' => 'hybrid',
            'deadline' => now()->addMonth()->toDateString(),
            'requirements' => 'Latest report card or grades',
            'status' => 'published',
            ...$scholarshipOverrides,
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'document_checklist' => ['Latest report card or grades'],
            'application_answers' => [[
                'question_id' => 'study_goal',
                'prompt' => 'How will the scholarship help your studies?',
                'required' => true,
                'answer' => 'It will cover school materials and transportation.',
            ]],
            'submitted_at' => now(),
        ]);
        $application = app(ApplicationWorkflowService::class)->start($application);

        return [$provider, $applicant, $scholarship, $application];
    }

    private function stageSession(
        Scholarship $scholarship,
        User $provider,
        array $overrides = [],
    ): ApplicationStageSession {
        return $scholarship->stageSessions()->create([
            'stage_key' => 'exam',
            'title' => 'Harborlight qualifying exam',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'timezone' => 'Asia/Manila',
            'mode' => 'online',
            'instructions' => 'Join ten minutes early and keep your school ID ready.',
            'status' => 'scheduled',
            'proof_required' => true,
            'published_at' => now(),
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
            ...$overrides,
        ]);
    }
}
