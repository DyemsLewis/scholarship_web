<?php

namespace Tests\Feature;

use App\Models\ProviderAssessment;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramCatalogSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_programs_use_provider_logos_and_include_ched_merit(): void
    {
        $this->seed();
        $this->seed();

        $programs = Scholarship::query()->orderBy('title')->get();

        $this->assertCount(4, $programs);
        $this->assertCount(2, $programs->pluck('image_path')->filter()->unique());

        foreach ($programs as $program) {
            $this->assertNotNull($program->image_path);
            $this->assertFileExists(public_path(ltrim($program->image_path, '/')));
        }

        $chedProvider = User::query()
            ->where('email', 'ched.provider@scholarship.test')
            ->firstOrFail();
        $chedProgram = Scholarship::query()
            ->where('provider_id', $chedProvider->id)
            ->where('title', 'CHED Merit Scholarship Program (CMSP)')
            ->firstOrFail();

        $this->assertSame('approved', $chedProvider->providerProfile?->verification_status);
        $dostPrograms = $programs->filter(fn (Scholarship $program): bool => str_starts_with($program->title, 'DOST-SEI'));

        $this->assertCount(3, $dostPrograms);
        $this->assertTrue($dostPrograms->every(
            fn (Scholarship $program): bool => $program->image_path === '/images/programs/dost-logo-card.jpg'
        ));
        $this->assertSame('/images/programs/ched-logo-card.jpg', $chedProgram->image_path);
        $this->assertSame('CHED Central Office', $chedProgram->location_name);
        $this->assertSame('93.00', $chedProgram->minimum_gwa);
        $this->assertStringContainsString('PHP 500,000', $chedProgram->income_requirement);

        $assessments = ProviderAssessment::query()->with('provider')->get();

        $this->assertCount(2, $assessments);
        $this->assertSame('/images/programs/dost-logo-card.jpg', $assessments->firstWhere('provider_id', $dostPrograms->first()->provider_id)?->image_path);
        $this->assertSame('/images/programs/ched-logo-card.jpg', $assessments->firstWhere('provider_id', $chedProvider->id)?->image_path);
    }
}
