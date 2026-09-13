<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class ProviderApplicationExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_application_export_contains_only_review_ready_data(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create([
            'role' => 'applicant',
            'email' => 'applicant@example.test',
        ]);
        $applicant->studentProfile()->updateOrCreate(['user_id' => $applicant->id], [
            'education_level' => 'senior_high_school',
            'contact_number' => '09171234567',
            'school' => 'Test Senior High School',
            'school_type' => 'public',
            'course_or_strand' => 'STEM',
            'year_level' => 'Grade 12',
            'gwa' => '92',
            'grading_scale' => 'percentage',
            'income_bracket' => 'below_10000',
            'barangay' => 'Barangay Test',
            'city' => 'Test City',
            'region' => 'Metro Manila',
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Test Provider Program',
            'description' => 'Export test program.',
            'status' => 'published',
            'selection_stages' => ['screening', 'decision'],
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'under_review',
            'workflow_version' => 2,
            'application_state' => 'under_review',
            'workflow_stage' => 'screening',
            'eligibility_score' => 100,
            'eligibility_breakdown' => ['score' => 100, 'criteria' => []],
            'document_checklist' => ['Latest report card or grades', 'Proof of income'],
            'submitted_at' => now(),
        ]);
        ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'Latest report card or grades',
            'original_name' => 'grades.pdf',
            'path' => 'uploads/applications/grades.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'accepted',
            'uploaded_at' => now(),
        ]);
        ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'Proof of income',
            'original_name' => 'income.pdf',
            'path' => 'uploads/applications/income.pdf',
            'mime_type' => 'application/pdf',
            'size' => 2048,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        $response = $this->actingAs($provider)
            ->get('/provider/export/applications?scholarship_id='.$scholarship->id)
            ->assertOk()
            ->assertDownload("provider-applications-program-{$scholarship->id}.xlsx");

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('content-type'),
        );

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($response->baseResponse->getFile()->getPathname()));
        $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
        $styles = $archive->getFromName('xl/styles.xml');
        $workbook = $archive->getFromName('xl/workbook.xml');
        $archive->close();

        $this->assertIsString($sheet);
        $this->assertIsString($styles);
        $this->assertIsString($workbook);
        $this->assertStringContainsString('Applicant Review Report', $sheet);
        $this->assertStringContainsString('Test Provider Program', $sheet);
        $this->assertStringContainsString('Pre-screening review', $sheet);
        $this->assertStringContainsString('Proof of income (Pending review)', $sheet);
        $this->assertStringContainsString('Document Readiness %', $sheet);
        $this->assertStringNotContainsString('Documents Confirmed', $sheet);
        $this->assertStringNotContainsString('Education Level', $sheet);
        $this->assertStringNotContainsString('Awarded Amount', $sheet);
        $this->assertStringNotContainsString('Distribution Instructions', $sheet);
        $this->assertStringContainsString('<autoFilter ref="A3:Q4"/>', $sheet);
        $this->assertStringContainsString('state="frozen"', $sheet);
        $this->assertStringContainsString('_xlnm._FilterDatabase', $workbook);
        $this->assertStringContainsString('&apos;Applicants&apos;!$A$3:$Q$4', $workbook);
        $this->assertStringContainsString('FF0B172A', $styles);
        $this->assertStringNotContainsString('FFFCD34D', $styles);
    }
}
