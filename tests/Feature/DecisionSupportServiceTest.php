<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\DecisionSupportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionSupportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_criteria_do_not_penalize_an_applicant(): void
    {
        [$application] = $this->application([
            'eligible_education_levels' => 'Any',
            'eligible_courses' => 'Any course',
            'eligible_school_types' => 'Open to all',
            'eligible_year_levels' => 'Any',
            'eligible_locations' => 'Nationwide',
            'income_requirement' => 'No income requirement',
        ]);

        $score = app(DecisionSupportService::class)->scoreApplication($application);

        $this->assertSame(DecisionSupportService::METHODOLOGY_VERSION, $score['methodology_version']);
        $this->assertSame(100, $score['suitability_score']);
        $this->assertSame('Strong match', $score['label']);
    }

    public function test_provider_progress_and_documents_do_not_change_suitability(): void
    {
        [$application, $applicant] = $this->application([
            'requirements' => "School ID\nLatest report card or grades",
        ]);
        $service = app(DecisionSupportService::class);
        $initial = $service->scoreApplication($application);

        ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'School ID',
            'original_name' => 'school-id.pdf',
            'path' => 'testing/school-id.pdf',
            'mime_type' => 'application/pdf',
            'status' => 'accepted',
            'uploaded_at' => now(),
        ]);
        $application->update(['status' => 'shortlisted']);

        $updated = $service->scoreApplication($application->fresh());

        $this->assertSame($initial['suitability_score'], $updated['suitability_score']);
        $this->assertGreaterThan($initial['application_readiness']['score'], $updated['application_readiness']['score']);
        $this->assertNotSame($initial['review_progress']['score'], $updated['review_progress']['score']);
    }

    private function application(array $scholarshipAttributes = []): array
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'education_level' => 'Senior high school',
            'course_or_strand' => 'STEM',
            'school_type' => 'Public',
            'year_level' => 'Grade 12',
            'region' => 'NCR',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below 10,000',
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'DSS Test Scholarship',
            'description' => 'Used to verify decision support behavior.',
            'status' => 'published',
            ...$scholarshipAttributes,
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);

        return [$application, $applicant, $scholarship];
    }
}
