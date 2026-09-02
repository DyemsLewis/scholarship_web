<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApplicationDocumentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_delegated_provider_reviewer_can_view_and_download_organization_documents(): void
    {
        Storage::fake('local');
        [$provider, $applicant, $document] = $this->applicationDocument();
        $reviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['review_applications'],
        ]);

        $this->actingAs($reviewer)
            ->get("/documents/{$document->id}/view")
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private');

        $this->actingAs($reviewer)
            ->get("/documents/{$document->id}/download")
            ->assertOk()
            ->assertDownload($document->original_name);

        $this->actingAs($applicant)
            ->get("/documents/{$document->id}/view")
            ->assertOk();
    }

    public function test_provider_staff_without_review_access_cannot_open_application_documents(): void
    {
        Storage::fake('local');
        [$provider, , $document] = $this->applicationDocument();
        $programCoordinator = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs'],
        ]);
        $otherProvider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($programCoordinator)
            ->get("/documents/{$document->id}/view")
            ->assertForbidden();

        $this->actingAs($otherProvider)
            ->get("/documents/{$document->id}/download")
            ->assertForbidden();
    }

    public function test_another_applicant_cannot_open_or_download_private_application_documents(): void
    {
        Storage::fake('local');
        [, , $document] = $this->applicationDocument();
        $otherApplicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($otherApplicant)
            ->get("/documents/{$document->id}/view")
            ->assertForbidden();

        $this->actingAs($otherApplicant)
            ->get("/documents/{$document->id}/download")
            ->assertForbidden();
    }

    private function applicationDocument(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Document Access Program',
            'description' => 'A program used to test delegated document review.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $path = "applications/{$application->id}/proof.pdf";
        Storage::disk('local')->put($path, 'private applicant proof');
        $document = ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'Certificate of enrollment',
            'original_name' => 'proof.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 23,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        return [$provider, $applicant, $document];
    }
}
