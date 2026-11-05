<?php

namespace Tests\Feature;

use App\Models\ProviderVerificationDocument;
use App\Models\User;
use App\Services\ProviderVerificationReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderIndividualDocumentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->reviewer = User::factory()->create(['role' => 'admin']);
        $this->provider = User::factory()->create(['role' => 'provider']);
        $this->provider->providerProfile()->update(['verification_status' => 'pending']);
    }

    private function proof(string $type = 'organization_registration'): ProviderVerificationDocument
    {
        $path = 'provider-verification/'.$this->provider->id.'/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4 demo organization proof');

        return $this->provider->providerVerificationDocuments()->create([
            'uploaded_by' => $this->provider->id, 'document_type' => $type,
            'original_name' => $type.'.pdf', 'path' => $path, 'mime_type' => 'application/pdf',
            'size' => 100, 'uploaded_at' => now(), 'status' => 'submitted',
        ])->fresh();
    }

    private function token(): string
    {
        return app(ProviderVerificationReviewService::class)->token($this->provider->fresh());
    }

    private function review(ProviderVerificationDocument $proof, array $data = [])
    {
        return $this->actingAs($this->reviewer)->patchJson('/admin/provider-verification-documents/'.$proof->id.'/review', [
            'status' => 'approved', 'expected_revision' => $proof->fresh()->revision,
            'compared' => true, ...$data,
        ]);
    }

    private function decide(string $status = 'approved', array $data = [])
    {
        return $this->actingAs($this->reviewer)->patchJson('/admin/providers/'.$this->provider->id.'/verification', [
            'verification_status' => $status, 'expected_review_token' => $this->token(), ...$data,
        ]);
    }

    public function test_individual_acceptance_does_not_verify_the_provider_and_records_reviewer_feedback(): void
    {
        $proof = $this->proof();
        $this->review($proof, ['review_notes' => 'Registration matches.'])->assertOk();
        $this->assertDatabaseHas('provider_verification_documents', [
            'id' => $proof->id, 'status' => 'approved', 'reviewed_by' => $this->reviewer->id,
            'revision' => 2, 'review_notes' => 'Registration matches.',
        ]);
        $this->assertNotNull($proof->fresh()->reviewed_at);
        $this->assertSame('pending', $this->provider->fresh()->providerProfile->verification_status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'provider_document_reviewed']);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $this->provider->id, 'type' => 'provider_document_review']);
        $this->actingAs($this->provider)->getJson('/provider/profile/data')->assertOk()
            ->assertJsonPath('verification_documents.0.reviewed_by', $this->reviewer->name)
            ->assertJsonPath('verification_documents.0.review_status', 'approved');
    }

    public function test_all_current_documents_must_be_accepted_before_account_approval(): void
    {
        $first = $this->proof();
        $second = $this->proof('authorization_letter');
        $this->decide()->assertUnprocessable()->assertJsonValidationErrors('verification_status');
        $this->review($first)->assertOk();
        $this->decide()->assertUnprocessable();
        $this->review($second)->assertOk();
        $this->decide()->assertOk()->assertJsonPath('provider.verification_status', 'approved')
            ->assertJsonPath('provider.document_review.accepted', 2);
        $this->assertSame(2, $first->fresh()->revision);
        $this->assertSame('approved', $second->fresh()->status);
    }

    public function test_final_decisions_do_not_bulk_overwrite_document_outcomes_or_notes(): void
    {
        $first = $this->proof();
        $second = $this->proof('valid_id');
        $this->review($first, ['review_notes' => 'Matches the registered organization.'])->assertOk();
        $this->review($second, ['status' => 'rejected', 'review_notes' => 'Upload a readable ID.'])->assertOk();
        $this->decide('rejected', ['verification_notes' => 'The representative ID needs correction.'])->assertOk();
        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('Upload a readable ID.', $second->fresh()->review_notes);
        $this->decide('pending')->assertOk();
        $this->assertSame('rejected', $second->fresh()->status);
        $this->decide()->assertUnprocessable();
    }

    public function test_acceptance_requires_comparison_acknowledgement_and_replacement_requires_a_reason(): void
    {
        $proof = $this->proof();
        $this->review($proof, ['compared' => false])->assertUnprocessable()->assertJsonValidationErrors('compared');
        $this->review($proof, ['status' => 'rejected', 'review_notes' => '   '])->assertUnprocessable()->assertJsonValidationErrors('review_notes');
        $this->assertSame('submitted', $proof->fresh()->status);
    }

    public function test_stale_document_decisions_cannot_overwrite_another_review(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        $this->review($proof, ['expected_revision' => 1, 'status' => 'rejected', 'review_notes' => 'Old review.'])->assertConflict();
        $this->assertSame('approved', $proof->fresh()->status);
    }

    public function test_missing_files_cannot_be_accepted_but_can_have_replacement_requested(): void
    {
        $proof = $this->proof();
        Storage::disk('local')->delete($proof->path);
        $this->review($proof)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->review($proof, ['status' => 'rejected', 'review_notes' => 'Please upload the missing file.'])->assertOk();
    }

    public function test_final_approval_also_rechecks_file_availability(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        Storage::disk('local')->delete($proof->path);
        $this->decide()->assertUnprocessable()->assertJsonValidationErrors('verification_status');
    }

    public function test_final_decision_requires_a_current_review_token(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        $this->actingAs($this->reviewer)->patchJson('/admin/providers/'.$this->provider->id.'/verification', [
            'verification_status' => 'approved',
        ])->assertUnprocessable()->assertJsonValidationErrors('expected_review_token');
        $token = $this->token();
        $this->proof('valid_id');
        $this->decide('approved', ['expected_review_token' => $token])->assertConflict();
        $this->assertSame('pending', $this->provider->fresh()->providerProfile->verification_status);
    }

    public function test_a_deleted_or_replaced_file_invalidates_the_final_decision_snapshot(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        $token = $this->token();
        $this->actingAs($this->provider)->deleteJson('/provider/verification-documents/'.$proof->id)->assertOk();
        $this->proof();
        $this->decide('approved', ['expected_review_token' => $token])->assertConflict();
        $this->actingAs($this->reviewer)->patchJson('/admin/provider-verification-documents/'.$proof->id.'/review', [
            'status' => 'approved', 'expected_revision' => 2, 'compared' => true,
        ])->assertNotFound();
    }

    public function test_legal_details_changes_invalidate_document_reviews_even_before_final_approval(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        $token = $this->token();
        $this->actingAs($this->provider)->patchJson('/provider/workspaces/organization-profile/legal', [
            'legal_name' => 'Updated Demo Organization', 'registration_authority' => 'SEC',
            'registration_number' => 'DEMO-002',
        ])->assertOk();
        $this->assertSame('submitted', $proof->fresh()->status);
        $this->assertNull($proof->fresh()->reviewed_by);
        $this->assertSame(3, $proof->fresh()->revision);
        $this->decide('approved', ['expected_review_token' => $token])->assertConflict();
        $this->review($proof, ['expected_revision' => 2])->assertConflict();
    }

    public function test_new_uploads_reopen_approved_accounts_without_rejecting_accepted_files(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        $this->decide()->assertOk();
        $this->actingAs($this->provider->fresh())->post('/provider/verification-documents', [
            'document_type' => 'authorization_letter', 'terms_accepted' => '1',
            'document_file' => UploadedFile::fake()->create('authorization.pdf', 100, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('user.verification_status', 'pending');
        $this->assertSame('approved', $proof->fresh()->status);
        $this->decide()->assertUnprocessable();
    }

    public function test_legacy_bulk_approvals_are_not_treated_as_individual_reviews(): void
    {
        $proof = $this->proof();
        $proof->update(['status' => 'approved']);
        $this->provider->providerProfile()->update(['verification_status' => 'approved']);
        $this->actingAs($this->reviewer)->getJson('/admin/providers/'.$this->provider->id.'/review/data')->assertOk()
            ->assertJsonPath('provider.verification_status', 'approved')
            ->assertJsonPath('provider.document_review.can_approve', false)
            ->assertJsonPath('provider.verification_documents.0.review_status', 'submitted');
        $this->decide()->assertUnprocessable();
    }

    public function test_document_decision_changes_reopen_a_previously_approved_provider(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        $this->decide()->assertOk();
        $this->review($proof, ['status' => 'rejected', 'review_notes' => 'A clearer record is required.'])->assertOk();
        $this->assertSame('pending', $this->provider->fresh()->providerProfile->verification_status);
        $this->assertNull($this->provider->fresh()->providerProfile->verified_by);
    }

    public function test_other_roles_cannot_record_document_decisions(): void
    {
        $proof = $this->proof();
        $restricted = User::factory()->create(['role' => 'admin', 'parent_account_id' => $this->reviewer->id, 'permissions' => ['manage_accounts']]);
        foreach ([$restricted, $this->provider, User::factory()->create(['role' => 'applicant'])] as $actor) {
            $this->actingAs($actor)->patchJson('/admin/provider-verification-documents/'.$proof->id.'/review', [
                'status' => 'approved', 'expected_revision' => 1, 'compared' => true,
            ])->assertForbidden();
        }
        $this->assertSame('submitted', $proof->fresh()->status);
    }

    public function test_replacement_removes_old_rejected_proof_without_reusing_its_review(): void
    {
        $proof = $this->proof();
        $this->review($proof, ['status' => 'rejected', 'review_notes' => 'Upload a clear copy.'])->assertOk();
        $response = $this->actingAs($this->provider)->post('/provider/verification-documents', [
            'document_type' => 'organization_registration', 'terms_accepted' => '1',
            'replaces_document_id' => $proof->id, 'expected_revision' => $proof->fresh()->revision,
            'document_file' => UploadedFile::fake()->create('clear-registration.pdf', 100, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('document.review_status', 'submitted')
            ->assertJsonPath('document.individually_reviewed', false)->assertJsonCount(1, 'verification_documents');
        $this->assertNotSame($proof->id, $response->json('document.id'));
        Storage::disk('local')->assertMissing($proof->path);
        $this->decide()->assertUnprocessable();
    }

    public function test_representative_identity_changes_require_new_comparisons_but_staff_changes_do_not(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        $this->decide()->assertOk();
        $staff = User::factory()->create(['role' => 'provider', 'parent_account_id' => $this->provider->id, 'permissions' => ['manage_programs']]);
        foreach ([$staff, $this->provider] as $actor) {
            $this->actingAs($actor)->patchJson('/provider/account', [
                'first_name' => 'Updated Representative', 'last_name' => $actor->providerProfile->last_name,
                'middle_initial' => $actor->providerProfile->middle_initial,
                'email' => $actor->email, 'username' => $actor->username,
                'contact_number' => $actor->providerProfile->contact_number, 'current_password' => 'password',
            ])->assertOk()->assertJsonPath('verification_reset', ! $actor->isManagedAccount());
            $this->assertSame($actor->isManagedAccount() ? 'approved' : 'submitted', $proof->fresh()->status);
        }
    }

    public function test_replacement_cannot_remove_someone_elses_proof_or_overwrite_a_newer_review(): void
    {
        $proof = $this->proof();
        $this->review($proof)->assertOk();
        $payload = ['document_type' => 'organization_registration', 'terms_accepted' => '1',
            'replaces_document_id' => $proof->id, 'expected_revision' => 1,
            'document_file' => UploadedFile::fake()->create('replacement.pdf', 100, 'application/pdf')];
        $this->actingAs($this->provider)->postJson('/provider/verification-documents', $payload)->assertConflict();
        $other = User::factory()->create(['role' => 'provider']);
        $this->actingAs($other)->postJson('/provider/verification-documents', $payload)->assertUnprocessable();
        $this->assertSame('approved', $proof->fresh()->status);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }
}
