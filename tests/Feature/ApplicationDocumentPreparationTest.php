<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationDocumentPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_page_lists_common_reusable_files_instead_of_provider_specific_papers(): void
    {
        Mail::fake();

        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Provider Paper Test',
            'description' => 'Uses a paper that only applies to this provider.',
            'requirements' => "Latest report card or grades\nProvider-specific essay form",
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);

        $documentOptions = $this->actingAs($applicant)
            ->getJson('/dashboard/documents/data')
            ->assertOk()
            ->json('document_options');

        $this->assertSame([
            'Latest report card or grades',
            'Certificate of enrollment',
            'School ID',
            'Proof of income',
            'Certificate of indigency',
            'Birth certificate',
            'Parent or guardian valid ID',
            'Transcript of records',
            'Good moral certificate',
            'Barangay certificate of residency',
            'Government-issued ID',
            'Recent 2x2 ID photo',
            'Admission or acceptance letter',
        ], $documentOptions);
        $this->assertNotContains('Provider-specific essay form', $documentOptions);
    }

    public function test_applicant_uploads_required_files_before_submitting_and_can_access_them_in_the_wizard(): void
    {
        Mail::fake();
        Storage::fake('local');

        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = $this->completeApplicant();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Prepared Files Scholarship',
            'description' => 'Verifies the pre-submission document upload workflow.',
            'eligible_education_levels' => 'college',
            'eligible_courses' => 'Any',
            'eligible_school_types' => 'Any',
            'eligible_year_levels' => 'Any',
            'eligible_locations' => 'Philippines',
            'income_requirement' => 'Any',
            'requirements' => "Certificate of enrollment\nLatest report card or grades",
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'published',
        ]);

        $this->actingAs($applicant)
            ->postJson('/dashboard/applications', [
                'scholarship_id' => $scholarship->id,
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Upload every required document before continuing with your application.')
            ->assertJsonCount(2, 'missing_documents');

        foreach ([
            'Certificate of enrollment' => 'enrollment.pdf',
            'Latest report card or grades' => 'report-card.pdf',
        ] as $documentName => $filename) {
            $this->actingAs($applicant)
                ->post('/dashboard/student-documents', [
                    'document_name' => $documentName,
                    'document_file' => UploadedFile::fake()->create($filename, 100, 'application/pdf'),
                    'terms_accepted' => true,
                ], ['HTTP_ACCEPT' => 'application/json'])
                ->assertOk()
                ->assertJsonPath('document.document_name', $documentName);
        }

        $this->actingAs($applicant)
            ->getJson('/dashboard/applications/data')
            ->assertOk()
            ->assertJsonCount(2, 'prepared_documents')
            ->assertJsonPath('scholarships.0.prepared_documents.percent', 100)
            ->assertJsonFragment(['document_name' => 'Certificate of enrollment'])
            ->assertJsonFragment(['document_name' => 'Latest report card or grades']);

        $response = $this->actingAs($applicant)
            ->postJson('/dashboard/applications', [
                'scholarship_id' => $scholarship->id,
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.status', 'submitted')
            ->assertJsonCount(2, 'application.documents');

        $application = ScholarshipApplication::findOrFail($response->json('application.id'));

        $this->assertSame([
            'Certificate of enrollment',
            'Latest report card or grades',
        ], $application->document_checklist);
        $this->assertSame(2, ApplicationDocument::query()
            ->where('scholarship_application_id', $application->id)
            ->count());

        ApplicationDocument::query()
            ->where('scholarship_application_id', $application->id)
            ->each(fn (ApplicationDocument $document) => Storage::disk('local')->assertExists($document->path));
    }

    private function completeApplicant(): User
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01',
            'education_level' => 'college',
            'school' => 'Demo University',
            'school_type' => 'public',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
            'enrollment_status' => 'Enrolled',
            'gwa' => 90,
            'grading_scale' => 'percentage',
            'income_bracket' => 'Below PHP 10,000',
            'city' => 'Quezon City',
            'province' => 'Metro Manila',
            'region' => 'NCR',
        ]);

        return $applicant->fresh();
    }
}
