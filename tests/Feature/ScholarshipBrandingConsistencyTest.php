<?php

namespace Tests\Feature;

use App\Models\ApplicationStageSession;
use App\Models\MobileApiToken;
use App\Models\Scholarship;
use App\Models\User;
use App\Support\CoverImageCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScholarshipBrandingConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_cover_uses_a_system_photo_never_the_scholarship_logo(): void
    {
        [, $program] = $this->brandedProgram();
        $program->update(['cover_image_key' => null, 'cover_image_path' => null]);

        $this->assertSame(CoverImageCatalog::urlForKey(CoverImageCatalog::defaultScholarshipKey($program->category)), $program->coverImageUrl());
        $this->assertNotSame($program->logoUrl(), $program->coverImageUrl());

        $program->update(['cover_image_path' => 'uploads/scholarships/custom-cover.jpg']);
        $this->assertSame(asset('uploads/scholarships/custom-cover.jpg'), $program->coverImageUrl());
        $this->assertSame(asset('uploads/scholarships/independent-logo.png'), $program->logoUrl());
    }

    public function test_scholarship_details_and_previews_keep_program_provider_and_cover_images_separate(): void
    {
        [$provider, $program] = $this->brandedProgram();
        $applicant = User::factory()->create();

        foreach (['summary', 'eligibility', 'award', 'application', 'provider'] as $section) {
            $this->actingAs($applicant)
                ->getJson("/dashboard/scholarships/{$program->id}/data?section={$section}")
                ->assertOk()
                ->assertJsonPath('detail.identity.logo_url', $program->logoUrl())
                ->assertJsonPath('detail.identity.cover_image_url', $program->coverImageUrl())
                ->assertJsonPath('detail.identity.image_url', $program->coverImageUrl())
                ->assertJsonPath('detail.identity.provider.logo_url', '/uploads/providers/organization-logo.png');

            $this->actingAs($provider)
                ->getJson("/provider/previews/programs/{$program->id}/data?section={$section}")
                ->assertOk()
                ->assertJsonPath('detail.identity.logo_url', $program->logoUrl())
                ->assertJsonPath('detail.identity.cover_image_url', $program->coverImageUrl());
        }
    }

    public function test_public_provider_programs_and_organization_preview_return_independent_program_assets(): void
    {
        [$provider, $program] = $this->brandedProgram();
        $this->actingAs(User::factory()->create())
            ->getJson("/dashboard/providers/{$provider->id}/data")
            ->assertOk()
            ->assertJsonPath('provider.programs.0.logo_url', $program->logoUrl())
            ->assertJsonPath('provider.programs.0.cover_image_url', $program->coverImageUrl());

        $this->actingAs($provider)
            ->getJson('/provider/previews/organization/data')
            ->assertOk()
            ->assertJsonPath('provider.programs.0.logo_url', $program->logoUrl())
            ->assertJsonPath('provider.programs.0.cover_image_url', $program->coverImageUrl());
    }

    public function test_shared_provider_and_admin_program_payloads_use_logos_for_thumbnails(): void
    {
        [$provider, $program] = $this->brandedProgram();
        $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$program->id}")
            ->assertOk()
            ->assertJsonPath('scholarship.image_url', $program->logoUrl())
            ->assertJsonPath('scholarship.logo_url', $program->logoUrl())
            ->assertJsonPath('scholarship.cover_image_url', $program->coverImageUrl());

        $primary = User::factory()->create(['role' => 'admin']);
        $reviewer = User::factory()->create(['role' => 'admin', 'parent_account_id' => $primary->id, 'permissions' => ['manage_reviews']]);
        $this->actingAs($reviewer)
            ->getJson("/admin/scholarships/{$program->id}/review/data")
            ->assertOk()
            ->assertJsonPath('scholarship.image_url', $program->logoUrl())
            ->assertJsonPath('scholarship.cover_image_url', $program->coverImageUrl());

        $this->getJson('/admin/workspaces/reviews/programs/data?status=all')
            ->assertOk()
            ->assertJsonPath('items.0.image_url', $program->logoUrl())
            ->assertJsonPath('items.0.cover_image_url', $program->coverImageUrl());

        ApplicationStageSession::create([
            'scholarship_id' => $program->id,
            'stage_key' => 'exam',
            'title' => 'Branding check session',
            'mode' => 'onsite',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'status' => 'scheduled',
            'created_by' => $provider->id,
        ]);
        $this->getJson('/admin/workspaces/reviews/selection-activities/data?status=all')
            ->assertOk()
            ->assertJsonPath('items.0.image_url', $program->logoUrl())
            ->assertJsonPath('items.0.cover_image_url', $program->coverImageUrl());
    }

    public function test_mobile_scholarship_payload_exposes_logo_and_cover_independently(): void
    {
        [, $program] = $this->brandedProgram();
        $applicant = User::factory()->create();
        MobileApiToken::create([
            'user_id' => $applicant->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', 'branding-test-token'),
            'expires_at' => now()->addDay(),
        ]);
        $this->withToken('branding-test-token')->getJson('/api/mobile/profile')
            ->assertOk()
            ->assertJsonPath('scholarships.0.image_url', $program->logoUrl())
            ->assertJsonPath('scholarships.0.logo_url', $program->logoUrl())
            ->assertJsonPath('scholarships.0.cover_image_url', $program->coverImageUrl());
    }

    private function brandedProgram(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'provider_name' => 'Independent Organization',
            'logo_path' => 'uploads/providers/organization-logo.png',
        ]);
        $program = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Independent Program Branding',
            'category' => 'Financial assistance',
            'description' => 'Separate program logo, organization logo and cover photo.',
            'status' => 'published',
            'deadline' => now()->addMonth()->toDateString(),
            'image_path' => 'uploads/scholarships/independent-logo.png',
            'cover_image_source' => 'system',
            'cover_image_key' => 'science-project',
        ]);

        return [$provider, $program];
    }
}
