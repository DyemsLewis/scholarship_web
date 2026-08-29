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

    public function test_course_aliases_receive_the_same_dss_eligibility_score(): void
    {
        [$application, $applicant] = $this->application([
            'eligible_courses' => 'BSIT',
        ]);
        $applicant->studentProfile()->update([
            'education_level' => 'college',
            'course_or_strand' => 'BS Information Technology',
        ]);

        $score = app(DecisionSupportService::class)->scoreApplication($application);
        $eligibility = collect($score['criteria'])->firstWhere('key', 'eligibility');

        $this->assertSame(100, $eligibility['score']);
    }

    public function test_explanation_marks_missing_profile_data_as_provisional(): void
    {
        [$application, $applicant] = $this->application([
            'eligible_courses' => 'STEM',
            'eligible_locations' => 'NCR',
        ]);
        $applicant->studentProfile()->update([
            'course_or_strand' => null,
        ]);
        $application = $application->fresh(['applicant.studentProfile', 'documents', 'scholarship']);
        $service = app(DecisionSupportService::class);
        $score = $service->scoreApplication($application);
        $explanation = $service->explainApplication($application, $score);

        $this->assertSame('provisional', $explanation['comparison']['state']);
        $this->assertSame(1, $explanation['comparison']['missing']);
        $this->assertLessThan(100, $explanation['comparison']['completeness']);
        $this->assertSame('Suitability score is provisional.', $explanation['headline']);
        $this->assertStringContainsString('Treat the score as provisional', $explanation['score_interpretation']);
    }

    public function test_explanation_clarifies_scores_for_open_criteria(): void
    {
        [$application] = $this->application([
            'eligible_education_levels' => 'Any',
            'eligible_courses' => 'Any course',
            'eligible_school_types' => 'Open to all',
            'eligible_year_levels' => 'Any',
            'eligible_locations' => 'Nationwide',
            'income_requirement' => 'No income requirement',
        ]);
        $service = app(DecisionSupportService::class);
        $score = $service->scoreApplication($application);
        $explanation = $service->explainApplication($application, $score);

        $this->assertSame('open_criteria', $explanation['comparison']['state']);
        $this->assertSame(0, $explanation['comparison']['applicable']);
        $this->assertStringContainsString('not an applicant ranking', $explanation['score_interpretation']);
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
            'document_checklist' => collect(preg_split('/\r\n|\r|\n|,/', $scholarship->requirements ?? ''))
                ->map(fn (string $requirement) => trim($requirement))
                ->filter()
                ->values()
                ->all(),
            'submitted_at' => now(),
        ]);

        return [$application, $applicant, $scholarship];
    }
}
