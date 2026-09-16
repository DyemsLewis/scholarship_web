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

class ProviderQueueOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_can_assign_an_application_only_to_an_active_organization_reviewer(): void
    {
        Mail::fake();

        $provider = User::factory()->create(['role' => 'provider']);
        $reviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['review_applications'],
        ]);
        $nonReviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs'],
        ]);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Reviewer Assignment Program',
            'description' => 'Tests provider-scoped reviewer ownership.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now()->subDays(3),
        ]);

        $payload = $this->actingAs($provider)
            ->getJson('/provider/applications/data')
            ->assertOk()
            ->json();
        $reviewerIds = collect($payload['reviewers'])->pluck('id')->all();

        $this->assertContains($provider->id, $reviewerIds);
        $this->assertContains($reviewer->id, $reviewerIds);
        $this->assertNotContains($nonReviewer->id, $reviewerIds);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/reviewer", [
                'assigned_reviewer_id' => $reviewer->id,
            ])
            ->assertOk()
            ->assertJsonPath('application.assigned_reviewer.id', $reviewer->id);

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'assigned_reviewer_id' => $reviewer->id,
        ]);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $reviewer->id)
            ->where('type', 'application_assignment')
            ->exists());

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/reviewer", [
                'assigned_reviewer_id' => $otherProvider->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_reviewer_id');
    }

    public function test_managed_team_member_cannot_assign_an_application_to_the_provider_owner(): void
    {
        Mail::fake();

        $provider = User::factory()->create(['role' => 'provider']);
        $teamMember = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['review_applications'],
        ]);
        $otherReviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['review_applications'],
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Managed Reviewer Assignment Program',
            'description' => 'Tests owner assignment restrictions for managed team members.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now()->subDay(),
        ]);

        $reviewerIds = collect($this->actingAs($teamMember)
            ->getJson('/provider/applications/data')
            ->assertOk()
            ->json('reviewers'))
            ->pluck('id')
            ->all();

        $this->assertNotContains($provider->id, $reviewerIds);
        $this->assertContains($teamMember->id, $reviewerIds);
        $this->assertContains($otherReviewer->id, $reviewerIds);

        $this->actingAs($teamMember)
            ->patchJson("/provider/applications/{$application->id}/reviewer", [
                'assigned_reviewer_id' => $provider->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_reviewer_id');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'assigned_reviewer_id' => null,
        ]);

        $this->actingAs($teamMember)
            ->patchJson("/provider/applications/{$application->id}/reviewer", [
                'assigned_reviewer_id' => $otherReviewer->id,
            ])
            ->assertOk()
            ->assertJsonPath('application.assigned_reviewer.id', $otherReviewer->id);
    }

    public function test_provider_cannot_assign_a_reviewer_outside_their_program_scope(): void
    {
        Mail::fake();

        $provider = User::factory()->create(['role' => 'provider']);
        $assignedProgram = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Reviewer Assigned Program',
            'description' => 'The only program available to the reviewer.',
            'status' => 'published',
        ]);
        $restrictedProgram = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Reviewer Restricted Program',
            'description' => 'A program outside the reviewer assignment.',
            'status' => 'published',
        ]);
        $reviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['review_applications'],
            'assigned_program_ids' => [$assignedProgram->id],
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $restrictedProgram->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/reviewer", [
                'assigned_reviewer_id' => $reviewer->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_reviewer_id');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'assigned_reviewer_id' => null,
        ]);
    }

    public function test_provider_queue_exposes_program_usage_waiting_time_and_replaced_file_signal(): void
    {
        Mail::fake();

        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $selectedApplicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Queue Metrics Program',
            'description' => 'Tests compact provider queue metrics.',
            'slots_available' => 3,
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'reviewed_by' => $provider->id,
            'reviewed_at' => now()->subDays(2),
            'submitted_at' => now()->subDays(4),
        ]);
        ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $selectedApplicant->id,
            'status' => 'awarded',
            'submitted_at' => now()->subDay(),
        ]);
        ApplicationDocument::create([
            'scholarship_application_id' => $application->id,
            'uploaded_by' => $applicant->id,
            'document_name' => 'Latest report card or grades',
            'original_name' => 'updated-grades.pdf',
            'path' => 'application-documents/testing/updated-grades.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'status' => 'pending',
            'uploaded_at' => now(),
        ]);

        $dashboard = $this->actingAs($provider)
            ->getJson('/provider/dashboard/data')
            ->assertOk()
            ->assertJsonPath('application_workflow_counts.needs_review', 1)
            ->assertJsonPath('application_workflow_counts.all', 2)
            ->json();
        $dashboardProgram = $dashboard['scholarships'][0];

        $this->assertSame(2, $dashboardProgram['applications_count']);
        $this->assertSame(1, $dashboardProgram['pending_review_applications_count']);
        $this->assertSame(1, $dashboardProgram['awarded_slots_count']);

        $queueApplication = collect($this->actingAs($provider)
            ->getJson('/provider/applications/data')
            ->assertOk()
            ->json('applications'))
            ->firstWhere('id', $application->id);

        $this->assertSame(4, $queueApplication['waiting_days']);
        $this->assertTrue($queueApplication['documents_changed_since_review']);
        $this->assertNotNull($queueApplication['latest_document_uploaded_at']);
    }
}
