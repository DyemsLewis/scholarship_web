<?php

namespace Tests\Feature;

use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicantProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_can_store_replace_view_and_remove_a_private_square_photo(): void
    {
        Storage::fake('local');
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->assertContains(
            'has_profile_photo',
            collect($applicant->applicantProfileReadiness()['missing'])->pluck('key'),
        );

        $this->actingAs($applicant)
            ->withHeader('Accept', 'application/json')
            ->post('/dashboard/profile/photo', [
                'profile_photo' => UploadedFile::fake()->image('wide-photo.jpg', 600, 400),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_photo');

        $response = $this->actingAs($applicant)
            ->post('/dashboard/profile/photo', [
                'profile_photo' => UploadedFile::fake()->image('applicant-photo.jpg', 600, 600),
            ])
            ->assertOk()
            ->assertJsonPath('user.has_profile_photo', true);

        $profile = $applicant->fresh()->studentProfile;
        Storage::disk('local')->assertExists($profile->profile_photo_path);
        $this->assertNotContains(
            'has_profile_photo',
            collect($applicant->fresh()->applicantProfileReadiness()['missing'])->pluck('key'),
        );

        $this->actingAs($applicant)
            ->get(route('dashboard.profile.photo'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $oldPath = $profile->profile_photo_path;
        $this->actingAs($applicant)
            ->post('/dashboard/profile/photo', [
                'profile_photo' => UploadedFile::fake()->image('replacement.png', 800, 800),
            ])
            ->assertOk();

        Storage::disk('local')->assertMissing($oldPath);
        $replacementPath = $applicant->fresh()->studentProfile->profile_photo_path;
        Storage::disk('local')->assertExists($replacementPath);

        $this->actingAs($applicant)
            ->deleteJson('/dashboard/profile/photo')
            ->assertOk()
            ->assertJsonPath('user.has_profile_photo', false);

        Storage::disk('local')->assertMissing($replacementPath);
        $this->actingAs($applicant)->get('/dashboard/profile/photo')->assertNotFound();
        $this->assertContains(
            'has_profile_photo',
            collect($applicant->fresh()->applicantProfileReadiness()['missing'])->pluck('key'),
        );
    }

    public function test_applicant_can_upload_the_required_photo_from_mobile(): void
    {
        Storage::fake('local');
        $applicant = User::factory()->create(['role' => 'applicant']);
        $plainToken = 'mobile-photo-token';
        MobileApiToken::create([
            'user_id' => $applicant->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->withToken($plainToken)
            ->post('/api/mobile/profile/photo', [
                'profile_photo' => UploadedFile::fake()->image('mobile-applicant.jpg', 600, 600),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('user.has_profile_photo', true);

        $this->assertNotContains(
            'has_profile_photo',
            collect($response->json('profile_readiness.missing'))->pluck('key'),
        );

        Storage::disk('local')->assertExists($applicant->fresh()->studentProfile->profile_photo_path);
    }

    public function test_only_the_provider_that_received_the_application_can_view_the_photo(): void
    {
        Storage::fake('local');
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($applicant)
            ->post('/dashboard/profile/photo', [
                'profile_photo' => UploadedFile::fake()->image('applicant-photo.jpg', 600, 600),
            ])
            ->assertOk();

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Private Photo Review Scholarship',
            'category' => 'Financial assistance',
            'description' => 'Tests application-scoped access to an applicant photo.',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $photoUrl = route('provider.applications.profile-photo.view', $application);

        $this->actingAs($provider)
            ->getJson('/provider/applications/data')
            ->assertOk()
            ->assertJsonPath('applications.0.applicant.profile_photo_url', $photoUrl);

        $this->actingAs($provider)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.applicant.profile_photo_url', $photoUrl);

        $this->actingAs($provider)->get($photoUrl)->assertOk();
        $this->actingAs($otherProvider)->get($photoUrl)->assertForbidden();
    }
}
