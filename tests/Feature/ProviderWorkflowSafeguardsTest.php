<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderWorkflowSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provider_cannot_advance_an_application_until_required_files_are_accepted(): void
    {
        [$provider, $application] = $this->applicationWithRequiredDocument('School ID');
        $document = $application->documents()->firstOrFail();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Eligibility review is complete.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('submitted', $application->fresh()->status);

        $this->actingAs($provider)
            ->patchJson("/provider/documents/{$document->id}/status", [
                'status' => 'accepted',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Eligibility and required files were reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved');
    }

    public function test_application_readiness_keeps_the_submitted_document_snapshot(): void
    {
        [$provider, $application] = $this->applicationWithRequiredDocument('School ID');

        $application->scholarship()->update([
            'requirements' => "Birth certificate\nProof of income",
        ]);

        $this->actingAs($provider)
            ->getJson("/provider/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.document_readiness.required', 1)
            ->assertJsonPath('application.document_readiness.uploaded', 1)
            ->assertJsonPath('application.document_readiness.accepted', 0)
            ->assertJsonPath('application.document_readiness.missing', [])
            ->assertJsonPath('application.document_readiness.not_accepted.0', 'School ID');
    }

    public function test_provider_cannot_change_selection_stages_after_applications_exist(): void
    {
        $provider = $this->verifiedProvider();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Protected Review Path',
            'description' => 'A program with an active applicant intake.',
            'selection_stages' => ['screening', 'distribution'],
            'status' => 'draft',
        ]);
        ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'submitted',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", [
                'title' => $scholarship->title,
                'description' => $scholarship->description,
                'selection_stages' => json_encode(['screening', 'exam', 'distribution']),
                'status' => 'draft',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('selection_stages');

        $this->assertSame(['screening', 'distribution'], $scholarship->fresh()->selection_stages);
    }

    public function test_provider_cannot_reduce_slots_below_awards_already_recorded(): void
    {
        $provider = $this->verifiedProvider();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Protected Award Capacity',
            'description' => 'A program with awards already recorded.',
            'slots_available' => 3,
            'status' => 'draft',
        ]);

        foreach (range(1, 2) as $_index) {
            ScholarshipApplication::create([
                'scholarship_id' => $scholarship->id,
                'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
                'status' => 'approved',
                'document_checklist' => [],
                'submitted_at' => now(),
            ]);
        }

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", [
                'title' => $scholarship->title,
                'description' => $scholarship->description,
                'slots_available' => 1,
                'status' => 'draft',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slots_available');

        $this->assertSame(3, $scholarship->fresh()->slots_available);
    }

    private function applicationWithRequiredDocument(string $documentName): array
    {
        $provider = $this->verifiedProvider();
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Document Review Scholarship',
            'description' => 'A program used to verify provider document safeguards.',
            'requirements' => $documentName,
            'selection_stages' => ['screening', 'distribution'],
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'document_checklist' => [$documentName],
            'submitted_at' => now(),
        ]);
        ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => $documentName,
            'original_name' => 'school-id.pdf',
            'path' => 'test/school-id.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        return [$provider, $application->fresh(['documents', 'scholarship'])];
    }

    private function verifiedProvider(): User
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        return $provider;
    }
}
