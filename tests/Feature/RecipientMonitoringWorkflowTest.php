<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\RecipientBenefitReceiptResponse;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringSubmission;
use App\Models\RecipientSupportDecision;
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

    public function test_applicant_monitoring_uses_dedicated_private_pages(): void
    {
        [, $applicant, , $application] = $this->selectedApplication();

        $this->actingAs($applicant)
            ->get('/dashboard/monitoring')
            ->assertOk()
            ->assertViewIs('dashboard-monitoring');

        $this->actingAs($applicant)
            ->get("/dashboard/monitoring/{$application->id}")
            ->assertOk()
            ->assertViewIs('dashboard-monitoring-detail')
            ->assertViewHas('application', fn (ScholarshipApplication $record): bool => $record->is($application));

        $this->actingAs($applicant)
            ->get('/dashboard/applications?view=monitoring')
            ->assertRedirect('/dashboard/monitoring');

        $otherApplicant = User::factory()->create(['role' => 'applicant']);
        $this->actingAs($otherApplicant)
            ->get("/dashboard/monitoring/{$application->id}")
            ->assertForbidden();
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

        $this->actingAs($applicant)
            ->postJson("/dashboard/benefit-release-records/{$recordId}/response", [
                'response_type' => 'confirmed',
                'received_on' => now()->toDateString(),
                'acknowledged' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('response_type');

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

        $this->actingAs($applicant)
            ->postJson("/dashboard/benefit-release-records/{$recordId}/response", [
                'response_type' => 'confirmed',
                'received_on' => now()->toDateString(),
                'recipient_note' => 'I received the complete allowance.',
                'acknowledged' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.benefit_releases.0.receipt_response.status', 'confirmed')
            ->assertJsonPath('application.recipient_monitoring.benefit_releases.0.can_respond', false);

        $this->assertDatabaseHas('recipient_benefit_receipt_responses', [
            'recipient_benefit_release_record_id' => $recordId,
            'applicant_id' => $applicant->id,
            'response_type' => 'confirmed',
            'status' => 'confirmed',
        ]);
        $this->actingAs($applicant)
            ->postJson("/dashboard/benefit-release-records/{$recordId}/response", [
                'response_type' => 'confirmed',
                'received_on' => now()->toDateString(),
                'acknowledged' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('response_type');
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('type', 'benefit_receipt_confirmed')
            ->exists());

        $this->actingAs($provider)
            ->postJson("/provider/benefit-release-records/{$recordId}/result", [
                'status' => 'withheld',
                'notes' => 'Attempting to replace the completed record.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_recipient_can_report_a_release_issue_and_provider_can_resolve_it_separately(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedApplication();
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
        ]);
        $release = RecipientBenefitRelease::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'Learning materials release',
            'release_at' => now()->subDay(),
            'benefit_description' => 'Books and school supplies',
            'release_method' => 'in_person',
            'location' => 'Community desk',
            'requires_original_verification' => false,
            'status' => 'completed',
            'published_at' => now()->subDays(2),
        ]);
        Storage::disk('local')->put('benefit-release-receipts/original/provider-proof.pdf', 'provider proof');
        $record = RecipientBenefitReleaseRecord::create([
            'recipient_benefit_release_id' => $release->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'status' => 'released',
            'notes' => 'Package handed to the recipient.',
            'receipt_original_name' => 'provider-proof.pdf',
            'receipt_path' => 'benefit-release-receipts/original/provider-proof.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size' => 14,
            'recorded_by' => $provider->id,
            'recorded_at' => now()->subDay(),
            'released_at' => now()->subDay(),
        ]);

        $issueEvidence = UploadedFile::fake()->image('incomplete-package.jpg')->size(200);
        $response = $this->actingAs($applicant)
            ->withHeader('Accept', 'application/json')
            ->post("/dashboard/benefit-release-records/{$record->id}/response", [
                'response_type' => 'issue',
                'issue_type' => 'incomplete_benefit',
                'issue_details' => 'The package did not include the listed reference books.',
                'evidence' => $issueEvidence,
                'acknowledged' => '1',
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.benefit_releases.0.receipt_response.status', 'open');

        $receiptResponse = RecipientBenefitReceiptResponse::findOrFail(
            $response->json('application.recipient_monitoring.benefit_releases.0.receipt_response.id'),
        );
        Storage::disk('local')->assertExists($receiptResponse->evidence_path);
        $this->actingAs($provider)
            ->get("/provider/benefit-receipt-responses/{$receiptResponse->id}/files/evidence")
            ->assertOk();

        $resolutionProof = UploadedFile::fake()->image('replacement-proof.jpg')->size(220);
        $this->actingAs($provider)
            ->withHeader('Accept', 'application/json')
            ->post("/provider/benefit-receipt-responses/{$receiptResponse->id}/resolve", [
                'resolution_outcome' => 'replacement_scheduled',
                'resolution_notes' => 'The missing books were verified and a replacement pickup was arranged.',
                'resolution_proof' => $resolutionProof,
            ])
            ->assertOk()
            ->assertJsonPath('release.records.0.receipt_response.status', 'resolved')
            ->assertJsonPath('release.records.0.receipt_response.resolution_outcome', 'replacement_scheduled');

        $receiptResponse->refresh();
        Storage::disk('local')->assertExists($receiptResponse->resolution_proof_path);
        $this->actingAs($applicant)
            ->get("/dashboard/benefit-receipt-responses/{$receiptResponse->id}/files/resolution-proof")
            ->assertOk();
        $this->assertSame('benefit-release-receipts/original/provider-proof.pdf', $record->fresh()->receipt_path);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'benefit_receipt_issue_resolved')
            ->exists());
    }

    public function test_provider_can_renew_then_complete_a_recipient_support_record(): void
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
                'title' => 'Renewal grade check',
                'period_type' => 'semester',
                'due_at' => now()->toDateString(),
                'minimum_grade' => 85,
                'grading_scale' => 'percentage',
            ])
            ->assertCreated()
            ->json('cycle.id');
        $renewalPayload = [
            'decision' => 'renewed',
            'reason_category' => 'requirements_met',
            'effective_on' => now()->toDateString(),
            'support_ends_on' => now()->addMonths(6)->toDateString(),
            'next_review_on' => now()->addMonths(3)->toDateString(),
            'next_period_terms' => 'Continue the published grade requirement and submit the next academic update.',
            'confirmed' => true,
        ];

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/support-decision", $renewalPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');

        RecipientMonitoringSubmission::create([
            'recipient_monitoring_cycle_id' => $cycleId,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'original_name' => 'renewal-grade-card.pdf',
            'path' => 'monitoring/renewal-grade-card.pdf',
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

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/support-decision", $renewalPayload)
            ->assertOk()
            ->assertJsonPath('recipient.support_status', 'renewed')
            ->assertJsonPath('recipient.is_closed', false)
            ->assertJsonPath('recipient.latest_decision.next_period_terms', $renewalPayload['next_period_terms']);
        $this->assertSame('renewed', $application->fresh()->status);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.support_status', 'renewed')
            ->assertJsonPath('application.recipient_monitoring.support_decisions.0.decision', 'renewed');

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/support-decision", [
                'decision' => 'completed',
                'reason_category' => 'program_completed',
                'effective_on' => now()->toDateString(),
                'reason' => 'The recipient completed the support period and all currently due requirements.',
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('recipient.support_status', 'completed')
            ->assertJsonPath('recipient.is_closed', true);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.support_status', 'completed')
            ->assertJsonPath('application.recipient_monitoring.cycles.0.can_submit', false);

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/support-decision", $renewalPayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision');
        $this->assertDatabaseCount('recipient_support_decisions', 2);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'recipient_support_decision')
            ->exists());
    }

    public function test_terminated_recipient_can_request_reconsideration_and_have_support_reinstated(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedApplication();
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
        ]);

        $decisionFile = UploadedFile::fake()->create('termination-notice.pdf', 220, 'application/pdf');
        $terminationResponse = $this->actingAs($provider)
            ->withHeader('Accept', 'application/json')
            ->post("/provider/applications/{$application->id}/support-decision", [
                'decision' => 'terminated',
                'reason_category' => 'requirement_not_met',
                'effective_on' => now()->toDateString(),
                'notice_given_on' => now()->subDay()->toDateString(),
                'reason' => 'The required academic record remained unresolved after provider follow-up.',
                'decision_document' => $decisionFile,
                'confirmed' => '1',
            ])
            ->assertOk()
            ->assertJsonPath('recipient.support_status', 'terminated')
            ->assertJsonPath('recipient.latest_decision.reason_category', 'requirement_not_met');

        $termination = RecipientSupportDecision::findOrFail($terminationResponse->json('recipient.latest_decision.id'));
        Storage::disk('local')->assertExists($termination->decision_document_path);
        $this->actingAs($applicant)
            ->get("/dashboard/support-decisions/{$termination->id}/files/decision-document")
            ->assertOk();

        $appealFile = UploadedFile::fake()->image('updated-record.jpg')->size(180);
        $this->actingAs($applicant)
            ->withHeader('Accept', 'application/json')
            ->post("/dashboard/support-decisions/{$termination->id}/response", [
                'response_type' => 'reconsideration_requested',
                'message' => 'The missing record is now available and is attached for another review.',
                'attachment' => $appealFile,
                'confirmed' => '1',
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.support_decisions.0.response_status', 'open');

        $termination->refresh();
        Storage::disk('local')->assertExists($termination->applicant_response_path);
        $this->actingAs($provider)
            ->get("/provider/support-decisions/{$termination->id}/files/applicant-response")
            ->assertOk();

        $resolutionProof = UploadedFile::fake()->create('reinstatement-note.pdf', 200, 'application/pdf');
        $this->actingAs($provider)
            ->withHeader('Accept', 'application/json')
            ->post("/provider/support-decisions/{$termination->id}/resolve", [
                'resolution_outcome' => 'support_reinstated',
                'resolution_notes' => 'The replacement record was reviewed and support can continue.',
                'resolution_proof' => $resolutionProof,
                'support_ends_on' => now()->addMonths(6)->toDateString(),
                'next_review_on' => now()->addMonths(3)->toDateString(),
                'next_period_terms' => 'Continue the monitoring plan and submit the next required academic update.',
            ])
            ->assertOk()
            ->assertJsonPath('recipient.support_status', 'renewed')
            ->assertJsonPath('recipient.latest_decision.reason_category', 'support_reinstated');

        $termination->refresh();
        $this->assertSame('resolved', $termination->response_status);
        $this->assertSame('support_reinstated', $termination->resolution_outcome);
        Storage::disk('local')->assertExists($termination->resolution_proof_path);
        $this->actingAs($applicant)
            ->get("/dashboard/support-decisions/{$termination->id}/files/resolution-proof")
            ->assertOk();
        $this->assertSame('renewed', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_support_decisions', 2);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'support_decision_request_resolved')
            ->exists());
    }

    public function test_provider_can_view_a_consolidated_recipient_support_record(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedApplication();
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now()->subMonths(4),
            'student_response_terms_accepted_at' => now()->subMonths(4),
            'provider_contract_terms_accepted_at' => now()->subMonths(4),
        ]);
        $cycle = RecipientMonitoringCycle::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'First semester academic review',
            'period_type' => 'semester',
            'academic_period' => 'First semester',
            'school_year' => '2026-2027',
            'due_at' => now()->subMonth()->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'closed',
            'published_at' => now()->subMonths(2),
        ]);
        $submission = RecipientMonitoringSubmission::create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'original_name' => 'first-semester-card.pdf',
            'path' => 'monitoring/first-semester-card.pdf',
            'mime_type' => 'application/pdf',
            'size' => 256,
            'ocr_status' => 'succeeded',
            'ocr_grade' => 91,
            'ocr_grading_scale' => 'percentage',
            'grade_source' => 'ocr',
            'submitted_at' => now()->subMonth(),
            'review_status' => 'met',
            'review_notes' => 'The submitted grade card meets the continuing requirement.',
            'reviewed_by' => $provider->id,
            'reviewed_at' => now()->subMonth()->addDay(),
        ]);
        $release = RecipientBenefitRelease::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'First semester allowance',
            'release_at' => now()->subWeeks(2),
            'benefit_description' => 'PHP 5,000 learning allowance',
            'amount' => 5000,
            'release_method' => 'in_person',
            'location' => 'Tulay Aral Community Desk',
            'requires_original_verification' => true,
            'status' => 'completed',
            'published_at' => now()->subMonth(),
        ]);
        $releaseRecord = RecipientBenefitReleaseRecord::create([
            'recipient_benefit_release_id' => $release->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'status' => 'released',
            'originals_verified' => true,
            'notes' => 'Recipient received the allowance and signed the acknowledgement.',
            'receipt_original_name' => 'signed-release-receipt.pdf',
            'receipt_path' => 'monitoring/signed-release-receipt.pdf',
            'receipt_mime_type' => 'application/pdf',
            'receipt_size' => 128,
            'recorded_by' => $provider->id,
            'recorded_at' => now()->subWeeks(2),
            'released_at' => now()->subWeeks(2),
        ]);
        RecipientSupportDecision::create([
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'decision' => 'renewed',
            'effective_on' => now()->subWeek()->toDateString(),
            'support_ends_on' => now()->addMonths(5)->toDateString(),
            'next_review_on' => now()->addMonths(2)->toDateString(),
            'next_period_terms' => 'Continue the grade requirement for the next semester.',
            'decided_by' => $provider->id,
            'decided_at' => now()->subWeek(),
        ]);

        $response = $this->actingAs($provider)
            ->getJson("/provider/applications/{$application->id}/recipient-record")
            ->assertOk()
            ->assertJsonPath('record.recipient.name', $applicant->name)
            ->assertJsonPath('record.program.title', $scholarship->title)
            ->assertJsonPath('record.agreement.status', 'accepted')
            ->assertJsonPath('record.summary.monitoring_total', 1)
            ->assertJsonPath('record.summary.monitoring_confirmed', 1)
            ->assertJsonPath('record.summary.release_total', 1)
            ->assertJsonPath('record.summary.released_total', 1)
            ->assertJsonPath('record.support.support_status', 'renewed');

        $types = collect($response->json('record.timeline'))->pluck('type');
        $this->assertContains('agreement', $types);
        $this->assertContains('monitoring', $types);
        $this->assertContains('release', $types);
        $this->assertContains('decision', $types);
        $this->assertSame($submission->id, $response->json('record.monitoring_records.0.submission.id'));
        $this->assertSame($releaseRecord->id, $response->json('record.benefit_releases.0.id'));

        $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles")
            ->assertOk()
            ->assertJsonPath('program_summary.recipients.total', 1)
            ->assertJsonPath('program_summary.recipients.active', 1)
            ->assertJsonPath('program_summary.recipients.renewal_ready', 1)
            ->assertJsonPath('program_summary.outcomes.renewed', 1)
            ->assertJsonPath('program_summary.monitoring.periods', 1)
            ->assertJsonPath('program_summary.monitoring.records_reviewed', 1)
            ->assertJsonPath('program_summary.releases.schedules', 1)
            ->assertJsonPath('program_summary.releases.released', 1)
            ->assertJsonPath('program_summary.attention_count', 0);

        $otherProvider = User::factory()->create(['role' => 'provider']);
        $this->actingAs($otherProvider)
            ->getJson("/provider/applications/{$application->id}/recipient-record")
            ->assertForbidden();
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
