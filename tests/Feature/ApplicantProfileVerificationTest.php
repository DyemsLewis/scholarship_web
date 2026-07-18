<?php

namespace Tests\Feature;

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

    public function test_admin_verification_is_visible_to_provider_without_exposing_proof_files(): void
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
