<?php

namespace Tests\Feature;

use App\Models\ProviderVerificationDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProviderVerificationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_email_verified_and_admin_approved_providers_can_open_the_program_form(): void
    {
        $provider = User::factory()->unverified()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $verificationUrl = route('provider.profile').'#verification-documents';

        $this->actingAs($provider)
            ->get('/provider/programs/create')
            ->assertRedirect($verificationUrl);

        $provider->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($provider->fresh())
            ->get('/provider/programs/create')
            ->assertOk();
    }

    public function test_dashboard_exposes_verification_progress_and_uses_both_approval_requirements(): void
    {
        $provider = User::factory()->unverified()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        ProviderVerificationDocument::create([
            'provider_id' => $provider->id,
            'uploaded_by' => $provider->id,
            'document_type' => 'organization_registration',
            'original_name' => 'registration.pdf',
            'path' => "provider-verification/{$provider->id}/registration.pdf",
            'mime_type' => 'application/pdf',
            'size' => 100,
            'status' => 'submitted',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($provider)
            ->getJson('/provider/dashboard/data')
            ->assertOk()
            ->assertJsonPath('user.verification_documents_count', 1)
            ->assertJsonPath('user.can_post_scholarships', false);

        $provider->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($provider->fresh())
            ->getJson('/provider/dashboard/data')
            ->assertOk()
            ->assertJsonPath('user.can_post_scholarships', true);
    }

    public function test_replacement_proof_returns_a_rejected_provider_to_admin_review(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'rejected',
            'verification_notes' => 'Upload a clearer registration document.',
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        $response = $this->actingAs($provider)->post('/provider/verification-documents', [
            'document_type' => 'organization_registration',
            'document_file' => UploadedFile::fake()->create('registration.pdf', 100, 'application/pdf'),
            'terms_accepted' => '1',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.verification_status', 'pending')
            ->assertJsonPath('user.can_post_scholarships', false)
            ->assertJsonPath('message', 'Verification proof uploaded and returned for admin review.');

        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => $provider->id,
            'verification_status' => 'pending',
            'verified_by' => null,
        ]);
        $this->assertDatabaseHas('provider_verification_documents', [
            'provider_id' => $provider->id,
            'document_type' => 'organization_registration',
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $admin->id,
            'type' => 'provider_verification_document',
            'action_url' => '/admin/reviews',
        ]);
    }
}
