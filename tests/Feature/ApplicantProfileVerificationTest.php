<?php

namespace Tests\Feature;

use App\Models\ApplicantVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantProfileVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_can_submit_private_profile_proof_for_admin_review(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $response = $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'school_id',
                'document_file' => UploadedFile::fake()->image('school-id.jpg'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('user.applicant_verification_status', 'pending')
            ->assertJsonCount(1, 'verification_documents');

        $documentId = $response->json('verification_documents.0.id');

        $this->actingAs($admin)
            ->getJson("/admin/users/{$applicant->id}")
            ->assertOk()
            ->assertJsonPath('verification_documents.0.id', $documentId);

        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->get("/dashboard/profile/verification-documents/{$documentId}/view")
            ->assertForbidden();
    }

    public function test_admin_verification_is_visible_in_provider_list_without_exposing_proof_files(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/verification-documents', [
                'document_type' => 'enrollment_certificate',
                'document_file' => UploadedFile::fake()->create('enrollment.pdf', 120, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
                'verification_notes' => 'A stale rejection note should not remain after approval.',
            ])
            ->assertOk()
            ->assertJsonPath('user.is_profile_verified', true)
            ->assertJsonPath('user.applicant_verification_notes', null);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Verified Applicant Scholarship',
            'category' => 'Academic merit',
            'description' => 'Test scholarship for applicant verification visibility.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);

        ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($provider)
            ->getJson('/provider/applications/data')
            ->assertOk()
            ->assertJsonPath('applications.0.applicant.profile_verification_status', 'approved');

        $this->assertArrayNotHasKey('verification_documents', $response->json('applications.0.applicant'));
        $this->assertArrayNotHasKey('profile_proofs', $response->json('applications.0.applicant'));
    }

    public function test_provider_can_review_profile_proof_only_through_an_owned_matching_application(): void
    {
        Storage::fake('local');

        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $otherApplicant = User::factory()->create(['role' => 'applicant']);

        $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], [
            'first_name' => 'Demo',
            'last_name' => 'Learner',
            'contact_number' => '09171234567',
            'birthdate' => '2008-04-15',
            'gender' => 'female',
            'account_managed_by' => 'guardian',
            'education_level' => 'senior_high_school',
            'school' => 'Demo National High School',
            'school_type' => 'public',
            'course_or_strand' => 'STEM',
            'year_level' => 'Grade 12',
            'enrollment_status' => 'enrolled',
            'income_bracket' => 'Below PHP 250,000',
            'household_size' => 5,
            'city' => 'Manila',
            'province' => 'Metro Manila',
            'guardian_name' => 'Demo Guardian',
            'guardian_relationship' => 'Parent',
            'guardian_contact' => '09179876543',
            'guardian_email' => 'guardian@example.com',
            'guardian_is_account_owner' => true,
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);

        $proofPath = "applicant-verification/{$applicant->id}/school-id.pdf";
        Storage::disk('local')->put($proofPath, 'demo profile proof');
        $proof = ApplicantVerificationDocument::create([
            'applicant_id' => $applicant->id,
            'uploaded_by' => $applicant->id,
            'document_type' => 'school_id',
            'original_name' => 'school-id.pdf',
            'path' => $proofPath,
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'approved',
            'uploaded_at' => now(),
        ]);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Profile Review Scholarship',
            'category' => 'Academic merit',
            'description' => 'Tests application-scoped applicant profile review.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $otherApplication = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $otherApplicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $viewUrl = route('provider.applications.profile-proofs.view', [$application, $proof]);

        $this->actingAs($provider)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.applicant.guardian_name', 'Demo Guardian')
            ->assertJsonPath('application.applicant.enrollment_status', 'enrolled')
            ->assertJsonPath('application.applicant.profile_proofs.0.id', $proof->id)
            ->assertJsonPath('application.applicant.profile_proofs.0.view_url', $viewUrl);

        $this->actingAs($provider)
            ->get($viewUrl)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($otherProvider)
            ->get($viewUrl)
            ->assertForbidden();

        $this->actingAs($provider)
            ->get(route('provider.applications.profile-proofs.view', [$otherApplication, $proof]))
            ->assertForbidden();
    }

    public function test_admin_cannot_verify_applicant_without_a_submitted_proof(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$applicant->id}/profile-verification", [
                'verification_status' => 'approved',
            ])
            ->assertUnprocessable();
    }
}
