<?php

namespace Tests\Feature;

use App\Models\ApplicantEvidenceReview;
use App\Models\ApplicantVerificationDocument;
use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicantEvidenceReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantIndividualEvidenceReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_uploads_and_opening_previews_do_not_count_as_acceptance(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->first();
        $this->actingAs($reviewer)->get("/admin/applicant-verification-documents/{$document->id}/view")->assertOk();
        $this->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id), 'verification_status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('evidence_reviews');
        $this->assertSame('submitted', $document->fresh()->status);
        $this->assertSame(0, ApplicantEvidenceReview::where('action', 'accepted')->count());
    }

    public function test_individual_acceptance_is_versioned_attributed_and_does_not_verify_the_profile(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->first();
        $this->accept($reviewer, $document)->assertOk()->assertJsonPath('document.has_current_acceptance', true)->assertJsonPath('document.revision', 2);
        $this->assertSame($reviewer->id, $document->fresh()->reviewed_by);
        $this->assertSame('pending', $applicant->fresh()->studentProfile->verification_status);
        $event = ApplicantEvidenceReview::where('action', 'accepted')->firstOrFail();
        $this->assertSame($reviewer->name, $event->actor_name);
        $this->assertSame(2, $event->revision);
    }

    public function test_reviewer_payload_has_current_readiness_claim_mapping_and_file_availability(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'identity_record')->firstOrFail();
        $payload = $this->actingAs($reviewer)->getJson("/admin/applicants/{$applicant->id}/review/data")
            ->assertOk()->assertJsonPath('applicant.can_review_evidence', true)
            ->assertJsonPath('applicant.evidence_review.required_evidence_accepted', false)
            ->json('applicant');
        $evidence = collect($payload['verification_documents'])->firstWhere('id', $document->id);
        $this->assertSame(ApplicantEvidenceReviewService::CLAIMS['identity_record'], $evidence['claim_fields']);
        $this->assertTrue($evidence['file_available']);
        $this->assertArrayNotHasKey('path', $evidence);
        Storage::disk('local')->delete($document->path);
        $missing = $this->getJson("/admin/applicants/{$applicant->id}/review/data")->assertOk()->json('applicant.verification_documents');
        $this->assertFalse(collect($missing)->firstWhere('id', $document->id)['file_available']);
    }

    public function test_decision_response_refreshes_profile_state_and_required_evidence_counts(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->firstOrFail();
        $this->accept($reviewer, $document)->assertOk()
            ->assertJsonPath('evidence_review.accepted_count', 1)
            ->assertJsonPath('evidence_review.required_count', 6)
            ->assertJsonPath('evidence_review.required_evidence_accepted', false)
            ->assertJsonPath('user.applicant_verification_status', 'pending')
            ->assertJsonPath('user.is_profile_verified', false);
    }

    public function test_portal_oversight_payload_is_explicitly_read_only(): void
    {
        [$reviewer, $applicant] = $this->records();
        $manager = User::factory()->create(['role' => 'admin', 'parent_account_id' => $reviewer->parent_account_id,
            'account_title' => 'Portal manager', 'permissions' => User::ADMIN_PERMISSIONS]);
        $this->actingAs($manager)->getJson("/admin/applicants/{$applicant->id}/review/data")
            ->assertOk()->assertJsonPath('applicant.can_review_evidence', false);
    }

    public function test_overall_approval_requires_every_current_document_and_preserves_individual_decisions(): void
    {
        [$reviewer, $applicant] = $this->records();
        foreach ($applicant->applicantVerificationDocuments as $document) {
            $this->accept($reviewer, $document)->assertOk();
        }
        $count = ApplicantEvidenceReview::count();
        $this->actingAs($reviewer)->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id),
            'verification_status' => 'approved',
        ])->assertOk()->assertJsonPath('user.is_profile_verified', true)->assertJsonPath('user.evidence_review.accepted_count', 6);
        $this->assertSame($count, ApplicantEvidenceReview::count());
        $this->assertTrue($applicant->applicantVerificationDocuments()->get()->every(fn ($document) => $document->hasCurrentAcceptance()));
    }

    public function test_replacement_requests_need_a_reason_and_duplicate_stale_decisions_are_rejected(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->first();
        $url = "/admin/applicant-verification-documents/{$document->id}/review";
        $this->actingAs($reviewer)->patchJson($url, ['status' => 'rejected', 'expected_revision' => 1, 'review_notes' => '  '])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'rejected', 'expected_revision' => 1, 'review_notes' => 'The date of birth is not readable.'])
            ->assertOk()->assertJsonPath('document.status', 'rejected');
        $this->patchJson($url, ['status' => 'approved', 'expected_revision' => 1])->assertConflict();
        $this->assertSame(1, ApplicantEvidenceReview::where('action', 'replacement_requested')->count());
    }

    public function test_replacement_resets_only_the_replaced_file_and_preserves_history(): void
    {
        [$reviewer, $applicant] = $this->records();
        foreach ($applicant->applicantVerificationDocuments as $document) {
            $this->accept($reviewer, $document)->assertOk();
        }
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();
        $oldPath = $document->path;
        $this->actingAs($applicant)->postJson('/dashboard/profile/verification-documents', [
            'document_type' => 'income_record', 'document_file' => UploadedFile::fake()->create('new-income.pdf', 10, 'application/pdf'), 'terms_accepted' => true,
        ])->assertOk();
        $current = $document->fresh();
        $this->assertFalse($current->hasCurrentAcceptance());
        $this->assertNull($current->reviewed_by);
        $this->assertGreaterThan($document->revision, $current->revision);
        $this->assertSame(5, $applicant->applicantVerificationDocuments()->where('status', 'approved')->count());
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertSame(1, $current->reviews()->where('action', 'accepted')->count());
        $this->assertSame(1, $current->reviews()->where('action', 'file_replaced')->count());
        $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$current->id}/review", ['status' => 'approved', 'expected_revision' => $document->revision])->assertConflict();
    }

    public function test_claim_edits_invalidate_reviews_even_while_the_profile_is_pending(): void
    {
        [$reviewer, $applicant] = $this->records();
        $income = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();
        $identity = $applicant->applicantVerificationDocuments()->where('document_type', 'identity_record')->firstOrFail();
        $this->accept($reviewer, $income)->assertOk();
        $this->accept($reviewer, $identity)->assertOk();
        $applicant->studentProfile()->first()->update(['income_bracket' => 'Above PHP 60,000']);
        $this->assertFalse($income->fresh()->hasCurrentAcceptance());
        $this->assertTrue($identity->fresh()->hasCurrentAcceptance());
        $this->assertSame(['income_bracket'], $income->reviews()->where('action', 'claims_changed')->firstOrFail()->changed_fields);
        $this->assertSame('pending', $applicant->fresh()->studentProfile->verification_status);
    }

    public function test_unrelated_contact_changes_do_not_discard_evidence_reviews(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->first();
        $this->accept($reviewer, $document)->assertOk();
        $applicant->studentProfile()->first()->update(['contact_number' => '09179999999']);
        $this->assertTrue($document->fresh()->hasCurrentAcceptance());
    }

    public function test_deletion_keeps_reviewer_history_and_does_not_reset_unrelated_documents(): void
    {
        [$reviewer, $applicant] = $this->records();
        foreach ($applicant->applicantVerificationDocuments as $document) {
            $this->accept($reviewer, $document)->assertOk();
        }
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();
        $event = $document->reviews()->where('action', 'accepted')->firstOrFail();
        $this->actingAs($applicant)->deleteJson("/dashboard/profile/verification-documents/{$document->id}")->assertOk();
        $this->assertNull($event->fresh()->document_id);
        $this->assertSame('income_record', $event->fresh()->document_type);
        $this->assertSame(5, $applicant->applicantVerificationDocuments()->where('status', 'approved')->count());
    }

    public function test_missing_files_cannot_be_accepted_but_a_replacement_can_be_requested(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->first();
        Storage::disk('local')->delete($document->path);
        $this->accept($reviewer, $document)->assertUnprocessable();
        $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$document->id}/review", [
            'status' => 'rejected', 'expected_revision' => 1, 'review_notes' => 'The stored file is unavailable. Please upload it again.',
        ])->assertOk();
    }

    public function test_review_history_is_private_paginated_and_append_only(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->first();
        $this->accept($reviewer, $document)->assertOk();
        $this->getJson("/admin/applicants/{$applicant->id}/evidence-review-history")->assertOk()->assertJsonPath('history.per_page', 25);
        $event = $document->reviews()->first();
        try {
            $event->update(['note' => 'Altered']);
            $this->fail('History was editable.');
        } catch (\LogicException) {
            $this->assertTrue(true);
        }
        $this->actingAs($applicant)->getJson("/admin/applicants/{$applicant->id}/evidence-review-history")->assertForbidden();
    }

    public function test_other_roles_cannot_review_evidence(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->first();
        $accountStaff = User::factory()->create(['role' => 'admin', 'parent_account_id' => $reviewer->parent_account_id, 'permissions' => ['manage_accounts']]);
        $oversight = User::factory()->create(['role' => 'admin', 'parent_account_id' => $reviewer->parent_account_id, 'account_title' => 'Portal manager', 'permissions' => User::ADMIN_PERMISSIONS]);
        foreach ([$applicant, User::factory()->create(['role' => 'provider']), $accountStaff, $oversight] as $actor) {
            $this->accept($actor, $document)->assertForbidden();
        }
    }

    public function test_legacy_approved_documents_do_not_fabricate_individual_reviews(): void
    {
        [$reviewer, $applicant] = $this->records();
        $applicant->applicantVerificationDocuments()->update(['status' => 'approved']);
        $applicant->studentProfile()->update(['verification_status' => 'approved']);
        $this->assertTrue($applicant->fresh()->publicPayload()['is_profile_verified']);
        $this->assertTrue($applicant->fresh()->publicPayload()['evidence_review']['legacy_profile_approval']);
        $applicant->studentProfile()->update(['verification_status' => 'pending']);
        $this->actingAs($reviewer)->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id), 'verification_status' => 'approved'])->assertUnprocessable();
        $this->assertSame(0, ApplicantEvidenceReview::where('action', 'accepted')->count());
    }

    public function test_provider_cannot_bypass_individual_reviews_or_change_application_stages(): void
    {
        [$reviewer, $applicant] = $this->records();
        $provider = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Evidence gate', 'description' => 'Test', 'status' => 'published']);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $this->actingAs($provider)->patchJson("/provider/applications/{$application->id}/profile-verification")->assertUnprocessable()->assertJsonValidationErrors('evidence_reviews');
        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame('pending', $applicant->fresh()->studentProfile->verification_status);
    }

    public function test_reopening_preserves_current_acceptances_and_new_guardian_requirements_still_block_approval(): void
    {
        [$reviewer, $applicant] = $this->records();
        foreach ($applicant->applicantVerificationDocuments as $document) {
            $this->accept($reviewer, $document)->assertOk();
        }
        $this->actingAs($reviewer)->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id), 'verification_status' => 'approved'])->assertOk();
        $this->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id), 'verification_status' => 'pending', 'verification_notes' => 'Please check the identity records again.'])->assertOk();
        $this->assertSame(6, $applicant->applicantVerificationDocuments()->whereNotNull('reviewed_at')->count());
        $this->assertSame(0, ApplicantEvidenceReview::where('action', 'review_reopened')->count());
        $applicant->studentProfile()->first()->update(['birthdate' => now()->subYears(17)->toDateString()]);
        $this->assertContains('guardian_record', $applicant->fresh()->publicPayload()['evidence_review']['missing_types']);
    }

    public function test_delayed_scan_cannot_overwrite_a_newer_review(): void
    {
        [$reviewer, $applicant] = $this->records();
        $applicant->studentProfile()->first()->update(['grading_scale' => 'percentage', 'gwa' => 80]);
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->firstOrFail();
        config(['services.academic_ocr.enabled' => true, 'services.academic_ocr.endpoint' => 'https://api.ocr.space/parse/image', 'services.academic_ocr.key' => 'test-key']);
        Http::fake(function () use ($reviewer, $document) {
            app(ApplicantEvidenceReviewService::class)->review($reviewer, $document, ['status' => 'approved', 'expected_revision' => $document->revision,
                ...($document->document_type === 'academic_record' ? ['academic_grading_scale' => 'percentage', 'academic_result' => 92] : []),
            ]);

            return Http::response(['ParsedResults' => [['ParsedText' => 'GWA: 99']], 'OCRExitCode' => 1, 'IsErroredOnProcessing' => false]);
        });
        $this->actingAs($applicant)->postJson("/dashboard/profile/verification-documents/{$document->id}/scan")
            ->assertOk()->assertJsonFragment(['message' => 'The evidence changed during scanning. The old scan was not applied; reload the current record.']);
        $this->assertSame('92.00', $applicant->fresh()->studentProfile->gwa);
        $this->assertTrue($document->fresh()->hasCurrentAcceptance());
    }

    public function test_applicant_feedback_is_owned_current_and_contains_no_admin_review_links(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();
        $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$document->id}/review", [
            'status' => 'rejected', 'expected_revision' => $document->revision, 'review_notes' => 'Upload the current household income record.',
        ])->assertOk();
        $payload = $this->actingAs($applicant)->getJson('/dashboard/profile/data')->assertOk()->json();
        $record = collect($payload['verification_documents'])->firstWhere('id', $document->id);
        $this->assertSame('Upload the current household income record.', $record['review_notes']);
        $this->assertSame('replacement_requested', $record['review_context']['action']);
        $this->assertTrue($record['file_available']);
        foreach (['path', 'review_url', 'actor_id', 'changed_values'] as $key) {
            $this->assertArrayNotHasKey($key, $record);
        }
        $this->assertTrue($applicant->notifications()->where('action_url', '/dashboard/profile?section=verification&evidence=income_record')->exists());
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/dashboard/profile/data')->assertOk()->assertJsonCount(0, 'verification_documents');
        $this->get("/dashboard/profile/verification-documents/{$document->id}/view")->assertForbidden();
    }

    public function test_stale_applicant_replacement_does_not_overwrite_reviewed_evidence_or_leave_an_orphan_file(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();
        $this->accept($reviewer, $document)->assertOk();
        $files = Storage::disk('local')->allFiles();
        $history = ApplicantEvidenceReview::count();
        foreach ([0, $document->revision] as $staleRevision) {
            $this->actingAs($applicant)->postJson('/dashboard/profile/verification-documents', [
                'document_type' => 'income_record', 'expected_revision' => $staleRevision,
                'document_file' => UploadedFile::fake()->create('income.pdf', 10, 'application/pdf'), 'terms_accepted' => true,
            ])->assertConflict();
        }
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame($history, ApplicantEvidenceReview::count());
        $this->assertTrue($document->fresh()->hasCurrentAcceptance());
    }

    public function test_stale_remove_and_scan_are_blocked_before_modifying_current_evidence(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->firstOrFail();
        $this->accept($reviewer, $document)->assertOk();
        Http::fake();
        $this->actingAs($applicant)->deleteJson("/dashboard/profile/verification-documents/{$document->id}", ['expected_revision' => $document->revision])->assertConflict();
        $this->postJson("/dashboard/profile/verification-documents/{$document->id}/scan", ['expected_revision' => $document->revision])->assertConflict();
        Http::assertNothingSent();
        Storage::disk('local')->assertExists($document->path);
        $this->assertTrue($document->fresh()->hasCurrentAcceptance());
    }

    public function test_a_replacement_preserves_other_open_corrections_and_returns_current_feedback(): void
    {
        [$reviewer, $applicant] = $this->records();
        foreach (['income_record', 'residency_record'] as $type) {
            $document = $applicant->applicantVerificationDocuments()->where('document_type', $type)->firstOrFail();
            $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$document->id}/review", [
                'status' => 'rejected', 'expected_revision' => $document->revision, 'review_notes' => 'Replace '.$type.'.',
            ])->assertOk();
        }
        $income = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();
        $payload = $this->actingAs($applicant)->postJson('/dashboard/profile/verification-documents', [
            'document_type' => 'income_record', 'expected_revision' => $income->revision,
            'document_file' => UploadedFile::fake()->create('current-income.pdf', 10, 'application/pdf'), 'terms_accepted' => true,
        ])->assertOk()->assertJsonPath('user.applicant_verification_status', 'rejected')->json();
        $record = collect($payload['verification_documents'])->firstWhere('id', $income->id);
        $this->assertSame('submitted', $record['status']);
        $this->assertNull($record['review_notes']);
        $this->assertFalse($record['has_current_acceptance']);
        $this->assertSame('file_replaced', $record['review_context']['action']);
        $this->assertSame(['residency_record'], $payload['user']['evidence_review']['replacement_types']);
        $this->assertSame('Replace residency_record.', $payload['user']['applicant_verification_notes']);
        $this->assertSame(1, $income->reviews()->where('action', 'replacement_requested')->count());
    }

    public function test_applicant_claim_changes_return_re_review_state_without_discarding_unrelated_acceptance(): void
    {
        [$reviewer, $applicant] = $this->records();
        $income = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();
        $identity = $applicant->applicantVerificationDocuments()->where('document_type', 'identity_record')->firstOrFail();
        $this->accept($reviewer, $income)->assertOk();
        $this->accept($reviewer, $identity)->assertOk();
        $payload = $this->actingAs($applicant)->patchJson('/dashboard/profile', [
            'profile_section' => 'household', 'income_bracket' => 'Above PHP 60,000', 'current_scholarship_status' => 'none',
        ])->assertOk()->json();
        $record = collect($payload['verification_documents'])->firstWhere('id', $income->id);
        $this->assertSame('claims_changed', $record['review_context']['action']);
        $this->assertSame(['income_bracket'], $record['review_context']['changed_fields']);
        $this->assertFalse($record['has_current_acceptance']);
        $this->assertTrue(collect($payload['verification_documents'])->firstWhere('id', $identity->id)['has_current_acceptance']);
    }

    public function test_correction_to_verification_to_web_and_mobile_application_keeps_existing_stages_on_replacement(): void
    {
        Mail::fake();
        [$reviewer, $applicant] = $this->records();
        Storage::disk('local')->put('profile-photos/evidence-release.jpg', 'Test photo');
        $applicant->studentProfile()->first()->update([
            'school' => 'Release Test College', 'school_type' => 'public', 'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'BS Information Technology', 'year_level' => '1st year',
            'academic_year' => '2026-2027', 'academic_term' => 'first_semester', 'gwa' => 90, 'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000', 'household_size' => 4, 'current_scholarship_status' => 'none',
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'region' => 'NCR', 'profile_photo_path' => 'profile-photos/evidence-release.jpg',
        ]);
        $applicant = $applicant->fresh();
        $this->assertTrue($applicant->applicantProfileReadiness()['complete']);
        $provider = User::factory()->create(['role' => 'provider']);
        $programs = collect(range(1, 3))->map(fn (int $number) => Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Release workflow '.$number, 'description' => 'Integration fixture.',
            'application_mode' => 'provider_review', 'deadline' => now()->addMonth()->toDateString(), 'status' => 'published',
        ]));
        $application = ['scholarship_id' => $programs[0]->id, 'terms_accepted' => true];
        $this->actingAs($applicant)->postJson('/dashboard/applications', $application)->assertForbidden();
        foreach ($applicant->applicantVerificationDocuments()->where('document_type', '!=', 'income_record')->get() as $document) {
            $this->accept($reviewer, $document)->assertOk();
        }
        $income = $applicant->applicantVerificationDocuments()->where('document_type', 'income_record')->firstOrFail();
        $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$income->id}/review", [
            'status' => 'rejected', 'expected_revision' => $income->revision, 'review_notes' => 'Upload the current household income certificate.',
        ])->assertOk();
        $this->actingAs($applicant)->getJson('/dashboard/profile/data')->assertOk()
            ->assertJsonPath('user.evidence_review.replacement_types.0', 'income_record');
        $this->postJson('/dashboard/profile/verification-documents', [
            'document_type' => 'income_record', 'expected_revision' => $income->fresh()->revision,
            'document_file' => UploadedFile::fake()->create('current-income.pdf', 10, 'application/pdf'), 'terms_accepted' => true,
        ])->assertOk()->assertJsonPath('user.evidence_review.accepted_count', 5);
        $this->accept($reviewer, $income->fresh())->assertOk()->assertJsonPath('evidence_review.required_evidence_accepted', true);
        $this->actingAs($applicant->fresh())->postJson('/dashboard/applications', $application)->assertForbidden();
        $this->actingAs($reviewer)->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id),
            'verification_status' => 'approved',
        ])->assertOk()->assertJsonPath('user.is_profile_verified', true);
        $this->actingAs($applicant->fresh())->postJson('/dashboard/applications', $application)->assertCreated();
        $token = bin2hex(random_bytes(24));
        MobileApiToken::create(['user_id' => $applicant->id, 'name' => 'Evidence integration test', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->withToken($token)->postJson('/api/mobile/applications', ['scholarship_id' => $programs[1]->id, 'terms_accepted' => true])->assertCreated();
        $stages = ScholarshipApplication::where('applicant_id', $applicant->id)->pluck('status', 'id')->all();
        $this->actingAs($applicant->fresh())->postJson('/dashboard/profile/verification-documents', [
            'document_type' => 'income_record', 'expected_revision' => $income->fresh()->revision,
            'document_file' => UploadedFile::fake()->create('updated-income.pdf', 10, 'application/pdf'), 'terms_accepted' => true,
        ])->assertOk()->assertJsonPath('user.is_profile_verified', false)->assertJsonPath('user.evidence_review.accepted_count', 5);
        $this->assertSame($stages, ScholarshipApplication::where('applicant_id', $applicant->id)->pluck('status', 'id')->all());
        $this->actingAs($applicant->fresh())->getJson('/dashboard/applications/'.array_key_first($stages).'/data')->assertOk();
        $this->postJson('/dashboard/applications', ['scholarship_id' => $programs[2]->id, 'terms_accepted' => true])->assertForbidden();
        $this->assertSame(1, $income->reviews()->where('action', 'replacement_requested')->count());
    }

    public function test_academic_result_is_saved_with_evidence_not_the_final_profile_decision(): void
    {
        [$reviewer, $applicant] = $this->records();
        $academic = $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->sole();
        $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$academic->id}/review", [
            'status' => 'approved', 'expected_revision' => $academic->revision,
            'academic_grading_scale' => 'grade_point', 'academic_result' => 1.75,
        ])->assertOk()->assertJsonPath('user.gwa', '1.75')->assertJsonPath('user.academic_result_source', 'admin_review')
            ->assertJsonPath('user.applicant_verification_status', 'pending')->assertJsonPath('document.has_current_acceptance', true);
        foreach ($applicant->applicantVerificationDocuments()->where('document_type', '!=', 'academic_record')->get() as $document) {
            $this->accept($reviewer, $document)->assertOk();
        }
        $this->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id),
            'verification_status' => 'approved', 'verification_notes' => 'Identity and supporting records reviewed.',
        ])->assertOk()->assertJsonPath('user.is_profile_verified', true)->assertJsonPath('user.gwa', '1.75');
        $this->assertSame('Identity and supporting records reviewed.', $applicant->fresh()->studentProfile->verification_notes);
    }

    public function test_invalid_academic_values_cannot_accept_or_overwrite_evidence(): void
    {
        [$reviewer, $applicant] = $this->records();
        $academic = $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->sole();
        $before = $applicant->studentProfile->only(['gwa', 'grading_scale', 'academic_result_source']);
        foreach ([[], ['academic_grading_scale' => 'percentage'], ['academic_grading_scale' => 'percentage', 'academic_result' => 101],
            ['academic_grading_scale' => 'grade_point', 'academic_result' => 5.01], ['academic_grading_scale' => [], 'academic_result' => 90]] as $payload) {
            $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$academic->id}/review", [
                'status' => 'approved', 'expected_revision' => $academic->revision, ...$payload,
            ])->assertUnprocessable();
        }
        $this->assertSame($before, $applicant->fresh()->studentProfile->only(['gwa', 'grading_scale', 'academic_result_source']));
        $this->assertSame(1, $academic->fresh()->revision);
        $this->assertFalse($academic->fresh()->hasCurrentAcceptance());
    }

    public function test_grade_values_cannot_be_changed_during_final_verification_or_other_document_reviews(): void
    {
        [$reviewer, $applicant] = $this->records();
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'recent_school_id')->sole();
        $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$document->id}/review", [
            'status' => 'approved', 'expected_revision' => $document->revision,
            'academic_grading_scale' => 'percentage', 'academic_result' => 99,
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_result');
        $this->patchJson("/admin/users/{$applicant->id}/profile-verification", ['expected_review_token' => \Tests\Support\AdminReviewSnapshots::applicant($applicant->id),
            'verification_status' => 'approved', 'academic_grading_scale' => 'percentage', 'academic_result' => 99,
        ])->assertUnprocessable()->assertJsonValidationErrors(['academic_grading_scale', 'academic_result']);
    }

    public function test_non_numeric_academic_review_and_stale_grade_corrections_are_guarded(): void
    {
        [$reviewer, $applicant] = $this->records();
        $academic = $applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->sole();
        $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$academic->id}/review", [
            'status' => 'approved', 'expected_revision' => $academic->revision,
            'academic_grading_scale' => 'pass_fail', 'academic_result' => null,
        ])->assertOk()->assertJsonPath('user.gwa', null)->assertJsonPath('user.grading_scale', 'pass_fail');
        $this->patchJson("/admin/applicant-verification-documents/{$academic->id}/review", [
            'status' => 'approved', 'expected_revision' => $academic->revision,
            'academic_grading_scale' => 'percentage', 'academic_result' => 99,
        ])->assertConflict();
        $this->assertNull($applicant->fresh()->studentProfile->gwa);
    }

    private function records(): array
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin', 'parent_account_id' => $owner->id, 'account_title' => 'Review officer', 'permissions' => ['manage_reviews']]);
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update(['birthdate' => '2000-01-01', 'education_level' => 'college', 'account_managed_by' => 'learner', 'verification_status' => 'pending']);
        foreach (ApplicantVerificationDocument::requiredTypesFor($applicant->fresh()) as $type) {
            $path = "applicant-verification/{$applicant->id}/{$type}.pdf";
            Storage::disk('local')->put($path, 'Test document');
            $applicant->applicantVerificationDocuments()->create(['uploaded_by' => $applicant->id, 'document_type' => $type,
                'original_name' => $type.'.pdf', 'path' => $path, 'mime_type' => 'application/pdf', 'size' => 13, 'status' => 'submitted', 'uploaded_at' => now()]);
        }

        return [$reviewer, $applicant->fresh()];
    }

    private function accept(User $actor, ApplicantVerificationDocument $document)
    {
        return $this->actingAs($actor)->patchJson("/admin/applicant-verification-documents/{$document->id}/review", ['status' => 'approved', 'expected_revision' => $document->fresh()->revision,
            ...($document->document_type === 'academic_record' ? ['academic_grading_scale' => 'percentage', 'academic_result' => 92] : []),
        ]);
    }
}
