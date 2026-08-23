<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipEvent;
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
        $this->assertCount(2, $programs->pluck('image_path')->filter()->unique());

        foreach ($programs as $program) {
            $this->assertNotNull($program->image_path);
            $this->assertFileExists(public_path(ltrim($program->image_path, '/')));
            $this->assertSame('onsite', $program->handoff_mode);
            $this->assertNotNull($program->handoff_deadline);
            $this->assertStringContainsString('portal pre-screening', strtolower($program->handoff_instructions));
            $this->assertStringNotContainsString('birth certificate', strtolower($program->requirements ?? ''));
            $this->assertStringNotContainsString('government-issued id', strtolower($program->requirements ?? ''));
            $this->assertStringNotContainsString('parent or guardian valid id', strtolower($program->requirements ?? ''));
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
        $collegeProgram = Scholarship::query()
            ->where('provider_id', $tulayAral->id)
            ->where('title', 'Tulay Aral College Starter Grant')
            ->firstOrFail();
        $schoolEssentialsProgram = Scholarship::query()
            ->where('provider_id', $bukasKinabukasan->id)
            ->where('title', 'Bukas Kinabukasan School Essentials Grant')
            ->firstOrFail();
        $student = $users->firstWhere('email', 'student@scholarship.test');

        $this->assertSame('approved', $tulayAral->providerProfile?->verification_status);
        $this->assertSame('approved', $bukasKinabukasan->providerProfile?->verification_status);
        $this->assertNotNull($student);
        $this->assertTrue($student->hasCompleteApplicantProfile());
        $this->assertSame('approved', $student->studentProfile?->verification_status);
        $this->assertTrue($programs->where('provider_id', $tulayAral->id)->every(
            fn (Scholarship $program): bool => $program->image_path === '/images/programs/tulay-aral-logo.png'
        ));
        $this->assertTrue($programs->where('provider_id', $bukasKinabukasan->id)->every(
            fn (Scholarship $program): bool => $program->image_path === '/images/programs/bukas-kinabukasan-logo.png'
        ));
        $this->assertSame('Bukas Kinabukasan Learning Hub', $stemProgram->location_name);
        $this->assertSame('onsite', $stemProgram->application_mode);
        $this->assertSame('onsite', $collegeProgram->application_mode);
        $this->assertSame('provider_review', $schoolEssentialsProgram->application_mode);
        $this->assertSame('85.00', $stemProgram->minimum_gwa);
        $this->assertSame('STEM', $stemProgram->eligible_courses);
        $this->assertSame(['screening', 'interview', 'distribution'], $collegeProgram->selection_stages);
        $this->assertSame(['screening', 'distribution'], $schoolEssentialsProgram->selection_stages);
        $this->assertSame(['screening', 'exam', 'interview', 'distribution'], $stemProgram->selection_stages);
        $this->assertTrue($programs->every(fn (Scholarship $program): bool => $program->benefits()->exists()));
        $this->assertNull($schoolEssentialsProgram->award_amount);
        $this->assertSame(
            ['school_supplies', 'device_support'],
            $schoolEssentialsProgram->benefits()->orderBy('sort_order')->pluck('type')->all(),
        );
        $this->assertSame(3, $stemProgram->benefits()->count());
        $this->assertStringContainsString('STEM learning grant', $stemProgram->benefitSummary());
        $this->assertStringContainsString('support package', strtolower($collegeProgram->description));
        $this->assertStringContainsString('non-cash support package', strtolower($schoolEssentialsProgram->description));
        $this->assertStringContainsString('enrichment session', strtolower($stemProgram->description));
        $this->assertSame(60, $stemProgram->exam_duration_minutes);
        $this->assertSame('75.00', $stemProgram->exam_passing_score);
        $this->assertNull($collegeProgram->exam_duration_minutes);
        $this->assertTrue(app(ScholarshipEligibilityService::class)
            ->evaluate($stemProgram, $student)['is_eligible']);

        $events = ScholarshipEvent::query()->with('scholarship')->get();

        $this->assertCount(7, $events);
        $this->assertTrue($programs->every(fn (Scholarship $program): bool => in_array('distribution', $program->selection_stages, true)));
        $this->assertSame(
            ['distribution', 'interview'],
            $collegeProgram->events()->orderBy('type')->pluck('type')->all(),
        );
        $this->assertSame(
            ['distribution', 'exam', 'interview'],
            $stemProgram->events()->orderBy('type')->pluck('type')->all(),
        );
        $this->assertTrue($events->every(
            fn (ScholarshipEvent $event): bool => $event->scheduled_at->isAfter($event->scholarship->deadline),
        ));

        $programsByProvider = $programs->groupBy('provider_id');
        $this->assertCount(2, $programsByProvider);
        $this->assertTrue($programsByProvider->every(fn ($providerPrograms): bool => $providerPrograms->isNotEmpty()));

    }
}
