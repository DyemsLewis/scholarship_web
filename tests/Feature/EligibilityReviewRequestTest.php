<?php

namespace Tests\Feature;

use App\Models\EligibilityReviewRequest;
use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ScholarshipEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EligibilityReviewRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake(); Storage::fake('local');
    }

    private function context(array $fields = []): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->verifiedProfile()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01', 'account_managed_by' => 'learner', 'education_level' => 'college', 'school' => 'Test University', 'enrollment_status' => 'Enrolled',
            'course_or_strand' => 'BS Information Technology', 'school_type' => 'public', 'year_level' => '1st year', 'academic_year' => '2026-2027', 'academic_term' => 'first_semester',
            'gwa' => 90, 'grading_scale' => 'percentage', 'income_bracket' => 'Below PHP 10,000', 'household_size' => 4, 'current_scholarship_status' => 'none',
            'city' => 'Quezon City', 'province' => 'Metro Manila', 'region' => 'NCR', 'profile_photo_path' => 'profile-photos/test.jpg',
        ]);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Eligibility exception test', 'description' => 'Provider reconsideration.', 'status' => 'published',
            'deadline' => now()->addMonth()->toDateString(), 'application_mode' => 'provider_review', 'minimum_gwa' => 95, 'minimum_grade_scale' => 'percentage', 'eligibility_review_rules' => ['academic'], ...$fields]);
        return [$provider, $applicant->fresh(), $program];
    }

    private function requestReview(User $applicant, Scholarship $program, ?UploadedFile $evidence = null): EligibilityReviewRequest
    {
        $url = "/dashboard/scholarships/{$program->id}/eligibility-review";
        $context = $this->actingAs($applicant)->getJson($url)->assertOk()->assertJsonPath('can_request', true)->json();
        $this->post($url, ['context_hash' => $context['context_hash'], 'explanation' => 'My recent academic improvement and school circumstances deserve individual consideration.', 'confirmed' => '1', ...($evidence ? ['evidence' => $evidence] : [])], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('context.request.status', 'pending')->assertJsonMissingPath('context.request.evidence_path');
        return EligibilityReviewRequest::latest('id')->firstOrFail();
    }

    private function decide(User $provider, EligibilityReviewRequest $record, string $decision = 'approved')
    {
        return $this->actingAs($provider)->patchJson("/provider/eligibility-reviews/{$record->id}/decision", [
            'decision' => $decision, 'decision_note' => 'The explanation and available supporting evidence were reviewed.', 'context_hash' => $record->context_hash, 'evidence_reviewed' => true, 'confirmed' => true,
        ]);
    }

    public function test_picture_request_can_be_reviewed_and_approved_without_auto_submitting_or_changing_the_profile(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $before = app(ScholarshipEligibilityService::class)->evaluate($program, $applicant);
        $record = $this->requestReview($applicant, $program, UploadedFile::fake()->image('support.png', 600, 400));
        Storage::disk('local')->assertExists($record->evidence_path);
        $this->assertDatabaseCount('scholarship_applications', 0);
        $this->actingAs($provider)->getJson('/provider/workspaces/reviews/eligibility-requests/data')->assertOk()->assertJsonPath('requests.total', 1);
        $this->get("/provider/eligibility-reviews/{$record->id}/evidence")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->decide($provider, $record)->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertDatabaseCount('scholarship_applications', 0);
        $after = app(ScholarshipEligibilityService::class)->evaluate($program->fresh(), $applicant->fresh());
        $this->assertTrue($after['is_eligible']);
        $this->assertSame($before['score'], $after['score']);
        $criterion = collect($after['criteria'])->firstWhere('key', 'academic');
        $this->assertSame('fail', $criterion['status']);
        $this->assertTrue($criterion['exception_approved']);
        $this->assertEquals(90, $applicant->fresh()->studentProfile->gwa);
        $this->actingAs($applicant)->postJson('/dashboard/applications', ['scholarship_id' => $program->id, 'terms_accepted' => true])->assertCreated();
        $this->assertNotNull($record->fresh()->consumed_at);
        $this->assertSame($record->fresh()->scholarship_application_id, ScholarshipApplication::sole()->id);
        $this->assertSame('screening', ScholarshipApplication::sole()->workflow_stage);
    }

    public function test_no_attachment_is_required_and_decline_never_unlocks_applying(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $record = $this->requestReview($applicant, $program);
        $this->assertNull($record->evidence_path);
        $this->decide($provider, $record, 'declined')->assertOk();
        $this->actingAs($applicant)->postJson('/dashboard/applications', ['scholarship_id' => $program->id, 'terms_accepted' => true])->assertUnprocessable();
    }

    public function test_closed_rules_preferences_and_disabled_reconsideration_do_not_create_a_request(): void
    {
        [, $applicant, $program] = $this->context(['eligibility_review_rules' => []]);
        $url = "/dashboard/scholarships/{$program->id}/eligibility-review";
        $context = $this->actingAs($applicant)->getJson($url)->assertJsonPath('can_request', false)->json();
        $this->postJson($url, ['context_hash' => $context['context_hash'], 'explanation' => str_repeat('Relevant explanation. ', 3), 'confirmed' => true])->assertUnprocessable();
        $program->update(['eligibility_rule_modes' => ['academic' => 'preferred'], 'eligibility_review_rules' => ['academic']]);
        $this->getJson($url)->assertJsonPath('can_request', false)->assertJsonPath('criteria', []);
        $this->assertDatabaseCount('eligibility_review_requests', 0);
    }

    public function test_rule_or_profile_changes_invalidate_approval_and_allow_a_new_context_request(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $record = $this->requestReview($applicant, $program);
        $this->decide($provider, $record)->assertOk();
        $program->update(['minimum_gwa' => 96]);
        $this->assertFalse(app(ScholarshipEligibilityService::class)->evaluate($program->fresh(), $applicant->fresh())['is_eligible']);
        $next = $this->requestReview($applicant, $program->fresh());
        $this->assertNotSame($record->context_hash, $next->context_hash);
        $applicant->studentProfile()->update(['gwa' => 89]);
        $this->decide($provider, $next)->assertStatus(409);
        $this->assertSame('pending', $next->fresh()->status);
    }

    public function test_repeated_requests_or_provider_decisions_cannot_duplicate_or_reverse_an_outcome(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $record = $this->requestReview($applicant, $program);
        $this->actingAs($applicant)->postJson("/dashboard/scholarships/{$program->id}/eligibility-review", ['context_hash' => $record->context_hash, 'explanation' => str_repeat('Consider this request. ', 3), 'confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('eligibility_review_requests', 1);
        $this->decide($provider, $record)->assertOk();
        $this->decide($provider, $record, 'declined')->assertStatus(409);
        $this->assertSame('approved', $record->fresh()->status);
    }

    public function test_reopened_or_changed_intake_cannot_reuse_an_old_approval(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $record = $this->requestReview($applicant, $program);
        $this->decide($provider, $record)->assertOk();
        $program->update(['deadline' => now()->addMonths(2)->toDateString()]);
        $this->assertFalse(app(ScholarshipEligibilityService::class)->evaluate($program->fresh(), $applicant->fresh())['is_eligible']);
        $this->actingAs($applicant)->getJson("/dashboard/scholarships/{$program->id}/eligibility-review")->assertJsonPath('request.context_matches', false);
    }

    public function test_full_intake_and_additional_nonreviewable_mismatch_still_block_requests(): void
    {
        [, $applicant, $program] = $this->context(['eligible_locations' => 'Cebu City']);
        $url = "/dashboard/scholarships/{$program->id}/eligibility-review";
        $this->actingAs($applicant)->getJson($url)->assertJsonPath('can_request', false);
        $program->update(['eligible_locations' => null, 'application_limit' => 1]);
        $other = User::factory()->verifiedProfile()->create();
        ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $other->id, 'status' => 'submitted', 'submitted_at' => now()]);
        $this->getJson($url)->assertJsonPath('can_request', false);
        $this->assertDatabaseCount('eligibility_review_requests', 0);
    }

    public function test_provider_can_save_only_known_reconsideration_rule_keys(): void
    {
        [$provider] = $this->context();
        $this->actingAs($provider)->postJson('/provider/scholarships', ['title' => 'Optional exception policy', 'status' => 'draft', 'eligibility_review_rules' => ['academic', 'location']])
            ->assertCreated()->assertJsonPath('scholarship.eligibility_review_rules', ['academic', 'location']);
        $this->postJson('/provider/scholarships', ['title' => 'Invalid waiver', 'status' => 'draft', 'eligibility_review_rules' => ['documents']])
            ->assertUnprocessable()->assertJsonValidationErrors('eligibility_review_rules.0');
    }

    public function test_evidence_is_private_and_assigned_program_access_and_role_are_enforced(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $record = $this->requestReview($applicant, $program, UploadedFile::fake()->image('photo.jpg'));
        $other = User::factory()->verifiedProfile()->create();
        $this->actingAs($other)->get("/dashboard/eligibility-reviews/{$record->id}/evidence")->assertForbidden();
        $foreign = User::factory()->create(['role' => 'provider']);
        $foreign->providerProfile()->update(['verification_status' => 'approved']);
        $this->actingAs($foreign)->getJson("/provider/eligibility-reviews/{$record->id}/data")->assertForbidden();
        $this->getJson('/provider/workspaces/reviews/eligibility-requests/data')->assertJsonPath('requests.total', 0);
        $staff = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'permissions' => ['verify_applications'], 'assigned_program_ids' => []]);
        $this->actingAs($staff)->getJson("/provider/eligibility-reviews/{$record->id}/data")->assertForbidden();
        $staff->update(['assigned_program_ids' => [$program->id]]);
        $this->actingAs($staff->fresh())->getJson("/provider/eligibility-reviews/{$record->id}/data")->assertOk();
        $staff->update(['permissions' => ['manage_programs']]);
        $this->actingAs($staff->fresh())->getJson("/provider/eligibility-reviews/{$record->id}/data")->assertForbidden();
    }

    public function test_bad_uploads_and_unreviewed_evidence_are_rejected(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $url = "/dashboard/scholarships/{$program->id}/eligibility-review";
        $hash = $this->actingAs($applicant)->getJson($url)->json('context_hash');
        foreach ([UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml'), UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')] as $file) {
            $this->post($url, ['context_hash' => $hash, 'explanation' => str_repeat('Explanation to review. ', 3), 'confirmed' => true, 'evidence' => $file], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('evidence');
        }
        $this->assertDatabaseCount('eligibility_review_requests', 0);
        $record = $this->requestReview($applicant, $program, UploadedFile::fake()->image('evidence.png'));
        $this->actingAs($provider)->patchJson("/provider/eligibility-reviews/{$record->id}/decision", ['context_hash' => $hash, 'decision' => 'approved', 'decision_note' => 'Reviewed the explanation.', 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('evidence_reviewed');
    }

    public function test_approval_does_not_bypass_a_closed_intake_profile_verification_or_required_documents(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $record = $this->requestReview($applicant, $program);
        $this->decide($provider, $record)->assertOk();
        $applicant->studentProfile()->update(['verification_status' => 'pending']);
        $this->actingAs($applicant->fresh())->postJson('/dashboard/applications', ['scholarship_id' => $program->id, 'terms_accepted' => true])->assertForbidden();
        $applicant->studentProfile()->update(['verification_status' => 'approved']);
        $program->update(['application_mode' => 'online', 'requirements' => 'Certificate of enrollment']);
        $this->actingAs($applicant->fresh())->postJson('/dashboard/applications', ['scholarship_id' => $program->id, 'terms_accepted' => true])->assertUnprocessable()->assertJsonStructure(['missing_documents']);
        $program->update(['application_mode' => 'provider_review', 'deadline' => now()->subDay()]);
        $this->postJson('/dashboard/applications', ['scholarship_id' => $program->id, 'terms_accepted' => true])->assertUnprocessable();
        $this->assertDatabaseCount('scholarship_applications', 0);
    }

    public function test_mobile_submission_uses_the_same_approved_exception_and_retains_the_original_mismatch(): void
    {
        [$provider, $applicant, $program] = $this->context();
        $record = $this->requestReview($applicant, $program);
        $this->decide($provider, $record)->assertOk();
        $token = bin2hex(random_bytes(20));
        MobileApiToken::create(['user_id' => $applicant->id, 'name' => 'eligibility-review', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->withToken($token)->postJson('/api/mobile/applications', ['scholarship_id' => $program->id, 'terms_accepted' => true])->assertCreated();
        $criterion = collect(ScholarshipApplication::sole()->eligibility_breakdown['criteria'])->firstWhere('key', 'academic');
        $this->assertTrue($criterion['exception_approved']);
        $this->assertSame('fail', $criterion['status']);
        $this->assertNotNull($record->fresh()->consumed_at);
    }
}
