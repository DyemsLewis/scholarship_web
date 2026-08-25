<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_contacts_are_separate_from_the_representative_account_and_prefill_programs(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'email' => 'representative@example.test',
            'username' => 'provider-representative',
        ]);
        $profile = $provider->providerProfile;
        $profile->update([
            'provider_name' => 'Community Learning Foundation',
            'provider_type' => 'foundation',
            'provider_website' => 'https://community-learning.example.test',
            'provider_address' => 'Quezon City, Metro Manila',
            'provider_description' => 'Local education support provider.',
            'provider_contact_email' => 'old-contact@community-learning.example.test',
            'provider_contact_number' => '09178880000',
            'verification_status' => 'approved',
        ]);

        $this->actingAs($provider)
            ->patchJson('/provider/profile', [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'middle_initial' => $profile->middle_initial,
                'email' => 'representative@example.test',
                'username' => 'provider-representative',
                'contact_number' => '09170000001',
                'provider_name' => 'Community Learning Foundation',
                'provider_type' => 'foundation',
                'provider_website' => 'https://community-learning.example.test',
                'provider_address' => 'Quezon City, Metro Manila',
                'provider_description' => 'Local education support provider.',
                'provider_contact_email' => 'scholarships@community-learning.example.test',
                'provider_contact_number' => '09179990000',
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'representative@example.test')
            ->assertJsonPath('user.contact_number', '09170000001')
            ->assertJsonPath('user.provider_contact_email', 'scholarships@community-learning.example.test')
            ->assertJsonPath('user.provider_contact_number', '09179990000')
            ->assertJsonPath('verification_reset', false);

        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => $provider->id,
            'contact_number' => '09170000001',
            'provider_contact_email' => 'scholarships@community-learning.example.test',
            'provider_contact_number' => '09179990000',
            'verification_status' => 'approved',
        ]);

        $response = $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Community Support Draft',
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.contact_email', 'scholarships@community-learning.example.test')
            ->assertJsonPath('scholarship.contact_number', '09179990000');

        $this->assertDatabaseHas('scholarships', [
            'id' => $response->json('scholarship.id'),
            'contact_email' => 'scholarships@community-learning.example.test',
            'contact_number' => '09179990000',
        ]);
    }
}
