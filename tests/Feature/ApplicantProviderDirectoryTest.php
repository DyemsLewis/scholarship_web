<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantProviderDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_can_browse_verified_providers_with_current_programs(): void
    {
        $applicant = User::factory()->create(['role' => 'applicant']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'provider_name' => 'Rizal Learning Foundation',
            'provider_type' => 'foundation',
            'mission' => 'Help local learners continue their education.',
            'provider_description' => 'A community foundation supporting students in Rizal.',
            'year_established' => 2014,
            'service_area' => 'Rizal Province',
            'contact_department' => 'Scholarship Desk',
            'office_hours' => 'Weekdays, 8:00 AM to 5:00 PM',
            'legal_name' => 'Rizal Learning Foundation, Inc.',
            'registration_number' => 'SEC-PRIVATE-001',
        ]);
        $program = $this->publishedScholarship($provider, 'Rizal Student Grant');

        $response = $this->actingAs($applicant)
            ->getJson('/dashboard/providers/data')
            ->assertOk()
            ->assertJsonCount(1, 'providers')
            ->assertJsonPath('summary.providers', 1)
            ->assertJsonPath('summary.programs', 1)
            ->assertJsonPath('providers.0.id', $provider->id)
            ->assertJsonPath('providers.0.name', 'Rizal Learning Foundation')
            ->assertJsonPath('providers.0.mission', 'Help local learners continue their education.')
            ->assertJsonPath('providers.0.service_area', 'Rizal Province')
            ->assertJsonPath('providers.0.programs_count', 1)
            ->assertJsonPath('providers.0.focus_areas.0', 'Academic merit');

        $providerPayload = $response->json('providers.0');
        $this->assertArrayNotHasKey('legal_name', $providerPayload);
        $this->assertArrayNotHasKey('registration_number', $providerPayload);
        $this->assertSame([], $providerPayload['programs']);

        $detail = $this->actingAs($applicant)
            ->getJson("/dashboard/providers/{$provider->id}/data")
            ->assertOk()
            ->assertJsonPath('provider.id', $provider->id)
            ->assertJsonPath('provider.programs.0.id', $program->id)
            ->assertJsonPath('provider.programs.0.provider.id', $provider->id);

        $this->assertArrayNotHasKey('legal_name', $detail->json('provider'));
        $this->assertArrayNotHasKey('registration_number', $detail->json('provider'));

        $this->actingAs($applicant)
            ->get("/dashboard/providers/{$provider->id}")
            ->assertOk();
    }

    public function test_directory_hides_unverified_providers_but_keeps_verified_provider_profiles_searchable(): void
    {
        $applicant = User::factory()->create(['role' => 'applicant']);
        $unverifiedProvider = User::factory()->create(['role' => 'provider']);
        $unverifiedProvider->providerProfile()->update(['verification_status' => 'pending']);
        $this->publishedScholarship($unverifiedProvider, 'Pending Provider Grant');

        $expiredProvider = User::factory()->create(['role' => 'provider']);
        $this->publishedScholarship($expiredProvider, 'Expired Provider Grant', [
            'deadline' => now()->subDay()->toDateString(),
        ]);

        $this->actingAs($applicant)
            ->getJson('/dashboard/providers/data')
            ->assertOk()
            ->assertJsonCount(1, 'providers')
            ->assertJsonPath('providers.0.id', $expiredProvider->id)
            ->assertJsonPath('providers.0.programs_count', 0)
            ->assertJsonPath('summary.providers', 1)
            ->assertJsonPath('summary.programs', 0);

        $this->actingAs($applicant)
            ->get("/dashboard/providers/{$unverifiedProvider->id}")
            ->assertNotFound();
    }

    public function test_provider_directory_requires_an_applicant_account(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->getJson('/dashboard/providers/data')
            ->assertForbidden();
    }

    private function publishedScholarship(User $provider, string $title, array $attributes = []): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => $title,
            'category' => 'Academic merit',
            'description' => 'A current scholarship available through this provider.',
            'eligibility' => 'Open to qualified enrolled learners.',
            'status' => 'published',
            'deadline' => now()->addMonth()->toDateString(),
            ...$attributes,
        ]);
    }
}
