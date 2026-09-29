<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringCycleRequirement;
use App\Models\RecipientMonitoringSubmission;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminMonitoringOversightTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_queue_detects_overdue_monitoring_and_records_provider_follow_up(): void
    {
        [$admin, $provider, $application, $requirement] = $this->monitoringRecord();

        $this->actingAs($admin)
            ->getJson('/admin/reviews/data')
            ->assertOk()
            ->assertJsonPath('stats.monitoring_records', 1)
            ->assertJsonPath('stats.monitoring_needing_attention', 1)
            ->assertJsonPath('monitoring_records.0.application_id', $application->id)
            ->assertJsonPath('monitoring_records.0.oversight_status', 'attention')
            ->assertJsonPath('monitoring_records.0.attention_items.0.title', 'Requirement is overdue');

        $this->actingAs($admin)
            ->get("/admin/monitoring/{$application->id}")
            ->assertOk();

        $this->actingAs($admin)
            ->getJson("/admin/monitoring/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('record.requirements.0.id', $requirement->id)
            ->assertJsonPath('record.requirements.0.status', 'overdue');

        $this->actingAs($admin)
            ->postJson("/admin/monitoring/{$application->id}/oversight-reviews", [
                'outcome' => 'follow_up_required',
                'notes' => 'Ask the provider to contact the recipient and document the next action.',
            ])
            ->assertCreated()
            ->assertJsonPath('record.last_review.outcome', 'follow_up_required')
            ->assertJsonPath('record.oversight_status', 'attention');

        $this->assertDatabaseHas('recipient_monitoring_oversight_reviews', [
            'scholarship_application_id' => $application->id,
            'reviewed_by' => $admin->id,
            'outcome' => 'follow_up_required',
        ]);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('type', 'monitoring_oversight_review')
            ->exists());

        $this->actingAs($provider)
            ->get("/admin/monitoring/{$application->id}")
            ->assertForbidden();
    }

    public function test_admin_can_open_private_monitoring_evidence(): void
    {
        Storage::fake('local');
        [$admin, $provider, $application, $requirement, $cycle] = $this->monitoringRecord();
        $path = 'recipient-monitoring/test/report-card.pdf';
        Storage::disk('local')->put($path, 'report card');
        $submission = RecipientMonitoringSubmission::create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'recipient_monitoring_cycle_requirement_id' => $requirement->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'submission_source' => 'applicant_upload',
            'original_name' => 'report-card.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 11,
            'ocr_status' => 'completed',
            'ocr_provider' => 'ocr_space',
            'reported_grade' => 91,
            'reported_grading_scale' => 'percentage',
            'grade_source' => 'ocr',
            'submitted_at' => now(),
            'review_status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get("/admin/monitoring-submissions/{$submission->id}/view")
            ->assertOk();

        $this->actingAs($provider)
            ->get("/admin/monitoring-submissions/{$submission->id}/view")
            ->assertForbidden();
    }

    private function monitoringRecord(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Tulay Aral Continuing Support',
            'description' => 'A monitored scholarship program.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'document_checklist' => [],
            'submitted_at' => now()->subMonth(),
        ]);
        $cycle = RecipientMonitoringCycle::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'First semester check-in',
            'period_type' => 'semester',
            'academic_period' => 'First semester',
            'school_year' => '2026-2027',
            'opens_at' => now()->subMonth()->toDateString(),
            'due_at' => now()->subDay()->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'instructions' => 'Submit the current report card.',
            'status' => 'published',
            'published_at' => now()->subMonth(),
        ]);
        $requirement = RecipientMonitoringCycleRequirement::create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'type' => 'grade_report',
            'title' => 'Semester grade report',
            'required' => true,
            'requires_file' => true,
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'sort_order' => 1,
        ]);

        return [$admin, $provider, $application, $requirement, $cycle];
    }
}
