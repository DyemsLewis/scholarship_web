<?php

namespace Tests\Feature;

use App\Models\ApplicationStageAuditEvent;
use App\Models\ApplicationStageEvidence;
use App\Models\ApplicationStageSession;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminSelectionActivityOversightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_review_officer_can_inspect_selection_activity_exceptions_without_private_meeting_access(): void
    {
        [$reviewOfficer, , $session, $application, $evidence] = $this->selectionActivity();
        $canonicalState = $application->only(['status', 'application_state', 'workflow_stage', 'final_outcome']);

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/summary')
            ->assertOk()
            ->assertJsonPath('summary.selection_activity_attention', 1);

        $this->actingAs($reviewOfficer)
            ->get('/admin/workspaces/reviews/selection-activities')
            ->assertOk()
            ->assertViewIs('admin-review-selection-activity-queue');

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/selection-activities/data?status=attention')
            ->assertOk()
            ->assertJsonPath('items.0.id', $session->id)
            ->assertJsonPath('items.0.oversight_status', 'attention')
            ->assertJsonPath('items.0.pending_attendance_count', 1)
            ->assertJsonPath('items.0.current_evidence_count', 1);

        $this->actingAs($reviewOfficer)
            ->get("/admin/workspaces/reviews/selection-activities/{$session->id}")
            ->assertOk()
            ->assertViewIs('admin-review-selection-activity-record');

        $this->actingAs($reviewOfficer)
            ->getJson("/admin/workspaces/reviews/selection-activities/{$session->id}/data")
            ->assertOk()
            ->assertJsonPath('session.id', $session->id)
            ->assertJsonPath('session.stage_label', 'Examination')
            ->assertJsonPath('session.counts.participants', 1)
            ->assertJsonPath('session.counts.pending_attendance', 1)
            ->assertJsonPath('session.participants.0.attendance_status', 'pending')
            ->assertJsonPath('session.evidence.0.id', $evidence->id)
            ->assertJsonPath('session.history.0.event', 'Attendance Recorded')
            ->assertJsonMissingPath('session.meeting_url');

        $this->assertSame($canonicalState, $application->fresh()->only(array_keys($canonicalState)));
    }

    public function test_selection_activity_evidence_is_private_to_authorized_admin_reviewers(): void
    {
        [$reviewOfficer, $otherAdmin, , , $evidence] = $this->selectionActivity();

        $this->actingAs($reviewOfficer)
            ->get("/admin/workspaces/reviews/selection-activities/evidence/{$evidence->id}/view")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($otherAdmin)
            ->get('/admin/workspaces/reviews/selection-activities')
            ->assertForbidden();

        $this->actingAs($otherAdmin)
            ->getJson('/admin/workspaces/reviews/selection-activities/data')
            ->assertForbidden();

        $this->actingAs($otherAdmin)
            ->get("/admin/workspaces/reviews/selection-activities/evidence/{$evidence->id}/view")
            ->assertForbidden();
    }

    public function test_completed_session_missing_required_proof_appears_in_attention_queue(): void
    {
        [$reviewOfficer, , $session] = $this->selectionActivity();
        $session->evidence()->delete();
        $session->assignments->first()->attendance->update([
            'status' => 'present',
            'recorded_at' => now()->subDay(),
        ]);
        $session->update([
            'status' => 'completed',
            'completed_at' => now()->subDay(),
        ]);

        $this->actingAs($reviewOfficer)
            ->getJson('/admin/workspaces/reviews/selection-activities/data?status=attention')
            ->assertOk()
            ->assertJsonPath('items.0.id', $session->id)
            ->assertJsonPath('items.0.primary_flag', 'Required session proof is missing.');
    }

    private function selectionActivity(): array
    {
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $reviewOfficer = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Review officer',
            'permissions' => ['manage_reviews'],
        ]);
        $otherAdmin = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Account manager',
            'permissions' => ['manage_accounts'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::query()->create([
            'provider_id' => $provider->id,
            'title' => 'Admin Oversight Scholarship',
            'description' => 'A scholarship used to verify read-only session oversight.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::query()->create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'application_state' => 'active',
            'workflow_stage' => 'exam',
            'document_checklist' => [],
            'submitted_at' => now()->subWeek(),
        ]);
        $session = ApplicationStageSession::query()->create([
            'scholarship_id' => $program->id,
            'stage_key' => 'exam',
            'title' => 'Overdue examination session',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'timezone' => 'Asia/Manila',
            'mode' => 'online',
            'meeting_url' => 'https://meeting.example.test/private-room',
            'status' => 'scheduled',
            'proof_required' => true,
            'published_at' => now()->subWeek(),
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
        ]);
        $assignment = $session->assignments()->create([
            'scholarship_application_id' => $application->id,
            'status' => 'acknowledged',
            'assigned_by' => $provider->id,
            'assigned_at' => now()->subWeek(),
            'acknowledged_at' => now()->subDays(5),
        ]);
        $assignment->attendance()->create([
            'status' => 'pending',
            'recorded_by' => $provider->id,
        ]);
        $path = 'application-stage-evidence/admin-oversight/attendance.pdf';
        Storage::disk('local')->put($path, 'attendance proof');
        $evidence = ApplicationStageEvidence::query()->create([
            'scholarship_id' => $program->id,
            'application_stage_session_id' => $session->id,
            'stage_key' => 'exam',
            'scope' => 'session',
            'category' => 'attendance_sheet',
            'original_name' => 'attendance.pdf',
            'path' => $path,
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'visibility' => 'provider_private',
            'is_current' => true,
            'uploaded_by' => $provider->id,
            'uploaded_at' => now()->subDay(),
        ]);
        ApplicationStageAuditEvent::query()->create([
            'scholarship_id' => $program->id,
            'scholarship_application_id' => $application->id,
            'application_stage_session_id' => $session->id,
            'application_stage_assignment_id' => $assignment->id,
            'stage_key' => 'exam',
            'event_type' => 'attendance_recorded',
            'from_status' => null,
            'to_status' => 'pending',
            'actor_id' => $provider->id,
            'occurred_at' => now(),
        ]);

        return [$reviewOfficer, $otherAdmin, $session->fresh('assignments.attendance'), $application, $evidence];
    }
}
