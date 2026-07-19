<?php

namespace Tests\Feature;

use App\Models\ProviderAssessment;
use App\Models\Scholarship;
use App\Models\User;
use App\Services\ScholarshipEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramCatalogSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_catalog_contains_compact_community_provider_demo_data(): void
    {
        $this->seed();
        $this->seed();

        $users = User::query()->with(['studentProfile', 'providerProfile'])->get();
        $programs = Scholarship::query()->orderBy('title')->get();

        $this->assertCount(4, $users);
        $this->assertSame(1, $users->where('role', 'admin')->count());
        $this->assertSame(2, $users->where('role', 'provider')->count());
        $this->assertSame(1, $users->where('role', 'applicant')->count());
        $this->assertCount(4, $programs);
        $this->assertCount(1, $programs->pluck('image_path')->filter()->unique());

        foreach ($programs as $program) {
            $this->assertNotNull($program->image_path);
            $this->assertFileExists(public_path(ltrim($program->image_path, '/')));
        }

        $tulayAral = User::query()
            ->where('email', 'tulayaral@scholarship.test')
            ->firstOrFail();
        $bukasKinabukasan = User::query()
            ->where('email', 'bukasfoundation@scholarship.test')
            ->firstOrFail();
        $stemProgram = Scholarship::query()
            ->where('provider_id', $bukasKinabukasan->id)
            ->where('title', 'Bukas Kinabukasan STEM Pathways Grant')
            ->firstOrFail();
        $student = $users->firstWhere('email', 'student@scholarship.test');

        $this->assertSame('approved', $tulayAral->providerProfile?->verification_status);
        $this->assertSame('approved', $bukasKinabukasan->providerProfile?->verification_status);
        $this->assertNotNull($student);
        $this->assertTrue($student->hasCompleteApplicantProfile());
        $this->assertSame('approved', $student->studentProfile?->verification_status);
        $this->assertTrue($programs->every(
            fn (Scholarship $program): bool => $program->image_path === '/uploads/scholarship-default.jpg'
        ));
        $this->assertSame('Bukas Kinabukasan Learning Hub', $stemProgram->location_name);
        $this->assertSame('85.00', $stemProgram->minimum_gwa);
        $this->assertSame('STEM', $stemProgram->eligible_courses);
        $this->assertTrue(app(ScholarshipEligibilityService::class)
            ->evaluate($stemProgram, $student)['is_eligible']);

        $programsByProvider = $programs->groupBy('provider_id');
        $this->assertCount(2, $programsByProvider);
        $this->assertTrue($programsByProvider->every(fn ($providerPrograms): bool => $providerPrograms->isNotEmpty()));

        $assessments = ProviderAssessment::query()->with('provider')->get();

        $this->assertCount(2, $assessments);
        $this->assertTrue($assessments->every(
            fn (ProviderAssessment $assessment): bool => $assessment->image_path === '/uploads/scholarship-default.jpg'
        ));
    }
}
