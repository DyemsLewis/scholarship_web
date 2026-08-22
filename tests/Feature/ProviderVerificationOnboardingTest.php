<?php

namespace Tests\Feature;

use App\Models\ProviderVerificationDocument;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
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

    public function test_removing_approved_provider_proof_pauses_publishing_until_another_review(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);
        $path = "provider-verification/{$provider->id}/registration.pdf";
        Storage::disk('local')->put($path, 'organization registration');
        $document = ProviderVerificationDocument::create([
            'provider_id' => $provider->id,
            'uploaded_by' => $provider->id,
            'document_type' => 'organization_registration',
            'original_name' => 'registration.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 25,
            'status' => 'submitted',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($provider)
            ->deleteJson("/provider/verification-documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('returned_to_review', true)
            ->assertJsonPath('user.verification_status', 'pending')
            ->assertJsonPath('user.can_post_scholarships', false);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('provider_verification_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => $provider->id,
            'verification_status' => 'pending',
            'verified_by' => null,
            'verified_at' => null,
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $admin->id,
            'type' => 'provider_verification_document',
            'title' => 'Provider proof changed',
        ]);
    }

    public function test_changing_provider_email_requires_verification_of_the_new_address(): void
    {
        Notification::fake();

        $provider = User::factory()->create(['role' => 'provider']);
        $profile = $provider->providerProfile;
        $profile->update(['verification_status' => 'approved']);

        $this->actingAs($provider)
            ->patchJson('/provider/profile', [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'middle_initial' => $profile->middle_initial,
                'email' => 'new-provider-email@example.test',
                'username' => $provider->username,
                'contact_number' => $profile->contact_number,
                'provider_name' => $profile->provider_name,
                'provider_type' => $profile->provider_type,
                'provider_website' => $profile->provider_website,
                'provider_address' => $profile->provider_address,
                'provider_description' => $profile->provider_description,
            ])
            ->assertOk()
            ->assertJsonPath('email_changed', true)
            ->assertJsonPath('user.email', 'new-provider-email@example.test')
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.can_post_scholarships', false);

        $this->assertDatabaseHas('users', [
            'id' => $provider->id,
            'email' => 'new-provider-email@example.test',
            'email_verified_at' => null,
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $provider->id,
            'type' => 'email_verification',
            'title' => 'Verify your email address',
        ]);
        Notification::assertSentTo($provider, VerifyEmail::class);
    }

    public function test_provider_proof_metadata_is_hidden_from_staff_without_profile_permission(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $staff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'permissions' => ['manage_programs'],
        ]);
        ProviderVerificationDocument::create([
            'provider_id' => $provider->id,
            'uploaded_by' => $provider->id,
            'document_type' => 'valid_id',
            'original_name' => 'private-owner-id.pdf',
            'path' => "provider-verification/{$provider->id}/private-owner-id.pdf",
            'mime_type' => 'application/pdf',
            'size' => 100,
            'status' => 'submitted',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($staff)
            ->getJson('/provider/profile/data')
            ->assertOk()
            ->assertJsonCount(0, 'verification_documents')
            ->assertJsonMissing(['original_name' => 'private-owner-id.pdf']);

        $this->actingAs($provider)
            ->getJson('/provider/profile/data')
            ->assertOk()
            ->assertJsonCount(1, 'verification_documents');
    }

    public function test_provider_verification_files_use_secure_inline_preview_routes(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $path = "provider-verification/{$provider->id}/registration.pdf";
        Storage::disk('local')->put($path, '%PDF-1.4 provider registration');
        $document = ProviderVerificationDocument::create([
            'provider_id' => $provider->id,
            'uploaded_by' => $provider->id,
            'document_type' => 'organization_registration',
            'original_name' => 'registration.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 31,
            'status' => 'submitted',
            'uploaded_at' => now(),
        ]);

        $providerViewUrl = route('provider.verification-documents.view', $document);
        $adminViewUrl = route('admin.provider-verification-documents.view', $document);

        $this->actingAs($provider)
            ->getJson('/provider/profile/data')
            ->assertOk()
            ->assertJsonPath('verification_documents.0.mime_type', 'application/pdf')
            ->assertJsonPath('verification_documents.0.view_url', $providerViewUrl);

        $this->actingAs($provider)
            ->get($providerViewUrl)
            ->assertOk();

        $this->actingAs($otherProvider)
            ->get($providerViewUrl)
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson("/admin/providers/{$provider->id}/review/data")
            ->assertOk()
            ->assertJsonPath('provider.verification_documents.0.mime_type', 'application/pdf')
            ->assertJsonPath('provider.verification_documents.0.view_url', $adminViewUrl);

        $this->actingAs($admin)
            ->get($adminViewUrl)
            ->assertOk();
    }
}
