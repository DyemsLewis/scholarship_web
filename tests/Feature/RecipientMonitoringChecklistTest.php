<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringCycleRequirement;
use App\Models\RecipientMonitoringAdjustmentRequest;
use App\Models\RecipientMonitoringIntervention;
use App\Models\RecipientMonitoringPlan;
use App\Models\RecipientMonitoringSubmission;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipientMonitoringChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('services.academic_ocr.enabled', true);
        config()->set('services.academic_ocr.endpoint', 'https://api.ocr.space/parse/image');
        config()->set('services.academic_ocr.key', 'test-key');
        config()->set('services.academic_ocr.max_file_size_kb', 1024);
    }

    public function test_provider_publishes_active_plan_as_a_versioned_recipient_checklist(): void
    {
        [$provider, $applicant, $scholarship, $application, $plan] = $this->selectedRecipientWithPlan();

        $response = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'First semester check-in',
                'period_label' => 'First semester',
                'school_year' => '2026-2027',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addMonth()->toDateString(),
                'instructions' => 'Upload clear and current records.',
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.is_plan_check_in', true)
            ->assertJsonPath('cycle.monitoring_plan_version', 3)
            ->assertJsonPath('cycle.requirements.0.type', 'academic_progress')
            ->assertJsonPath('cycle.requirements.1.type', 'enrollment')
            ->assertJsonPath('cycle.requirements.2.type', 'program_participation')
            ->assertJsonPath('cycle.item_expected_count', 2)
            ->assertJsonPath('cycle.item_pending_count', 2);

        $cycleId = $response->json('cycle.id');
        $this->assertDatabaseHas('recipient_monitoring_cycles', [
            'id' => $cycleId,
            'recipient_monitoring_plan_id' => $plan->id,
            'monitoring_plan_version' => 3,
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
        ]);
        $this->assertDatabaseCount('recipient_monitoring_cycle_requirements', 3);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'recipient_monitoring_request')
            ->exists());

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.id', $cycleId)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.required_count', 2)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.pending_count', 2)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.status', 'provider_recorded')
            ->assertJsonPath('application.recipient_monitoring.pending_count', 2);
    }

    public function test_recipient_uploads_each_checklist_item_independently_and_academic_item_uses_ocr(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'First semester check-in',
                'period_label' => 'First semester',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addMonth()->toDateString(),
            ])
            ->assertCreated()
            ->json('cycle.id');

        $requirements = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->get()
            ->keyBy('type');
        $academic = $requirements->get('academic_progress');
        $enrollment = $requirements->get('enrollment');
        $participation = $requirements->get('program_participation');

        Http::fake([
            'api.ocr.space/*' => Http::response([
                'OCRExitCode' => 1,
                'IsErroredOnProcessing' => false,
                'ParsedResults' => [[
                    'ParsedText' => "Learner: Checklist Recipient\nGeneral Average: 92%",
                ]],
            ]),
        ]);

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$enrollment->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('registration.pdf', 120, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.pending_count', 1);

        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'recipient_monitoring_cycle_requirement_id' => $enrollment->id,
            'scholarship_application_id' => $application->id,
            'ocr_status' => 'not_requested',
        ]);

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$academic->id}/submission", [
                'supporting_record' => UploadedFile::fake()->image('report-card.jpg')->size(200),
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.pending_count', 0)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.submitted_count', 2)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.submission.ocr_status', 'succeeded')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.submission.grade_label', '92.00%');

        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'recipient_monitoring_cycle_requirement_id' => $academic->id,
            'scholarship_application_id' => $application->id,
            'ocr_status' => 'succeeded',
            'ocr_grade' => 92,
        ]);
        $this->assertSame(2, RecipientMonitoringSubmission::query()
            ->where('scholarship_application_id', $application->id)
            ->count());

        $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles")
            ->assertOk()
            ->assertJsonPath('cycles.0.item_submitted_count', 2)
            ->assertJsonPath('cycles.0.item_pending_count', 0)
            ->assertJsonPath('cycles.0.recipients.0.checklist_complete', true)
            ->assertJsonPath('program_summary.attention_count', 2);

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$participation->id}/submission", [
                'supporting_record' => UploadedFile::fake()->image('attendance.jpg'),
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supporting_record');
    }

    public function test_checklist_snapshot_does_not_change_when_provider_edits_the_plan(): void
    {
        [$provider, , $scholarship, , $plan] = $this->selectedRecipientWithPlan();
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'Locked checklist',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addMonth()->toDateString(),
            ])
            ->assertCreated()
            ->json('cycle.id');

        $plan->requirements()->firstWhere('type', 'enrollment')->update([
            'title' => 'Updated enrollment title',
        ]);
        $plan->update(['version' => 4]);

        $cycle = RecipientMonitoringCycle::with('requirements')->findOrFail($cycleId);
        $this->assertSame(3, $cycle->monitoring_plan_version);
        $this->assertSame(
            'Proof of current enrollment',
            $cycle->requirements->firstWhere('type', 'enrollment')->title,
        );
    }

    public function test_provider_reviews_uploaded_items_and_recipient_can_replace_a_record_after_the_due_date(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'First semester check-in',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addMonth()->toDateString(),
            ])
            ->assertCreated()
            ->json('cycle.id');
        $requirement = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('type', 'enrollment')
            ->firstOrFail();

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('registration.pdf', 120, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertCreated();
        $submission = RecipientMonitoringSubmission::query()
            ->where('recipient_monitoring_cycle_requirement_id', $requirement->id)
            ->firstOrFail();

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-submissions/{$submission->id}/review", [
                'decision' => 'needs_correction',
                'notes' => 'Upload the complete registration form with the current term visible.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.requirements.1.submission.review_status', 'needs_correction');

        RecipientMonitoringCycle::findOrFail($cycleId)->update(['due_at' => now()->subDay()->toDateString()]);
        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('complete-registration.pdf', 140, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertOk();

        $submission->refresh();
        $this->assertSame('applicant_upload', $submission->submission_source);
        $this->assertSame('pending', $submission->review_status);

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-submissions/{$submission->id}/review", [
                'decision' => 'met',
                'notes' => 'The replacement record is complete.',
            ])
            ->assertOk();

        $this->assertDatabaseCount('recipient_monitoring_reviews', 2);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'recipient_monitoring_review')
            ->where('title', 'Requirement confirmed')
            ->exists());
    }

    public function test_provider_records_non_file_requirements_with_an_audit_history(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'First semester check-in',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addMonth()->toDateString(),
            ])
            ->assertCreated()
            ->json('cycle.id');
        $requirements = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->get()
            ->keyBy('type');
        $participation = $requirements->get('program_participation');

        $this->actingAs($provider)
            ->postJson("/provider/monitoring-requirements/{$participation->id}/applications/{$application->id}/record", [
                'decision' => 'met',
                'notes' => 'Attendance was confirmed from the signed orientation sheet.',
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.recipients.0.required_review_count', 3)
            ->assertJsonPath('cycle.recipients.0.reviewed_item_count', 1)
            ->assertJsonPath('cycle.recipients.0.requirements.2.submission.submission_source', 'provider_record')
            ->assertJsonPath('cycle.recipients.0.requirements.2.submission.has_file', false)
            ->assertJsonPath('cycle.recipients.0.requirements.2.submission.review_status', 'met');

        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'recipient_monitoring_cycle_requirement_id' => $participation->id,
            'scholarship_application_id' => $application->id,
            'submission_source' => 'provider_record',
            'path' => null,
            'review_status' => 'met',
        ]);
        $this->assertDatabaseCount('recipient_monitoring_reviews', 1);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.status', 'completed')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.submission.has_file', false)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.submission.review_status_label', 'Requirement met');

        $this->actingAs($provider)
            ->postJson("/provider/monitoring-requirements/{$requirements->get('enrollment')->id}/applications/{$application->id}/record", [
                'decision' => 'met',
            ])
            ->assertUnprocessable();
    }

    public function test_recipient_requests_and_uses_an_approved_personal_extension(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $originalDue = now()->addDays(5);
        $approvedDue = now()->addDays(12);
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'First semester check-in',
                'opens_at' => now()->toDateString(),
                'due_at' => $originalDue->toDateString(),
            ])
            ->assertCreated()
            ->json('cycle.id');
        $requirement = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('type', 'enrollment')
            ->firstOrFail();

        $response = $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/adjustment-request", [
                'request_type' => 'extension',
                'reason_category' => 'school_schedule',
                'explanation' => 'The registrar will release the current enrollment form after the original deadline.',
                'requested_due_at' => $approvedDue->toDateString(),
                'confirmation' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.1.adjustment_request.status', 'pending');
        $adjustmentId = $response->json('application.recipient_monitoring.check_ins.0.requirements.1.adjustment_request.id');

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-adjustment-requests/{$adjustmentId}/decision", [
                'decision' => 'approved',
                'decision_notes' => 'Registrar delay confirmed.',
                'approved_due_at' => $approvedDue->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.requirements.1.adjustment_request.status', 'approved')
            ->assertJsonPath('cycle.recipients.0.requirements.1.effective_due_at', $approvedDue->toDateString());

        RecipientMonitoringCycle::findOrFail($cycleId)->update(['due_at' => now()->subDay()->toDateString()]);
        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('late-registration.pdf', 120, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('recipient_monitoring_adjustment_requests', [
            'id' => $adjustmentId,
            'status' => 'approved',
            'approved_due_at' => $approvedDue->toDateString().' 00:00:00',
        ]);
    }

    public function test_approved_exception_completes_the_requirement_with_an_audit_record(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'First semester check-in',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addWeek()->toDateString(),
            ])
            ->assertCreated()
            ->json('cycle.id');
        $requirement = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('type', 'program_participation')
            ->firstOrFail();

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/adjustment-request", [
                'request_type' => 'exception',
                'reason_category' => 'illness',
                'explanation' => 'I was confined during the required orientation and could not attend.',
                'confirmation' => true,
            ])
            ->assertCreated();
        $adjustment = RecipientMonitoringAdjustmentRequest::firstOrFail();

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-adjustment-requests/{$adjustment->id}/decision", [
                'decision' => 'approved',
                'decision_notes' => 'Approved based on the reported health circumstance.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.requirements.2.submission.review_status', 'excused');

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.status', 'completed')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.adjustment_request.status', 'approved');
        $this->assertDatabaseHas('recipient_monitoring_reviews', ['decision' => 'excused']);
    }

    public function test_provider_intervention_is_shared_and_can_be_completed(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'First semester check-in',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addMonth()->toDateString(),
            ])
            ->assertCreated()
            ->json('cycle.id');
        $requirement = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('type', 'academic_progress')
            ->firstOrFail();

        $response = $this->actingAs($provider)
            ->postJson("/provider/monitoring-requirements/{$requirement->id}/applications/{$application->id}/interventions", [
                'type' => 'consultation',
                'summary' => 'Schedule a short academic consultation before the next release.',
                'action_required' => 'Contact the scholarship desk to select an available time.',
                'follow_up_on' => now()->addWeek()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.recipients.0.requirements.0.interventions.0.status', 'open');
        $interventionId = $response->json('cycle.recipients.0.requirements.0.interventions.0.id');

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.interventions.0.type', 'consultation')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.interventions.0.status', 'open');

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-interventions/{$interventionId}/complete", [
                'completion_notes' => 'The consultation was completed and the next steps were discussed.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.requirements.0.interventions.0.status', 'completed');
        $this->assertSame('completed', RecipientMonitoringIntervention::findOrFail($interventionId)->status);
    }

    private function selectedRecipientWithPlan(): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'account_status' => 'active']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Tulay Aral Continuing Support',
            'description' => 'A scholarship with a complete monitoring checklist.',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'under_review',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
            'provider_contract_terms_accepted_at' => now(),
        ]);
        $plan = RecipientMonitoringPlan::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
            'frequency' => 'semester',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'status' => 'active',
            'version' => 3,
            'activated_at' => now(),
        ]);
        $plan->requirements()->createMany([
            [
                'type' => 'academic_progress',
                'title' => 'Semester grades',
                'evidence_description' => 'Latest report card or official grade report.',
                'required' => true,
                'requires_file' => true,
                'requires_original_verification' => true,
                'minimum_grade' => 85,
                'grading_scale' => 'percentage',
                'sort_order' => 0,
            ],
            [
                'type' => 'enrollment',
                'title' => 'Proof of current enrollment',
                'evidence_description' => 'Enrollment certificate or registration form.',
                'required' => true,
                'requires_file' => true,
                'requires_original_verification' => false,
                'sort_order' => 1,
            ],
            [
                'type' => 'program_participation',
                'title' => 'Orientation attendance',
                'evidence_description' => 'Recorded by the provider.',
                'required' => true,
                'requires_file' => false,
                'requires_original_verification' => false,
                'sort_order' => 2,
            ],
        ]);

        return [$provider, $applicant, $scholarship, $application->fresh(), $plan->fresh()];
    }
}
