<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicationStageCatalog;
use App\Models\ApplicationStageAuditEvent;
use App\Models\ApplicationStageSession;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApplicationStageFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_catalog_defines_specific_outcomes_and_proof_defaults(): void
    {
        $this->assertSame(
            ['qualified', 'needs_correction', 'not_qualified'],
            ApplicationStageCatalog::outcomesFor('screening'),
        );
        $this->assertSame(
            ['passed', 'not_passed', 'absent', 'reschedule', 'waived'],
            ApplicationStageCatalog::outcomesFor('exam'),
        );
        $this->assertSame(
            ['recommended', 'not_recommended', 'absent', 'reschedule', 'waived'],
            ApplicationStageCatalog::outcomesFor('interview'),
        );
        $this->assertSame(
            ['selected', 'waitlisted', 'not_selected'],
            ApplicationStageCatalog::outcomesFor('decision'),
        );
        $this->assertTrue(ApplicationStageCatalog::supportsSessions('formal_application'));
        $this->assertTrue(ApplicationStageCatalog::supportsSessions('exam'));
        $this->assertFalse(ApplicationStageCatalog::supportsSessions('decision'));
        $this->assertTrue(ApplicationStageCatalog::requiresProofByDefault('exam'));
        $this->assertTrue(ApplicationStageCatalog::requiresProofByDefault('interview'));
        $this->assertFalse(ApplicationStageCatalog::requiresProofByDefault('screening'));
    }

    public function test_program_can_hold_multiple_exam_sessions_with_different_dates_and_locations(): void
    {
        [$provider, $program] = $this->program();
        $firstSession = $program->stageSessions()->create([
            'stage_key' => 'exam',
            'title' => 'Morning examination - Antipolo',
            'starts_at' => now()->addWeek()->setTime(9, 0),
            'ends_at' => now()->addWeek()->setTime(11, 0),
            'timezone' => 'Asia/Manila',
            'mode' => 'onsite',
            'venue' => 'Antipolo Learning Center',
            'location_address' => 'Antipolo City, Rizal',
            'capacity' => 40,
            'instructions' => 'Bring a recent school ID.',
            'status' => 'scheduled',
            'proof_required' => true,
            'published_at' => now(),
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);
        $meetingUrl = 'https://meet.example.test/exam-room-b';
        $secondSession = $program->stageSessions()->create([
            'stage_key' => 'exam',
            'title' => 'Afternoon examination - Online',
            'starts_at' => now()->addWeeks(2)->setTime(13, 0),
            'ends_at' => now()->addWeeks(2)->setTime(15, 0),
            'timezone' => 'Asia/Manila',
            'mode' => 'online',
            'meeting_url' => $meetingUrl,
            'capacity' => 25,
            'instructions' => 'Open the protected meeting link ten minutes early.',
            'status' => 'scheduled',
            'proof_required' => true,
            'published_at' => now(),
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);

        $this->assertSame(2, $program->stageSessions()->where('stage_key', 'exam')->count());
        $this->assertNotSame(
            $firstSession->starts_at->toDateTimeString(),
            $secondSession->starts_at->toDateTimeString(),
        );
        $this->assertSame('Antipolo Learning Center', $firstSession->venue);
        $this->assertSame($meetingUrl, $secondSession->fresh()->meeting_url);
        $this->assertNotSame(
            $meetingUrl,
            DB::table('application_stage_sessions')
                ->where('id', $secondSession->id)
                ->value('meeting_url'),
        );
        $this->assertSame(25, $secondSession->capacity);
    }

    public function test_assignments_attendance_evidence_and_audits_do_not_advance_the_application(): void
    {
        [$provider, $program, $application] = $this->applicationAtExam();
        $canonicalBefore = $application->only([
            'status',
            'application_state',
            'workflow_stage',
            'final_outcome',
        ]);
        $firstSession = $this->stageSession($program, $provider, 'First examination schedule', now()->addWeek());
        $secondSession = $this->stageSession($program, $provider, 'Rescheduled examination', now()->addWeeks(2));
        $firstAssignment = $firstSession->assignments()->create([
            'scholarship_application_id' => $application->id,
            'status' => 'rescheduled',
            'notes' => 'Applicant requested another date.',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
        ]);
        $activeAssignment = $secondSession->assignments()->create([
            'scholarship_application_id' => $application->id,
            'previous_assignment_id' => $firstAssignment->id,
            'status' => 'acknowledged',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
            'acknowledged_at' => now(),
        ]);
        $attendance = $activeAssignment->attendance()->create([
            'status' => 'present',
            'notes' => 'Identity checked at the examination desk.',
            'recorded_by' => $provider->id,
            'recorded_at' => now(),
        ]);
        $sessionEvidence = $secondSession->evidence()->create([
            'scholarship_id' => $program->id,
            'stage_key' => 'exam',
            'scope' => 'session',
            'category' => 'attendance_sheet',
            'original_name' => 'attendance-sheet.pdf',
            'path' => "application-stage-evidence/{$secondSession->id}/attendance-sheet.pdf",
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'event_date' => now()->addWeeks(2)->toDateString(),
            'visibility' => 'provider_private',
            'uploaded_by' => $provider->id,
            'uploaded_at' => now(),
        ]);
        $applicantEvidence = $application->stageEvidence()->create([
            'scholarship_id' => $program->id,
            'application_stage_session_id' => $secondSession->id,
            'application_stage_assignment_id' => $activeAssignment->id,
            'stage_key' => 'exam',
            'scope' => 'applicant',
            'category' => 'score_sheet',
            'original_name' => 'applicant-score.pdf',
            'path' => "application-stage-evidence/{$secondSession->id}/applicant-score.pdf",
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'event_date' => now()->addWeeks(2)->toDateString(),
            'visibility' => 'provider_private',
            'uploaded_by' => $provider->id,
            'uploaded_at' => now(),
        ]);
        $audit = $application->stageAuditEvents()->create([
            'scholarship_id' => $program->id,
            'application_stage_session_id' => $secondSession->id,
            'application_stage_assignment_id' => $activeAssignment->id,
            'stage_key' => 'exam',
            'event_type' => 'attendance_recorded',
            'from_status' => 'pending',
            'to_status' => 'present',
            'metadata' => ['attendance_id' => $attendance->id],
            'actor_id' => $provider->id,
            'occurred_at' => now(),
        ]);

        $this->assertSame($firstAssignment->id, $activeAssignment->previousAssignment->id);
        $this->assertSame('present', $activeAssignment->attendance->status);
        $this->assertSame($sessionEvidence->id, $secondSession->evidence->first()->id);
        $this->assertSame($applicantEvidence->id, $application->stageEvidence->first()->id);
        $this->assertSame('attendance_recorded', $audit->event_type);
        $this->assertSame(['attendance_id' => $attendance->id], $audit->metadata);

        $application->refresh();

        $this->assertSame($canonicalBefore, $application->only([
            'status',
            'application_state',
            'workflow_stage',
            'final_outcome',
        ]));
        $this->assertSame(0, $application->monitoringSubmissions()->count());
        $this->assertSame(0, $application->benefitReleaseRecords()->count());
        $this->assertSame(0, $application->schedules()->count());
    }

    public function test_stage_progress_can_hold_operational_state_and_proof_review_without_changing_legacy_result(): void
    {
        [$provider, , $application] = $this->applicationAtExam();
        $progress = $application->stageProgresses()->where('stage_key', 'exam')->firstOrFail();

        $progress->update([
            'operational_status' => 'ready_for_review',
            'outcome_key' => null,
            'proof_required' => true,
            'proof_verified_by' => $provider->id,
            'proof_verified_at' => now(),
        ]);

        $progress->refresh();

        $this->assertSame('current', $progress->status);
        $this->assertNull($progress->result);
        $this->assertSame('ready_for_review', $progress->operational_status);
        $this->assertNull($progress->outcome_key);
        $this->assertTrue($progress->proof_required);
        $this->assertSame($provider->id, $progress->proofVerifier->id);
        $this->assertNotNull($progress->proof_verified_at);
        $this->assertSame('exam', $application->fresh()->workflow_stage);
    }

    public function test_removing_a_session_cascades_operational_records_but_preserves_its_audit_event(): void
    {
        [$provider, $program, $application] = $this->applicationAtExam();
        $session = $this->stageSession($program, $provider, 'Temporary examination', now()->addWeek());
        $assignment = $session->assignments()->create([
            'scholarship_application_id' => $application->id,
            'status' => 'assigned',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
        ]);
        $attendance = $assignment->attendance()->create(['status' => 'pending']);
        $evidence = $session->evidence()->create([
            'scholarship_id' => $program->id,
            'stage_key' => 'exam',
            'scope' => 'session',
            'category' => 'verification_record',
            'original_name' => 'session-proof.pdf',
            'path' => "application-stage-evidence/{$session->id}/session-proof.pdf",
            'mime_type' => 'application/pdf',
            'size' => 256,
            'visibility' => 'provider_private',
            'uploaded_by' => $provider->id,
            'uploaded_at' => now(),
        ]);
        $audit = ApplicationStageAuditEvent::create([
            'scholarship_id' => $program->id,
            'scholarship_application_id' => $application->id,
            'application_stage_session_id' => $session->id,
            'application_stage_assignment_id' => $assignment->id,
            'stage_key' => 'exam',
            'event_type' => 'session_created',
            'to_status' => 'scheduled',
            'actor_id' => $provider->id,
            'occurred_at' => now(),
        ]);

        $session->delete();

        $this->assertNull($assignment->fresh());
        $this->assertNull($attendance->fresh());
        $this->assertNull($evidence->fresh());
        $this->assertNotNull($application->fresh());
        $this->assertNull($audit->fresh()->application_stage_session_id);
        $this->assertNull($audit->fresh()->application_stage_assignment_id);
    }

    /** @return array{User, Scholarship} */
    private function program(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        $program = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => fake()->unique()->sentence(4),
            'description' => 'Program used to verify the application stage backend foundation.',
            'selection_stages' => ['screening', 'exam', 'formal_application', 'decision'],
            'status' => 'published',
        ]);

        return [$provider, $program];
    }

    /** @return array{User, Scholarship, ScholarshipApplication} */
    private function applicationAtExam(): array
    {
        [$provider, $program] = $this->program();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);

        return [$provider, $program, $application];
    }

    private function stageSession(
        Scholarship $program,
        User $provider,
        string $title,
        mixed $startsAt,
    ): ApplicationStageSession {
        return $program->stageSessions()->create([
            'stage_key' => 'exam',
            'title' => $title,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'timezone' => 'Asia/Manila',
            'mode' => 'onsite',
            'venue' => 'Provider testing center',
            'location_address' => 'Antipolo City, Rizal',
            'capacity' => 30,
            'instructions' => 'Bring a recent school ID.',
            'status' => 'scheduled',
            'proof_required' => true,
            'published_at' => now(),
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);
    }
}
