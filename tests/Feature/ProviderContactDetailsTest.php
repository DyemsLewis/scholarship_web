<?php

namespace Tests\Feature;

use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_and_representative_details_can_be_updated_independently(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'email' => 'owner@example.test',
            'username' => 'provider-owner',
        ]);
        $profile = $provider->providerProfile;
        $originalRepresentativeName = $profile->first_name;

        $this->actingAs($provider)
            ->patchJson('/provider/profile', [
                'profile_section' => 'organization',
                'provider_name' => 'Separate Provider Foundation',
                'provider_type' => 'foundation',
                'provider_website' => 'https://separate-provider.example.test',
                'provider_address' => 'Pasig City, Metro Manila',
                'provider_description' => 'Provider-facing organization details.',
                'provider_mission' => 'Help learners continue their education.',
                'provider_year_established' => 2012,
                'provider_service_area' => 'Metro Manila and Rizal',
                'provider_contact_email' => 'scholarships@separate-provider.example.test',
                'provider_contact_number' => '09175550111',
                'provider_contact_department' => 'Scholarship Office',
                'provider_office_hours' => 'Monday to Friday, 8:00 AM to 5:00 PM',
                'legal_name' => 'Separate Provider Foundation, Inc.',
                'registration_authority' => 'SEC',
                'registration_number' => 'SEC-2012-001',
                'registration_date' => '2012-06-15',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Provider profile updated and returned for admin verification.');

        $this->assertSame('owner@example.test', $provider->fresh()->email);
        $this->assertSame($originalRepresentativeName, $profile->fresh()->first_name);

        $this->actingAs($provider->fresh())
            ->patchJson('/provider/profile', [
                'profile_section' => 'representative',
                'first_name' => 'Updated',
                'last_name' => 'Representative',
                'middle_initial' => 'R',
                'email' => 'owner@example.test',
                'username' => 'updated-provider-owner',
                'contact_number' => '09175550222',
                'representative_position' => 'Scholarship Director',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Representative details updated successfully.');

        $updatedProfile = $profile->fresh();
        $this->assertSame('Updated', $updatedProfile->first_name);
        $this->assertSame('Separate Provider Foundation', $updatedProfile->provider_name);
        $this->assertSame('Help learners continue their education.', $updatedProfile->mission);
        $this->assertSame('SEC-2012-001', $updatedProfile->registration_number);
        $this->assertSame('Scholarship Director', $updatedProfile->representative_position);
        $this->assertSame('scholarships@separate-provider.example.test', $updatedProfile->provider_contact_email);
    }

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

    public function test_profile_activity_is_derived_from_provider_records(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Provider Activity Scholarship',
            'description' => 'A program used to verify provider profile activity.',
            'status' => 'published',
            'deadline' => now()->addMonth()->toDateString(),
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'final_outcome' => 'selected',
            'submitted_at' => now(),
        ]);
        $release = RecipientBenefitRelease::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'title' => 'Learning allowance',
            'release_at' => now(),
            'benefit_description' => 'Initial learning allowance released to the recipient.',
            'status' => 'completed',
        ]);
        RecipientBenefitReleaseRecord::create([
            'recipient_benefit_release_id' => $release->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'status' => 'released',
            'recorded_by' => $provider->id,
            'recorded_at' => now(),
            'released_at' => now(),
        ]);

        $this->actingAs($provider)
            ->getJson('/provider/profile/data')
            ->assertOk()
            ->assertJsonPath('user.activity_summary.programs', 1)
            ->assertJsonPath('user.activity_summary.published_programs', 1)
            ->assertJsonPath('user.activity_summary.applications', 1)
            ->assertJsonPath('user.activity_summary.selected_recipients', 1)
            ->assertJsonPath('user.activity_summary.benefits_released', 1);
    }

    public function test_public_provider_details_are_visible_to_applicants_but_legal_record_is_admin_only(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'provider_name' => 'Trusted Learning Foundation',
            'provider_description' => 'A local education foundation.',
            'mission' => 'Make education support easier to reach.',
            'year_established' => 2015,
            'service_area' => 'Rizal Province',
            'contact_department' => 'Scholarship Desk',
            'office_hours' => 'Weekdays, 9:00 AM to 4:00 PM',
            'legal_name' => 'Trusted Learning Foundation, Inc.',
            'registration_authority' => 'SEC',
            'registration_number' => 'SEC-2015-009',
            'registration_date' => '2015-04-10',
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Public Provider Details Scholarship',
            'description' => 'A scholarship with provider organization details.',
            'status' => 'published',
            'deadline' => now()->addMonth()->toDateString(),
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $admin = User::factory()->create(['role' => 'admin']);

        $applicantResponse = $this->actingAs($applicant)
            ->getJson("/dashboard/scholarships/{$scholarship->id}/data")
            ->assertOk()
            ->assertJsonPath('scholarship.provider.mission', 'Make education support easier to reach.')
            ->assertJsonPath('scholarship.provider.year_established', 2015)
            ->assertJsonPath('scholarship.provider.service_area', 'Rizal Province')
            ->assertJsonPath('scholarship.provider.contact_department', 'Scholarship Desk');

        $this->assertArrayNotHasKey('registration_number', $applicantResponse->json('scholarship.provider'));
        $this->assertArrayNotHasKey('legal_name', $applicantResponse->json('scholarship.provider'));

        $this->actingAs($admin)
            ->getJson("/admin/providers/{$provider->id}/review/data")
            ->assertOk()
            ->assertJsonPath('provider.legal_name', 'Trusted Learning Foundation, Inc.')
            ->assertJsonPath('provider.registration_authority', 'SEC')
            ->assertJsonPath('provider.registration_number', 'SEC-2015-009')
            ->assertJsonPath('provider.registration_date', 'Apr 10, 2015');
    }
}
