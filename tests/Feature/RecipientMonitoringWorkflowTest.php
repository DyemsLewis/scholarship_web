<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\RecipientBenefitReleaseRecord;
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

class RecipientMonitoringWorkflowTest extends TestCase
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

    public function test_selected_recipient_can_upload_a_grade_record_that_ocr_space_extracts(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedApplication();
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
            'provider_contract_terms_accepted_at' => now(),
        ]);

        $cycleResponse = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles", [
                'title' => 'First semester grade update',
                'period_type' => 'semester',
                'academic_period' => 'First semester',
                'school_year' => '2026-2027',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addMonth()->toDateString(),
                'minimum_grade' => 85,
                'grading_scale' => 'percentage',
                'instructions' => 'Upload the official report card showing the general average.',
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.recipient_count', 1)
            ->assertJsonPath('cycle.requirement_label', 'Minimum average 85.00%');

        $cycleId = $cycleResponse->json('cycle.id');
        Http::fake([
            'api.ocr.space/*' => Http::response([
                'OCRExitCode' => 1,
                'IsErroredOnProcessing' => false,
                'ParsedResults' => [[
                    'ParsedText' => "Learner: Test Applicant\nGeneral Average: 91%",
                ]],
            ]),
        ]);

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-cycles/{$cycleId}/grade-record", [
                'grade_record' => UploadedFile::fake()->image('first-semester-card.jpg')->size(200),
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.ocr_status', 'succeeded')
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.grade_label', '91.00%')
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.comparison.status', 'pass');

        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'recipient_monitoring_cycle_id' => $cycleId,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'ocr_status' => 'succeeded',
            'ocr_grade' => 91,
            'grade_source' => 'ocr',
        ]);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('type', 'recipient_monitoring_submission')
            ->exists());

        $submission = RecipientMonitoringSubmission::query()->sole();
        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-submissions/{$submission->id}/review", [
                'decision' => 'met',
                'notes' => 'The general average matches the uploaded report card.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.submission.review_status', 'met')
            ->assertJsonPath('cycle.recipients.0.submission.review_status_label', 'Requirement met');

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.review_status', 'met')
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.review_notes', 'The general average matches the uploaded report card.');

        $this->assertDatabaseHas('recipient_monitoring_reviews', [
            'recipient_monitoring_submission_id' => $submission->id,
            'reviewed_by' => $provider->id,
            'decision' => 'met',
        ]);
    }

    public function test_failed_scan_keeps_the_file_and_allows_a_manual_result(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedApplication();
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
            'provider_contract_terms_accepted_at' => now(),
        ]);
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles", [
                'title' => 'Second semester grade update',
                'period_type' => 'semester',
                'due_at' => now()->addMonth()->toDateString(),
                'minimum_grade' => 2.0,
                'grading_scale' => 'grade_point',
            ])
            ->assertCreated()
            ->json('cycle.id');

        Http::fake([
            'api.ocr.space/*' => Http::response([
                'OCRExitCode' => 1,
                'IsErroredOnProcessing' => false,
                'ParsedResults' => [['ParsedText' => 'Subjects and individual grades only']],
            ]),
        ]);

        $upload = $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-cycles/{$cycleId}/grade-record", [
                'grade_record' => UploadedFile::fake()->image('second-semester-card.png')->size(200),
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.ocr_status', 'needs_review')
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.manual_entry_allowed', true);

        $submissionId = $upload->json('application.recipient_monitoring.cycles.0.submission.id');
        $storedPath = (string) RecipientMonitoringSubmission::findOrFail($submissionId)->path;
        Storage::disk('local')->assertExists($storedPath);

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/monitoring-cycles/{$cycleId}/manual-grade", [
                'grade' => 1.75,
                'grading_scale' => 'grade_point',
            ])
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.grade_source', 'applicant_manual')
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.grade_label', '1.75 GWA/GPA')
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.comparison.status', 'pass');
    }

    public function test_requested_replacement_can_be_uploaded_after_the_original_deadline(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedApplication();
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
            'provider_contract_terms_accepted_at' => now(),
        ]);
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles", [
                'title' => 'Quarterly grade update',
                'period_type' => 'quarter',
                'due_at' => now()->toDateString(),
                'minimum_grade' => 85,
                'grading_scale' => 'percentage',
            ])
            ->assertCreated()
            ->json('cycle.id');

        Http::fake([
            'api.ocr.space/*' => Http::sequence()
                ->push([
                    'OCRExitCode' => 1,
                    'IsErroredOnProcessing' => false,
                    'ParsedResults' => [['ParsedText' => 'General Average: 80%']],
                ])
                ->push([
                    'OCRExitCode' => 1,
                    'IsErroredOnProcessing' => false,
                    'ParsedResults' => [['ParsedText' => 'General Average: 88%']],
                ]),
        ]);
        $upload = $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-cycles/{$cycleId}/grade-record", [
                'grade_record' => UploadedFile::fake()->image('unclear-card.jpg')->size(200),
                'terms_accepted' => true,
            ])
            ->assertCreated();
        $submissionId = $upload->json('application.recipient_monitoring.cycles.0.submission.id');

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-submissions/{$submissionId}/review", [
                'decision' => 'needs_correction',
                'notes' => 'Please upload a complete image that includes the school name and grading period.',
            ])
            ->assertOk();

        $this->travel(2)->days();

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-cycles/{$cycleId}/grade-record", [
                'grade_record' => UploadedFile::fake()->image('replacement-card.jpg')->size(200),
                'terms_accepted' => true,
            ])
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.review_status', 'pending')
            ->assertJsonPath('application.recipient_monitoring.cycles.0.submission.grade_label', '88.00%');

        $this->assertDatabaseCount('recipient_monitoring_reviews', 1);
        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'id' => $submissionId,
            'review_status' => 'pending',
            'original_name' => 'replacement-card.jpg',
        ]);
    }

    public function test_confirmed_recipient_can_be_scheduled_and_recorded_for_a_benefit_release(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedApplication();
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
            'provider_contract_terms_accepted_at' => now(),
        ]);
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles", [
                'title' => 'Release eligibility grades',
                'period_type' => 'semester',
                'due_at' => now()->toDateString(),
                'minimum_grade' => 85,
                'grading_scale' => 'percentage',
            ])
            ->assertCreated()
            ->json('cycle.id');
        $releasePayload = [
            'title' => 'First semester allowance',
            'release_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'benefit_description' => 'PHP 5,000 learning allowance',
            'amount' => 5000,
            'release_method' => 'in_person',
            'location' => 'Tulay Aral Community Desk',
            'instructions' => 'Bring the original grade record and a valid school ID.',
            'requires_original_verification' => true,
            'recipient_ids' => [$application->id],
        ];

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/benefit-releases", $releasePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_ids');

        RecipientMonitoringSubmission::create([
            'recipient_monitoring_cycle_id' => $cycleId,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'original_name' => 'confirmed-grade-card.pdf',
            'path' => 'monitoring/confirmed-grade-card.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'ocr_status' => 'succeeded',
            'ocr_grade' => 90,
            'ocr_grading_scale' => 'percentage',
            'grade_source' => 'ocr',
            'submitted_at' => now(),
            'review_status' => 'met',
            'reviewed_by' => $provider->id,
            'reviewed_at' => now(),
        ]);

        $releaseResponse = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/benefit-releases", $releasePayload)
            ->assertCreated()
            ->assertJsonPath('release.recipient_count', 1)
            ->assertJsonPath('release.records.0.status', 'scheduled')
            ->assertJsonPath('release.requires_original_verification', true);
        $recordId = $releaseResponse->json('release.records.0.id');

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.benefit_releases.0.status', 'scheduled')
            ->assertJsonPath('application.recipient_monitoring.benefit_releases.0.benefit_description', 'PHP 5,000 learning allowance');

        $this->travel(2)->days();
        $this->actingAs($provider)
            ->postJson("/provider/benefit-release-records/{$recordId}/result", [
                'status' => 'released',
                'originals_verified' => false,
                'notes' => 'Recipient confirmed receipt in person.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('originals_verified');

        $receipt = UploadedFile::fake()->image('signed-receipt.jpg')->size(300);
        $this->actingAs($provider)
            ->withHeader('Accept', 'application/json')
            ->post("/provider/benefit-release-records/{$recordId}/result", [
                'status' => 'released',
                'originals_verified' => '1',
                'notes' => 'Recipient signed the acknowledgement after receiving the allowance.',
                'receipt_proof' => $receipt,
            ])
            ->assertOk()
            ->assertJsonPath('release.status', 'completed')
            ->assertJsonPath('release.records.0.status', 'released')
            ->assertJsonPath('release.records.0.originals_verified', true);

        $record = RecipientBenefitReleaseRecord::findOrFail($recordId);
        Storage::disk('local')->assertExists($record->receipt_path);
        $this->actingAs($applicant)
            ->get("/dashboard/benefit-release-records/{$recordId}/receipt")
            ->assertOk();
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'recipient_benefit_release_result')
            ->exists());
    }

    private function selectedApplication(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'provider_name' => 'Tulay Aral Community Foundation',
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Continuing Scholar Grant',
            'description' => 'A test program with continuing academic monitoring.',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'recipient_agreement' => [
                'commitment_type' => 'reporting',
                'duration' => 'Submit one academic update each semester.',
                'noncompliance_consequence' => 'The provider reviews the circumstances before changing future support.',
                'exit_or_exception_process' => 'Contact the provider to explain exceptional circumstances.',
            ],
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

        return [$provider, $applicant, $scholarship, $application->fresh()];
    }
}
