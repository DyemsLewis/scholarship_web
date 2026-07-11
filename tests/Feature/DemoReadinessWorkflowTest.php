<?php

namespace Tests\Feature;

use App\Models\ApplicationDocument;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DemoReadinessWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_provider_and_applicant_can_complete_the_core_workflow(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'pending']);
        $applicant = $this->completeApplicant();

        $this->actingAs($admin)
            ->patchJson("/admin/providers/{$provider->id}/verification", [
                'verification_status' => 'approved',
                'verification_notes' => 'Organization details and proof were reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('provider.verification_status', 'approved');

        $scholarshipResponse = $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Core Workflow Scholarship',
                'description' => 'A scholarship used to verify the complete role workflow.',
                'deadline' => now()->addMonth()->toDateString(),
                'status' => 'pending_review',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.status', 'pending_review');

        $scholarshipId = $scholarshipResponse->json('scholarship.id');

        $this->actingAs($admin)
            ->patchJson("/admin/scholarships/{$scholarshipId}/review", [
                'status' => 'published',
                'review_notes' => 'Program details are ready for applicants.',
            ])
            ->assertOk()
            ->assertJsonPath('scholarship.status', 'published');

        $applicationResponse = $this->actingAs($applicant)
            ->postJson('/dashboard/applications', [
                'scholarship_id' => $scholarshipId,
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.status', 'submitted');

        $applicationId = $applicationResponse->json('application.id');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$applicationId}/status", [
                'status' => 'under_review',
                'review_notes' => 'Application entered provider review.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'under_review');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$applicationId}/status", [
                'status' => 'approved',
                'decision_reason' => 'meets_all_criteria',
                'review_notes' => 'The applicant meets the published criteria.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved');

        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => $provider->id,
            'verification_status' => 'approved',
        ]);
        $this->assertDatabaseHas('scholarships', [
            'id' => $scholarshipId,
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $applicationId,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_status',
        ]);
    }

    public function test_negative_decisions_require_feedback_and_include_it_in_notifications(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->create();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Feedback Test Scholarship',
            'description' => 'Used to verify clear negative decision feedback.',
            'status' => 'pending_review',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $document = ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'Report Card',
            'original_name' => 'report-card.pdf',
            'path' => 'test/report-card.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/scholarships/{$scholarship->id}/review", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');

        $this->actingAs($admin)
            ->patchJson("/admin/providers/{$provider->id}/verification", ['verification_status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_notes');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('decision_reason');

        $this->actingAs($provider)
            ->patchJson("/provider/documents/{$document->id}/status", ['status' => 'needs_replacement'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('review_notes');

        $this->actingAs($admin)
            ->patchJson("/admin/scholarships/{$scholarship->id}/review", [
                'status' => 'rejected',
                'review_notes' => 'Clarify the eligibility criteria before resubmitting.',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/documents/{$document->id}/status", [
                'status' => 'needs_replacement',
                'review_notes' => 'Upload a complete and readable report card.',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'rejected',
                'decision_reason' => 'incomplete_requirements',
                'review_notes' => 'The required supporting records were incomplete.',
            ])
            ->assertOk();

        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('message', 'like', '%Clarify the eligibility criteria%')
            ->exists());
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('message', 'like', '%complete and readable report card%')
            ->exists());
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('message', 'like', '%Incomplete Requirements%')
            ->exists());
    }

    private function completeApplicant(): User
    {
        $applicant = User::factory()->create();
        $applicant->studentProfile()->update([
            'birthdate' => '2005-06-01',
            'education_level' => 'college',
            'school' => 'Demo University',
            'course_or_strand' => 'BS Information Technology',
            'year_level' => '1st year',
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
