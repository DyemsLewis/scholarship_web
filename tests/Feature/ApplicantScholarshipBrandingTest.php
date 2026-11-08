<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantScholarshipBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_scholarship_catalog_returns_logo_separately_from_the_cover(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'logo_path' => 'uploads/providers/provider-logo.png',
        ]);
        $program = $this->program($provider, 'uploads/scholarships/program-logo.png');

        $item = collect($this->actingAs(User::factory()->create())
            ->getJson('/dashboard/scholarships/data')
            ->assertOk()
            ->json('scholarships'))->firstWhere('id', $program->id);

        $this->assertNotNull($item);
        $this->assertSame($program->logoUrl(), $item['logo_url']);
        $this->assertSame($program->coverImageUrl(), $item['cover_image_url']);
        $this->assertNotSame($item['cover_image_url'], $item['logo_url']);
        $this->assertNotSame($item['provider']['logo_url'], $item['logo_url']);
        $this->assertSame($item['logo_url'], $item['image_url']);
    }

    public function test_missing_program_logo_uses_the_default_not_a_background_or_provider_logo(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'logo_path' => 'uploads/providers/provider-logo.png',
        ]);
        $program = $this->program($provider, null);

        $item = collect($this->actingAs(User::factory()->create())
            ->getJson('/dashboard/scholarships/data')
            ->assertOk()
            ->json('scholarships'))->firstWhere('id', $program->id);

        $this->assertNotNull($item);
        $this->assertSame(asset('uploads/scholarship-default.jpg'), $item['logo_url']);
        $this->assertSame($program->coverImageUrl(), $item['cover_image_url']);
        $this->assertNotSame($item['cover_image_url'], $item['logo_url']);
        $this->assertSame($item['logo_url'], $item['image_url']);
    }

    public function test_application_lists_and_all_detail_sections_use_logos_while_preserving_covers(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'logo_path' => 'uploads/providers/provider-logo.png',
        ]);
        $applicant = User::factory()->create();

        foreach (['uploads/scholarships/program-logo.png', null] as $logo) {
            $program = $this->program($provider, $logo);
            $application = ScholarshipApplication::create([
                'scholarship_id' => $program->id,
                'applicant_id' => $applicant->id,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
            $item = collect($this->actingAs($applicant)
                ->getJson('/dashboard/applications/data')
                ->assertOk()
                ->json('applications'))->firstWhere('id', $application->id);

            $this->assertNotNull($item);
            $this->assertSame($program->logoUrl(), $item['scholarship']['image_url']);
            $this->assertSame($program->coverImageUrl(), $item['scholarship']['cover_image_url']);
            $this->assertNotSame($item['scholarship']['cover_image_url'], $item['scholarship']['image_url']);

            foreach (['summary', 'submission', 'activities', 'outcome', 'history'] as $view) {
                $this->actingAs($applicant)
                    ->getJson(route('dashboard.applications.show.data', $application).'?view='.$view)
                    ->assertOk()
                    ->assertJsonPath('detail.identity.program.image_url', $program->logoUrl())
                    ->assertJsonPath('detail.identity.program.logo_url', $program->logoUrl())
                    ->assertJsonPath('detail.identity.program.cover_image_url', $program->coverImageUrl())
                    ->assertJsonPath('application.scholarship.image_url', $program->logoUrl())
                    ->assertJsonPath('application.scholarship.cover_image_url', $program->coverImageUrl());
            }
        }
    }

    private function program(User $provider, ?string $logo): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Separate scholarship branding',
            'description' => 'A published scholarship with independent branding assets.',
            'category' => 'Financial assistance',
            'status' => 'published',
            'deadline' => now()->addMonth()->toDateString(),
            'image_path' => $logo,
            'cover_image_source' => 'system',
            'cover_image_key' => 'science-project',
        ]);
    }
}
