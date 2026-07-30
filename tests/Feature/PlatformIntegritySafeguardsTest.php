<?php

namespace Tests\Feature;

use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationDocument;
use App\Models\ProviderVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformIntegritySafeguardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_publish_until_provider_proof_and_program_data_are_valid(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = $this->scholarship($provider, ['status' => 'pending_review']);

        $this->actingAs($admin)
            ->patchJson("/admin/scholarships/{$scholarship->id}/review", ['status' => 'published'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->providerProof($provider, 'approved');

        $this->actingAs($admin)
            ->patchJson("/admin/scholarships/{$scholarship->id}/review", ['status' => 'published'])
            ->assertOk()
            ->assertJsonPath('scholarship.status', 'published');
    }

    public function test_admin_cannot_approve_a_provider_without_uploaded_proof(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'pending']);

        $this->actingAs($admin)
            ->patchJson("/admin/providers/{$provider->id}/verification", ['verification_status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The provider must upload at least one proof before the account can be approved.');

        $proof = $this->providerProof($provider);

        $this->actingAs($admin)
            ->patchJson("/admin/providers/{$provider->id}/verification", ['verification_status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('provider.verification_status', 'approved');

        $this->assertSame('approved', $proof->fresh()->status);
    }

    public function test_programs_from_a_rejected_provider_are_hidden_and_cannot_receive_applications(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeApplicant();
        $scholarship = $this->scholarship($provider);
        $provider->providerProfile()->update(['verification_status' => 'rejected']);

        $this->assertFalse($scholarship->fresh()->isPubliclyVisible());
        $this->assertFalse(Scholarship::query()->acceptingApplications()->whereKey($scholarship)->exists());

        $this->actingAs($applicant)
            ->getJson("/dashboard/scholarships/{$scholarship->id}/data")
            ->assertNotFound();

        $this->actingAs($applicant)
            ->postJson('/dashboard/applications', [
                'scholarship_id' => $scholarship->id,
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This scholarship is no longer accepting applications.');
    }

    public function test_verified_applicant_changes_return_the_profile_and_proofs_to_review(): void
    {
        $applicant = $this->completeApplicant();
        $profile = $applicant->studentProfile;
        $profile->update(['verification_status' => 'approved', 'verified_at' => now()]);
        $proof = ApplicantVerificationDocument::create([
            'applicant_id' => $applicant->id,
            'uploaded_by' => $applicant->id,
            'document_type' => 'school_id',
            'original_name' => 'school-id.pdf',
            'path' => "applicant-verification/{$applicant->id}/school-id.pdf",
            'mime_type' => 'application/pdf',
            'size' => 100,
            'status' => 'approved',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($applicant)
            ->patchJson('/dashboard/profile', [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'middle_initial' => $profile->middle_initial,
                'contact_number' => $profile->contact_number,
                'school' => 'Updated Test School',
                'grading_scale' => $profile->grading_scale,
                'gwa' => $profile->gwa,
            ])
            ->assertOk()
            ->assertJsonPath('verification_reset', true)
            ->assertJsonPath('user.applicant_verification_status', 'pending');

        $this->assertSame('submitted', $proof->fresh()->status);
        $this->assertNull($applicant->studentProfile()->first()->verified_at);
    }

    public function test_verified_provider_changes_return_the_organization_and_proofs_to_review(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $profile = $provider->providerProfile;
        $proof = $this->providerProof($provider, 'approved');

        $this->actingAs($provider)
            ->patchJson('/provider/profile', [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'middle_initial' => $profile->middle_initial,
                'email' => $provider->email,
                'username' => $provider->username,
                'contact_number' => $profile->contact_number,
                'provider_name' => $profile->provider_name.' Updated',
                'provider_type' => $profile->provider_type,
                'provider_website' => $profile->provider_website,
                'provider_address' => $profile->provider_address,
                'provider_description' => $profile->provider_description,
            ])
            ->assertOk()
            ->assertJsonPath('verification_reset', true);

        $this->assertSame('pending', $provider->providerProfile()->first()->verification_status);
        $this->assertSame('submitted', $proof->fresh()->status);
    }

    public function test_application_status_cannot_skip_configured_stages(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create();
        $scholarship = $this->scholarship($provider, [
            'selection_stages' => ['screening', 'exam', 'distribution'],
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", ['status' => 'approved'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('submitted', $application->fresh()->status);
    }

    public function test_finalized_application_files_are_locked_unless_replacement_is_requested(): void
    {
        Storage::fake('local');
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create();
        $scholarship = $this->scholarship($provider);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'approved',
            'submitted_at' => now(),
        ]);
        $path = "application-documents/{$application->id}/grades.pdf";
        Storage::disk('local')->put($path, 'existing grades');
        $document = ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'Latest report card or grades',
            'original_name' => 'grades.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 15,
            'status' => 'accepted',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($applicant)
            ->post("/dashboard/applications/{$application->id}/documents", [
                'document_name' => $document->document_name,
                'document_file' => UploadedFile::fake()->create('replacement.pdf', 20, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_file');

        $this->actingAs($applicant)
            ->deleteJson("/dashboard/documents/{$document->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_file');

        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('application_documents', ['id' => $document->id]);
    }

    public function test_admin_cannot_change_an_account_role_and_email_changes_require_reverification(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create();
        $profile = $applicant->studentProfile;
        $payload = [
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'middle_initial' => $profile->middle_initial,
            'email' => $applicant->email,
            'username' => $applicant->username,
            'contact_number' => $profile->contact_number,
        ];

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}", [...$payload, 'role' => 'provider'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Account roles cannot be changed after registration. Create a separate account for a different portal role.');

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}", [
                ...$payload,
                'role' => 'applicant',
                'email' => 'updated-applicant@example.test',
            ])
            ->assertOk();

        $this->assertNull($applicant->fresh()->email_verified_at);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'email_verification',
        ]);
    }

    public function test_submission_eligibility_snapshot_is_not_overwritten_by_later_profile_changes(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = $this->completeApplicant();
        $scholarship = $this->scholarship($provider, ['eligible_education_levels' => 'college']);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $service = app(DecisionSupportService::class);
        $service->syncApplication($application, 'submission');
        $submittedEligibility = $application->fresh()->eligibility_breakdown;

        $applicant->studentProfile()->update(['education_level' => 'elementary']);
        $service->syncApplication($application->fresh(), 'profile_changed');

        $this->assertSame($submittedEligibility, $application->fresh()->eligibility_breakdown);
        $this->assertSame(2, $application->dssSnapshots()->count());
        $this->assertNotSame(
            $application->dssSnapshots()->orderBy('id')->first()->eligibility_breakdown,
            $application->dssSnapshots()->orderByDesc('id')->first()->eligibility_breakdown,
        );
    }

    private function completeApplicant(): User
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01',
            'education_level' => 'college',
            'school' => 'Test University',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
        ]);

        return $applicant->fresh(['studentProfile']);
    }

    private function scholarship(User $provider, array $attributes = []): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Integrity Test Scholarship',
            'category' => 'Financial assistance',
            'description' => 'A complete scholarship used to verify platform integrity safeguards.',
            'eligibility' => 'Open to enrolled college applicants who submit the listed records.',
            'eligible_education_levels' => 'college',
            'location_name' => 'Test Scholarship Office',
            'location_address' => 'Quezon City, Metro Manila',
            'latitude' => 14.6760,
            'longitude' => 121.0437,
            'requirements' => 'Latest report card or grades',
            'review_rubric' => ReviewRubric::DEFAULT,
            'award_amount' => 10000,
            'application_mode' => 'online',
            'contact_email' => 'integrity@example.test',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
            'provider_terms_accepted_at' => now(),
            'provider_terms_version' => Terms::VERSION,
            ...$attributes,
        ]);
    }

    private function providerProof(User $provider, string $status = 'submitted'): ProviderVerificationDocument
    {
        return ProviderVerificationDocument::create([
            'provider_id' => $provider->id,
            'uploaded_by' => $provider->id,
            'document_type' => 'organization_registration',
            'original_name' => 'registration.pdf',
            'path' => "provider-verification/{$provider->id}/registration.pdf",
            'mime_type' => 'application/pdf',
            'size' => 100,
            'status' => $status,
            'uploaded_at' => now(),
        ]);
    }
}
