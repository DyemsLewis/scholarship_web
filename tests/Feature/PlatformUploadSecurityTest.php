<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlatformUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_document_upload_rejects_executable_and_oversized_files(): void
    {
        Storage::fake('local');
        [$applicant, $application] = $this->application();

        $this->actingAs($applicant)
            ->post("/dashboard/applications/{$application->id}/documents", [
                'document_name' => 'Latest report card or grades',
                'document_file' => UploadedFile::fake()->create('malware.exe', 1, 'application/x-msdownload'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_file');

        $this->actingAs($applicant)
            ->post("/dashboard/applications/{$application->id}/documents", [
                'document_name' => 'Latest report card or grades',
                'document_file' => UploadedFile::fake()->create('grades.pdf', 5121, 'application/pdf'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_file');

        $this->assertDatabaseCount('application_documents', 0);
    }

    public function test_provider_verification_upload_rejects_executable_files(): void
    {
        Storage::fake('local');
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->post('/provider/verification-documents', [
                'document_type' => 'organization_registration',
                'document_file' => UploadedFile::fake()->create('payload.exe', 1, 'application/x-msdownload'),
                'terms_accepted' => '1',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_file');

        $this->assertDatabaseCount('provider_verification_documents', 0);
    }

    public function test_application_document_upload_is_rate_limited(): void
    {
        [$applicant, $application] = $this->application();

        foreach (range(1, 20) as $attempt) {
            $this->actingAs($applicant)
                ->postJson("/dashboard/applications/{$application->id}/documents", [])
                ->assertUnprocessable();
        }

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/documents", [])
            ->assertTooManyRequests();
    }

    private function application(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Secure Upload Program',
            'description' => 'A program used to validate upload security boundaries.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        return [$applicant, $application];
    }
}
