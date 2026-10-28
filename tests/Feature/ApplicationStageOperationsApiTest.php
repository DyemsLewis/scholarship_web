<?php

namespace Tests\Feature;

use App\Models\ApplicationStageEvidence;
use App\Models\ApplicationStageSession;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationStageOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_selection_officer_can_create_and_publish_an_encrypted_online_session(): void
    {
        [$owner, $officer, $program] = $this->programContext();

        $response = $this->actingAs($officer)->postJson(
            "/provider/scholarships/{$program->id}/stage-sessions",
            $this->sessionPayload([
                'mode' => 'online',
                'meeting_url' => 'https://meet.example.test/exam-room',
                'venue' => null,
                'latitude' => 14.5861,
                'longitude' => 121.1756,
            ]),
        )->assertCreated()
            ->assertJsonPath('session.status', 'draft')
            ->assertJsonPath('session.mode', 'online')
            ->assertJsonPath('session.latitude', null)
            ->assertJsonPath('session.longitude', null)
            ->assertJsonPath('session.meeting_url', 'https://meet.example.test/exam-room');

        $session = ApplicationStageSession::findOrFail($response->json('session.id'));
        $this->assertNotSame(
            'https://meet.example.test/exam-room',
            DB::table('application_stage_sessions')->where('id', $session->id)->value('meeting_url'),
        );

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/publish")
            ->assertOk()
            ->assertJsonPath('session.status', 'scheduled');

        $this->assertNotNull($session->fresh()->published_at);
        $this->assertDatabaseHas('application_stage_audit_events', [
            'application_stage_session_id' => $session->id,
            'event_type' => 'session_published',
            'actor_id' => $officer->id,
        ]);

        $reviewer = $this->providerStaff($owner, 'verify_applications');
        $this->actingAs($reviewer)
            ->getJson("/provider/scholarships/{$program->id}/stage-sessions")
            ->assertForbidden();
    }

    public function test_onsite_session_pin_can_be_saved_and_reloaded_for_editing(): void
    {
        [, $officer, $program] = $this->programContext();
        $session = $this->createSession($officer, $program, ['latitude' => 14.5861, 'longitude' => 121.1756]);
        $this->assertSame('14.5861000', $session->latitude);
        $this->actingAs($officer)->getJson("/provider/workspaces/selection/sessions/{$session->id}/edit/data")
            ->assertOk()->assertJsonPath('session.longitude', '121.1756000');

        $this->patchJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}", $this->sessionPayload([
            'latitude' => 14.6, 'longitude' => 121.2,
        ]))->assertOk()->assertJsonPath('session.latitude', '14.6000000');
        $this->postJson("/provider/scholarships/{$program->id}/stage-sessions", $this->sessionPayload(['latitude' => 14.5]))
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');
    }

    public function test_draft_capacity_cannot_be_reduced_below_assigned_applicants(): void
    {
        [, $officer, $program] = $this->programContext();
        $session = $this->createSession($officer, $program, ['capacity' => 3]);
        $first = $this->applicationAtExam($program);
        $second = $this->applicationAtExam($program);
        $this->actingAs($officer)->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments", [
            'application_ids' => [$first->id, $second->id],
        ])->assertCreated();

        $url = "/provider/scholarships/{$program->id}/stage-sessions/{$session->id}";
        $this->patchJson($url, $this->sessionPayload(['capacity' => 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('capacity');
        $this->assertSame(3, $session->fresh()->capacity);
        $this->patchJson($url, $this->sessionPayload(['capacity' => 2]))
            ->assertOk()->assertJsonPath('session.capacity.limit', 2);
        $this->patchJson($url, $this->sessionPayload(['capacity' => null]))
            ->assertOk()->assertJsonPath('session.capacity.limit', null);
    }

    public function test_session_end_must_be_after_start_on_both_create_and_edit(): void
    {
        [, $officer, $program] = $this->programContext();
        $session = $this->createSession($officer, $program);
        $payload = $this->sessionPayload(['ends_at' => now()->addWeek()->toIso8601String()]);
        $this->actingAs($officer)->postJson("/provider/scholarships/{$program->id}/stage-sessions", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        $this->patchJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}", $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_assignment_requires_current_stage_and_never_advances_the_application(): void
    {
        [, $officer, $program] = $this->programContext();
        $eligible = $this->applicationAtExam($program);
        $wrongStage = $this->submittedApplication($program);
        $session = $this->createSession($officer, $program, ['capacity' => 2]);

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments", [
                'application_ids' => [$eligible->id],
            ])
            ->assertCreated()
            ->assertJsonPath('assignment_ids.0', fn ($id): bool => is_int($id));

        $eligible->refresh();
        $this->assertSame('exam', $eligible->workflow_stage);
        $this->assertSame('current', $eligible->stageProgresses()->where('stage_key', 'exam')->value('status'));
        $this->assertSame('scheduled', $eligible->stageProgresses()->where('stage_key', 'exam')->value('operational_status'));
        $this->assertNull($eligible->stageProgresses()->where('stage_key', 'exam')->value('result'));

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments", [
                'application_ids' => [$wrongStage->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('application_ids');

        $this->assertDatabaseCount('application_stage_assignments', 1);
        $this->assertSame('screening', $wrongStage->fresh()->workflow_stage);
    }

    public function test_selection_session_workspace_separates_sessions_unassigned_applicants_and_setup(): void
    {
        [, $officer, $program] = $this->programContext();
        $application = $this->applicationAtExam($program);
        $session = $this->createSession($officer, $program);

        $this->actingAs($officer)
            ->get('/provider/workspaces/selection')
            ->assertOk()
            ->assertViewIs('provider-selection-officer-dashboard');
        $this->actingAs($officer)
            ->get('/provider/workspaces/selection/sessions/create')
            ->assertOk()
            ->assertViewIs('provider-selection-session-form');
        $this->actingAs($officer)
            ->get("/provider/workspaces/selection/sessions/{$session->id}")
            ->assertOk()
            ->assertViewIs('provider-selection-session-record');
        $this->actingAs($officer)
            ->get("/provider/workspaces/selection/sessions/{$session->id}/edit")
            ->assertOk()
            ->assertViewIs('provider-selection-session-form');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/sessions/data?queue=active')
            ->assertOk()
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('sessions.0.id', $session->id)
            ->assertJsonPath('sessions.0.program.id', $program->id);
        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/sessions/data?queue=unassigned')
            ->assertOk()
            ->assertJsonPath('summary.unassigned', 1)
            ->assertJsonPath('applications.0.id', $application->id)
            ->assertJsonPath('applications.0.action.label', 'Create session');
        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/sessions/create/data')
            ->assertOk()
            ->assertJsonPath('programs.0.id', $program->id)
            ->assertJsonPath('programs.0.stages.0.key', 'exam');
        $this->actingAs($officer)
            ->getJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/candidates")
            ->assertOk()
            ->assertJsonPath('candidates.0.application_id', $application->id);
    }

    public function test_formal_application_is_reviewable_without_a_session_but_can_use_an_optional_appointment(): void
    {
        [, $officer, $program] = $this->programContext();
        $application = $this->applicationAtExam($program);
        $application = app(ApplicationWorkflowService::class)->recordStageResult(
            $application,
            'exam',
            'passed',
            $program->provider,
        );

        $this->assertSame('formal_application', $application->workflow_stage);
        $this->assertSame(
            'ready_for_review',
            $application->stageProgresses()->where('stage_key', 'formal_application')->value('operational_status'),
        );

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/sessions/data?queue=unassigned')
            ->assertOk()
            ->assertJsonPath('summary.unassigned', 0)
            ->assertJsonCount(0, 'applications');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/sessions/data?queue=results')
            ->assertOk()
            ->assertJsonPath('summary.results', 1)
            ->assertJsonPath('applications.0.id', $application->id)
            ->assertJsonPath('applications.0.action.label', 'Review formal application');

        $this->actingAs($officer)
            ->getJson('/provider/workspaces/selection/dashboard/data')
            ->assertOk()
            ->assertJsonPath('metrics.1.value', 0)
            ->assertJsonPath('metrics.2.value', 1)
            ->assertJsonPath('sections.1.items.0.status.label', 'Ready for review')
            ->assertJsonPath('sections.1.items.0.action.label', 'Review formal application');

        $session = $this->createSession($officer, $program, [
            'stage_key' => 'formal_application',
            'title' => 'Optional document appointment',
            'proof_required' => false,
        ]);
        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments", [
                'application_ids' => [$application->id],
            ])
            ->assertCreated();

        $this->assertSame(
            'scheduled',
            $application->stageProgresses()->where('stage_key', 'formal_application')->value('operational_status'),
        );

        $this->actingAs($officer)
            ->patchJson("/provider/applications/{$application->id}/stages/formal_application/result", [
                'result' => 'passed',
                'notes' => 'This should wait for the assigned appointment.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('result');

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/cancel", [
                'reason' => 'The applicant can complete the formal review without an appointment.',
            ])
            ->assertOk();

        $this->assertSame(
            'ready_for_review',
            $application->stageProgresses()->where('stage_key', 'formal_application')->value('operational_status'),
        );

        $this->actingAs($officer)
            ->patchJson("/provider/applications/{$application->id}/stages/formal_application/result", [
                'result' => 'passed',
                'notes' => 'Formal requirements reviewed without an appointment.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'decision');
    }

    public function test_publishing_and_rescheduling_notify_the_applicant_and_preserve_history(): void
    {
        [, $officer, $program] = $this->programContext();
        $application = $this->applicationAtExam($program);
        $first = $this->createSession($officer, $program, ['title' => 'Morning examination']);
        $second = $this->createSession($officer, $program, [
            'title' => 'Afternoon examination',
            'starts_at' => now()->addDays(8)->toIso8601String(),
            'ends_at' => now()->addDays(8)->addHours(2)->toIso8601String(),
        ]);

        $assignResponse = $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$first->id}/assignments", [
                'application_ids' => [$application->id],
            ])
            ->assertCreated();
        $assignmentId = $assignResponse->json('assignment_ids.0');

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$first->id}/publish")
            ->assertOk();
        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$second->id}/publish")
            ->assertOk();

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $application->applicant_id,
            'deduplication_key' => "stage-session-assignment-{$assignmentId}-scheduled",
        ]);

        $response = $this->actingAs($officer)
            ->patchJson(
                "/provider/scholarships/{$program->id}/stage-sessions/{$first->id}/assignments/{$assignmentId}/reschedule",
                [
                    'target_session_id' => $second->id,
                    'notes' => 'Applicant has a documented schedule conflict.',
                ],
            )
            ->assertOk();
        $replacementId = $response->json('assignment_id');

        $this->assertDatabaseHas('application_stage_assignments', [
            'id' => $assignmentId,
            'status' => 'rescheduled',
        ]);
        $this->assertDatabaseHas('application_stage_assignments', [
            'id' => $replacementId,
            'previous_assignment_id' => $assignmentId,
            'application_stage_session_id' => $second->id,
            'status' => 'assigned',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $application->applicant_id,
            'deduplication_key' => "stage-session-assignment-{$replacementId}-scheduled",
        ]);
        $this->assertSame('exam', $application->fresh()->workflow_stage);
    }

    public function test_attendance_and_proof_are_required_before_completion_but_do_not_record_an_outcome(): void
    {
        [, $officer, $program] = $this->programContext();
        $application = $this->applicationAtExam($program);
        $session = $this->createSession($officer, $program);
        $assignmentId = $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments", [
                'application_ids' => [$application->id],
            ])
            ->assertCreated()
            ->json('assignment_ids.0');
        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/publish")
            ->assertOk();

        $this->actingAs($officer)
            ->patchJson(
                "/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments/{$assignmentId}/attendance",
                ['attendance_status' => 'present'],
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendance_status');

        $session->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $this->actingAs($officer)
            ->patchJson(
                "/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments/{$assignmentId}/attendance",
                ['attendance_status' => 'present', 'notes' => 'School ID checked.'],
            )
            ->assertOk()
            ->assertJsonPath('attendance.status', 'present');

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/complete")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('evidence');

        $this->actingAs($officer)
            ->post(
                "/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/evidence",
                [
                    'evidence_file' => UploadedFile::fake()->create('attendance-sheet.pdf', 120, 'application/pdf'),
                    'category' => 'attendance_sheet',
                    'event_date' => now()->toDateString(),
                ],
                ['Accept' => 'application/json'],
            )
            ->assertCreated();

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/complete")
            ->assertOk()
            ->assertJsonPath('session.status', 'completed');

        $this->actingAs($officer)
            ->getJson("/provider/applications/{$application->id}/stage-readiness")
            ->assertOk()
            ->assertJsonPath('readiness.can_record_result', true)
            ->assertJsonPath('readiness.attendance_status', 'present')
            ->assertJsonPath('readiness.proof_count', 1)
            ->assertJsonPath('readiness.session.id', $session->id);

        $application->refresh();
        $progress = $application->stageProgresses()->where('stage_key', 'exam')->firstOrFail();
        $this->assertSame('exam', $application->workflow_stage);
        $this->assertSame('current', $progress->status);
        $this->assertSame('ready_for_review', $progress->operational_status);
        $this->assertNull($progress->result);
        $this->assertNull($progress->outcome_key);

        $this->actingAs($officer)
            ->patchJson("/provider/applications/{$application->id}/stages/exam/result", [
                'result' => 'passed',
                'notes' => 'Attendance and proof reviewed.',
            ])
            ->assertOk();

        $this->assertSame('formal_application', $application->fresh()->workflow_stage);
    }

    public function test_replacing_proof_preserves_versions_and_cross_provider_access_is_denied(): void
    {
        [$owner, $officer, $program] = $this->programContext();
        $session = $this->createSession($officer, $program);

        $firstId = $this->uploadEvidence($officer, $program, $session, 'first-proof.pdf');
        $secondId = $this->uploadEvidence($officer, $program, $session, 'replacement-proof.pdf');
        $first = ApplicationStageEvidence::findOrFail($firstId);
        $second = ApplicationStageEvidence::findOrFail($secondId);

        $this->assertFalse($first->is_current);
        $this->assertTrue($second->is_current);
        $this->assertSame($first->id, $second->supersedes_evidence_id);
        Storage::disk('local')->assertExists($first->path);
        Storage::disk('local')->assertExists($second->path);

        $this->actingAs($officer)
            ->get("/provider/stage-evidence/{$second->id}/view")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $otherOwner = User::factory()->create(['role' => 'provider']);
        $otherOfficer = $this->providerStaff($otherOwner, 'manage_selection_activities');
        $this->actingAs($otherOfficer)
            ->get("/provider/stage-evidence/{$second->id}/view")
            ->assertForbidden();

        $reviewer = $this->providerStaff($owner, 'verify_applications');
        $this->actingAs($reviewer)
            ->get("/provider/stage-evidence/{$second->id}/view")
            ->assertForbidden();
    }

    public function test_cross_program_session_and_assignment_ids_are_not_accepted(): void
    {
        [, $officer, $program] = $this->programContext();
        [, $otherOfficer, $otherProgram] = $this->programContext();
        $application = $this->applicationAtExam($program);
        $session = $this->createSession($officer, $program);
        $otherSession = $this->createSession($otherOfficer, $otherProgram);
        $otherApplication = $this->applicationAtExam($otherProgram);
        $otherAssignment = $this->actingAs($otherOfficer)
            ->postJson("/provider/scholarships/{$otherProgram->id}/stage-sessions/{$otherSession->id}/assignments", [
                'application_ids' => [$otherApplication->id],
            ])
            ->assertCreated()
            ->json('assignment_ids.0');

        $this->actingAs($officer)
            ->getJson("/provider/scholarships/{$program->id}/stage-sessions/{$otherSession->id}")
            ->assertNotFound();

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments", [
                'application_ids' => [$application->id],
            ])
            ->assertCreated();

        $this->actingAs($officer)
            ->patchJson(
                "/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments/{$otherAssignment}/attendance",
                ['attendance_status' => 'present'],
            )
            ->assertNotFound();
    }

    public function test_cancelling_a_session_returns_applicants_to_waiting_for_schedule(): void
    {
        [, $officer, $program] = $this->programContext();
        $application = $this->applicationAtExam($program);
        $session = $this->createSession($officer, $program);
        $assignmentId = $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/assignments", [
                'application_ids' => [$application->id],
            ])
            ->assertCreated()
            ->json('assignment_ids.0');
        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/publish")
            ->assertOk();

        $this->actingAs($officer)
            ->postJson("/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/cancel", [
                'reason' => 'The testing venue is unavailable.',
            ])
            ->assertOk()
            ->assertJsonPath('session.status', 'cancelled');

        $this->assertDatabaseHas('application_stage_assignments', [
            'id' => $assignmentId,
            'status' => 'cancelled',
        ]);
        $this->assertSame(
            'waiting_for_schedule',
            $application->stageProgresses()->where('stage_key', 'exam')->value('operational_status'),
        );
        $this->assertSame('exam', $application->fresh()->workflow_stage);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $application->applicant_id,
            'deduplication_key' => "stage-session-assignment-{$assignmentId}-cancelled",
        ]);
    }

    /** @return array{User, User, Scholarship} */
    private function programContext(): array
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        $officer = $this->providerStaff($owner, 'manage_selection_activities');
        $program = Scholarship::create([
            'provider_id' => $owner->id,
            'title' => fake()->unique()->sentence(4),
            'description' => 'Program used to test normalized stage operations.',
            'selection_stages' => ['screening', 'exam', 'formal_application', 'decision'],
            'status' => 'published',
        ]);

        return [$owner, $officer, $program];
    }

    private function providerStaff(User $owner, string $permission): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'permissions' => [$permission],
        ]);
    }

    private function submittedApplication(Scholarship $program): ScholarshipApplication
    {
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'submitted',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);

        return app(ApplicationWorkflowService::class)->start($application);
    }

    private function applicationAtExam(Scholarship $program): ScholarshipApplication
    {
        $application = $this->submittedApplication($program);

        return app(ApplicationWorkflowService::class)->recordStageResult(
            $application,
            'screening',
            'passed',
            $program->provider,
        );
    }

    private function createSession(User $officer, Scholarship $program, array $overrides = []): ApplicationStageSession
    {
        $response = $this->actingAs($officer)
            ->postJson(
                "/provider/scholarships/{$program->id}/stage-sessions",
                $this->sessionPayload($overrides),
            )
            ->assertCreated();

        return ApplicationStageSession::findOrFail($response->json('session.id'));
    }

    private function sessionPayload(array $overrides = []): array
    {
        return [
            'stage_key' => 'exam',
            'title' => 'Provider examination',
            'starts_at' => now()->addWeek()->toIso8601String(),
            'ends_at' => now()->addWeek()->addHours(2)->toIso8601String(),
            'timezone' => 'Asia/Manila',
            'mode' => 'onsite',
            'venue' => 'Tulay Aral Testing Center',
            'location_address' => 'Antipolo City, Rizal',
            'capacity' => 30,
            'instructions' => 'Bring a recent school ID and arrive 30 minutes early.',
            'proof_required' => true,
            ...$overrides,
        ];
    }

    private function uploadEvidence(
        User $officer,
        Scholarship $program,
        ApplicationStageSession $session,
        string $name,
    ): int {
        return (int) $this->actingAs($officer)
            ->post(
                "/provider/scholarships/{$program->id}/stage-sessions/{$session->id}/evidence",
                [
                    'evidence_file' => UploadedFile::fake()->create($name, 100, 'application/pdf'),
                    'category' => 'attendance_sheet',
                    'event_date' => now()->toDateString(),
                ],
                ['Accept' => 'application/json'],
            )
            ->assertCreated()
            ->json('evidence.id');
    }
}
