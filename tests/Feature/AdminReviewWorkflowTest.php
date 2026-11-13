<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use App\Models\Scholarship;
use App\Models\User;
use App\Queries\Admin\RecordsWorkspaceQuery;
use App\Services\AdminReviewWorkflowService;
use App\Services\ApplicantEvidenceReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_applicant_final_decision_requires_the_displayed_snapshot(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $this->actingAs($reviewer)->patchJson("/admin/users/{$applicant->id}/profile-verification", ['verification_status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_review_token');
        $token = $this->applicantPayload($reviewer, $applicant)['review_workflow']['token'];
        $this->decide($reviewer, $applicant, $token)->assertOk()->assertJsonPath('applicant.review_workflow.outcome', 'approved');
        $this->assertSame(1, ActivityLog::where('action', 'applicant_profile_verification_updated')->count());
    }

    public function test_another_reviewers_result_cannot_be_overwritten_from_an_old_tab(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $other = User::factory()->create(['role' => 'admin']);
        $token = $this->applicantPayload($reviewer, $applicant)['review_workflow']['token'];
        $this->decide($other, $applicant, $token)->assertOk();
        $this->decide($reviewer, $applicant, $token, 'rejected', 'Please replace the ID.')->assertConflict();
        $this->assertSame('approved', $applicant->fresh()->studentProfile->verification_status);
        $this->assertSame(1, ActivityLog::where('action', 'applicant_profile_verification_updated')->count());
    }

    public function test_profile_edit_during_review_invalidates_only_affected_evidence_and_the_final_snapshot(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $token = $this->applicantPayload($reviewer, $applicant)['review_workflow']['token'];
        $applicant->studentProfile()->first()->update(['city' => 'Quezon City']);
        $this->decide($reviewer, $applicant, $token)->assertConflict();
        $this->assertFalse($applicant->applicantVerificationDocuments()->where('document_type', 'residency_record')->sole()->hasCurrentAcceptance());
        $this->assertTrue($applicant->applicantVerificationDocuments()->where('document_type', 'academic_record')->sole()->hasCurrentAcceptance());
    }

    public function test_replacement_is_highlighted_and_other_acceptances_are_kept(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $token = $this->applicantPayload($reviewer, $applicant)['review_workflow']['token'];
        $this->decide($reviewer, $applicant, $token)->assertOk();
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'identity_record')->sole();
        $document->update(['original_name' => 'updated-identity.pdf']);
        $payload = $this->applicantPayload($reviewer, $applicant);
        $this->assertSame(1, $payload['review_workflow']['review_count']);
        $this->assertSame(5, $payload['review_workflow']['accepted_count']);
        $this->assertContains('evidence:identity_record', $payload['review_workflow']['changed_keys']);
        $this->assertFalse($payload['review_workflow']['ready']);
        $this->assertSame('pending', $payload['review_workflow']['outcome']);
    }

    public function test_reopening_overall_review_keeps_document_acceptances_but_blocks_applying_until_final_verification(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $token = $this->applicantPayload($reviewer, $applicant)['review_workflow']['token'];
        $approved = $this->decide($reviewer, $applicant, $token)->assertOk()->json('applicant');
        $this->decide($reviewer, $applicant, $approved['review_workflow']['token'], 'pending', 'Recheck the overall profile result.')
            ->assertOk()->assertJsonPath('user.is_profile_verified', false)
            ->assertJsonPath('applicant.review_workflow.accepted_count', 6);
        $this->assertTrue($applicant->applicantVerificationDocuments()->get()->every(fn ($document) => $document->hasCurrentAcceptance()));
        $this->assertSame('Recheck the overall profile result.', $this->applicantPayload($reviewer, $applicant)['review_workflow']['previous_note']);
    }

    public function test_document_decision_refreshes_the_final_review_token(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $before = $this->applicantPayload($reviewer, $applicant)['review_workflow']['token'];
        $document = $applicant->applicantVerificationDocuments()->where('document_type', 'identity_record')->sole();
        $payload = $this->actingAs($reviewer)->patchJson("/admin/applicant-verification-documents/{$document->id}/review", [
            'status' => 'rejected', 'expected_revision' => $document->revision, 'review_notes' => 'Upload a readable identity record.',
        ])->assertOk()->json();
        $this->assertNotSame($before, $payload['review_workflow']['token']);
        $this->assertSame(1, $payload['review_workflow']['waiting_count']);
    }

    public function test_missing_required_file_cannot_be_approved_even_with_a_current_snapshot(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $document = $applicant->applicantVerificationDocuments()->first();
        Storage::disk('local')->delete($document->path);
        $payload = $this->applicantPayload($reviewer, $applicant);
        $this->assertFalse($payload['review_workflow']['ready']);
        $this->decide($reviewer, $applicant, $payload['review_workflow']['token'])->assertUnprocessable();
    }

    public function test_program_record_token_survives_analytics_but_rejects_changed_program_details(): void
    {
        [$reviewer, $program] = $this->program();
        $token = $this->actingAs($reviewer)->getJson("/admin/scholarships/{$program->id}/review/data")->assertOk()->json('scholarship.review_workflow.token');
        $program->increment('views_count');
        $this->actingAs($reviewer)->patchJson("/admin/scholarships/{$program->id}/review", [
            'status' => 'rejected', 'review_notes' => 'Clarify the required enrollment evidence.', 'expected_review_token' => $token,
        ])->assertOk();
        $program->refresh()->update(['status' => 'pending_review']);
        $token = $this->actingAs($reviewer)->getJson("/admin/scholarships/{$program->id}/review/data")->json('scholarship.review_workflow.token');
        $program->refresh()->update(['requirements' => 'Updated required records']);
        $this->actingAs($reviewer)->patchJson("/admin/scholarships/{$program->id}/review", [
            'status' => 'pending_review', 'expected_review_token' => $token,
        ])->assertConflict();
    }

    public function test_program_resubmission_shows_the_previous_note_changes_and_publication_blockers(): void
    {
        [$reviewer, $program] = $this->program();
        $token = app(AdminReviewWorkflowService::class)->token('program', $program->fresh());
        $this->actingAs($reviewer)->patchJson("/admin/scholarships/{$program->id}/review", [
            'status' => 'rejected', 'review_notes' => 'Clarify the applicant terms.', 'expected_review_token' => $token,
        ])->assertOk();
        $program->refresh()->update(['status' => 'pending_review', 'description' => 'Updated applicant terms and support.']);
        $payload = $this->actingAs($reviewer)->getJson("/admin/scholarships/{$program->id}/review/data")->assertOk()->json('scholarship.review_workflow');
        $this->assertContains('details:program', $payload['changed_keys']);
        $this->assertSame('Clarify the applicant terms.', $payload['previous_note']);
        $this->assertNotEmpty($payload['blockers']);
        $this->assertFalse($payload['ready']);
        $this->assertCount(1, $payload['history']);
        $this->assertStringNotContainsString('Updated applicant terms', json_encode(ActivityLog::latest('id')->first()->metadata['review_fingerprint']));
    }

    public function test_managers_can_inspect_but_cannot_replace_a_review_officers_decision(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $manager = User::factory()->create(['role' => 'admin', 'parent_account_id' => $reviewer->parent_account_id,
            'account_title' => 'Portal manager', 'permissions' => User::ADMIN_PERMISSIONS]);
        $this->actingAs($manager)->getJson("/admin/applicants/{$applicant->id}/review/data")->assertOk()->assertJsonPath('applicant.can_review_evidence', false);
        $token = app(AdminReviewWorkflowService::class)->token('applicant', $applicant->fresh());
        $this->decide($manager, $applicant, $token)->assertForbidden();
    }

    public function test_review_fingerprints_remain_in_the_audit_record_without_flooding_the_visible_metadata(): void
    {
        $entry = ActivityLog::create(['action' => 'applicant_profile_verification_updated', 'description' => 'Recorded verification.',
            'metadata' => ['verification_status' => 'approved', 'review_fingerprint' => ['evidence:identity_record' => str_repeat('a', 64)]]]);
        $visible = app(RecordsWorkspaceQuery::class)->record($entry)['metadata'];
        $this->assertNotContains('Review Fingerprint', $visible->pluck('label')->all());
        $this->assertArrayHasKey('review_fingerprint', $entry->fresh()->metadata);
    }

    public function test_photo_review_rejects_an_outdated_picture_snapshot(): void
    {
        [$reviewer, $applicant] = $this->applicant();
        $applicant->studentProfile()->update(['profile_photo_path' => 'old-photo.jpg']);
        $token = $this->applicantPayload($reviewer, $applicant)['review_workflow']['token'];
        $applicant->studentProfile()->update(['profile_photo_path' => 'new-photo.jpg']);
        $this->actingAs($reviewer)->patchJson("/admin/applicants/{$applicant->id}/profile-photo-review", [
            'action' => 'approve', 'expected_review_token' => $token,
        ])->assertConflict();
        $this->assertNotSame('approved', $applicant->fresh()->studentProfile->profile_photo_review_status);
    }

    public function test_manager_provider_oversight_does_not_grant_document_review_authority(): void
    {
        $primary = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'admin', 'parent_account_id' => $primary->id,
            'account_title' => 'Portal manager', 'permissions' => User::ADMIN_PERMISSIONS]);
        $provider = User::factory()->create(['role' => 'provider']);
        $proof = $provider->providerVerificationDocuments()->create(['uploaded_by' => $provider->id,
            'document_type' => 'organization_registration', 'original_name' => 'registration.pdf', 'path' => 'registration.pdf', 'uploaded_at' => now()]);
        Storage::disk('local')->put('registration.pdf', 'Test proof');
        $this->actingAs($manager)->getJson("/admin/providers/{$provider->id}/review/data")->assertOk()->assertJsonPath('provider.can_review_evidence', false);
        $this->actingAs($manager)->patchJson('/admin/provider-verification-documents/'.$proof->id.'/review', [
            'status' => 'approved', 'compared' => true, 'expected_revision' => $proof->fresh()->revision,
        ])->assertForbidden();
    }

    private function applicant(): array
    {
        $primary = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin', 'parent_account_id' => $primary->id, 'account_title' => 'Review officer', 'permissions' => ['manage_reviews']]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $applicant->studentProfile()->update(['birthdate' => '2000-01-01', 'education_level' => 'college', 'account_managed_by' => 'learner', 'verification_status' => 'pending']);
        foreach (ApplicantVerificationDocument::requiredTypesFor($applicant->fresh()) as $type) {
            $path = 'verification/'.$applicant->id.'/'.$type.'.pdf';
            Storage::disk('local')->put($path, 'Test evidence');
            $document = $applicant->applicantVerificationDocuments()->create([
                'uploaded_by' => $applicant->id, 'document_type' => $type, 'original_name' => $type.'.pdf', 'path' => $path, 'uploaded_at' => now(),
            ]);
            app(ApplicantEvidenceReviewService::class)->review($reviewer, $document, [
                'status' => 'approved', 'expected_revision' => $document->fresh()->revision,
                ...($type === 'academic_record' ? ['academic_grading_scale' => 'percentage', 'academic_result' => 90] : []),
            ]);
        }

        return [$reviewer, $applicant->fresh()];
    }

    private function applicantPayload(User $reviewer, User $applicant): array
    {
        return $this->actingAs($reviewer)->getJson("/admin/applicants/{$applicant->id}/review/data")->assertOk()->json('applicant');
    }

    private function decide(User $actor, User $applicant, string $token, string $status = 'approved', ?string $note = null)
    {
        return $this->actingAs($actor)->patchJson("/admin/users/{$applicant->id}/profile-verification", [
            'verification_status' => $status, 'verification_notes' => $note, 'expected_review_token' => $token,
        ]);
    }

    private function program(): array
    {
        $reviewer = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);

        return [$reviewer, Scholarship::create(['provider_id' => $provider->id, 'title' => 'Publication review fixture', 'description' => 'Applicant terms.', 'status' => 'pending_review'])];
    }
}
