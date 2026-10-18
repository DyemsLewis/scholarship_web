<?php

namespace Tests\Feature;

use App\Models\ApplicationStageSession;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ApplicantStageActivityExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_applicant_detail_exposes_only_their_published_activity_details(): void
    {
        [$application, $provider] = $this->applicationAtExam();
        $published = $this->createStageSession($application->scholarship, $provider, [
            'mode' => 'online',
            'venue' => null,
            'location_address' => null,
            'meeting_url' => 'https://meet.example.test/applicant-room',
            'status' => 'scheduled',
            'published_at' => now(),
        ]);
        $assignment = $published->assignments()->create([
            'scholarship_application_id' => $application->id,
            'status' => 'assigned',
            'notes' => 'Private provider scheduling note.',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
        ]);
        $assignment->attendance()->create(['status' => 'pending']);

        $draft = $this->createStageSession($application->scholarship, $provider, [
            'title' => 'Unpublished replacement',
            'status' => 'draft',
            'published_at' => null,
        ]);
        $draft->assignments()->create([
            'scholarship_application_id' => $application->id,
            'status' => 'assigned',
            'notes' => 'Do not show this draft.',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
        ])->attendance()->create(['status' => 'pending']);

        $this->actingAs($application->applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonCount(1, 'application.selection_activities')
            ->assertJsonPath('application.selection_activities.0.id', $assignment->id)
            ->assertJsonPath('application.selection_activities.0.type', 'exam')
            ->assertJsonPath('application.selection_activities.0.online_url', 'https://meet.example.test/applicant-room')
            ->assertJsonPath('application.selection_activities.0.requires_applicant_acknowledgment', true)
            ->assertJsonMissingPath('application.selection_activities.0.notes')
            ->assertJsonMissingPath('application.selection_activities.0.proof_required')
            ->assertJsonMissingPath('application.selection_activities.0.meeting_url')
            ->assertJsonMissing(['title' => 'Unpublished replacement']);

        $this->actingAs($application->applicant)
            ->getJson('/dashboard/overview-data')
            ->assertOk()
            ->assertJsonFragment([
                'kind' => 'schedule',
                'title' => 'Scholarship examination',
                'href' => "/dashboard/applications/{$application->id}?section=schedule",
            ]);
    }

    public function test_applicant_can_acknowledge_an_activity_without_advancing_the_stage(): void
    {
        [$application, $provider] = $this->applicationAtExam();
        $session = $this->createStageSession($application->scholarship, $provider, [
            'status' => 'scheduled',
            'published_at' => now(),
        ]);
        $assignment = $session->assignments()->create([
            'scholarship_application_id' => $application->id,
            'status' => 'assigned',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
        ]);
        $assignment->attendance()->create(['status' => 'pending']);

        $this->actingAs($application->applicant)
            ->patchJson("/dashboard/applications/{$application->id}/stage-activities/{$assignment->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('activity.applicant_acknowledged', true)
            ->assertJsonPath('activity.requires_applicant_acknowledgment', false);

        $this->assertDatabaseHas('application_stage_assignments', [
            'id' => $assignment->id,
            'status' => 'acknowledged',
        ]);
        $this->assertDatabaseHas('application_stage_audit_events', [
            'application_stage_assignment_id' => $assignment->id,
            'event_type' => 'applicant_acknowledged',
            'actor_id' => $application->applicant_id,
        ]);
        $this->assertSame('exam', $application->fresh()->workflow_stage);
        $this->assertNull(
            $application->stageProgresses()->where('stage_key', 'exam')->value('result'),
        );
    }

    public function test_applicant_cannot_acknowledge_another_applicants_activity(): void
    {
        [$application, $provider] = $this->applicationAtExam();
        [$otherApplication] = $this->applicationAtExam($provider, $application->scholarship);
        $session = $this->createStageSession($application->scholarship, $provider, [
            'status' => 'scheduled',
            'published_at' => now(),
        ]);
        $assignment = $session->assignments()->create([
            'scholarship_application_id' => $otherApplication->id,
            'status' => 'assigned',
            'assigned_by' => $provider->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($application->applicant)
            ->patchJson("/dashboard/applications/{$application->id}/stage-activities/{$assignment->id}/acknowledge")
            ->assertNotFound();

        $this->assertSame('assigned', $assignment->fresh()->status);
    }

    /** @return array{ScholarshipApplication, User} */
    private function applicationAtExam(?User $provider = null, ?Scholarship $scholarship = null): array
    {
        $provider ??= User::factory()->create(['role' => 'provider']);
        $scholarship ??= Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Complete Path Scholarship',
            'description' => 'A complete scholarship workflow for applicant activity testing.',
            'selection_stages' => ['screening', 'exam', 'formal_application', 'decision'],
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'submitted',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);

        return [$application, $provider];
    }

    private function createStageSession(Scholarship $scholarship, User $provider, array $overrides = []): ApplicationStageSession
    {
        return $scholarship->stageSessions()->create([
            'stage_key' => 'exam',
            'title' => 'Scholarship examination',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHours(2),
            'timezone' => 'Asia/Manila',
            'mode' => 'onsite',
            'venue' => 'Tulay Aral Testing Center',
            'location_address' => 'Antipolo City, Rizal',
            'capacity' => 30,
            'instructions' => 'Bring a recent school ID and arrive 30 minutes early.',
            'status' => 'scheduled',
            'proof_required' => true,
            'published_at' => now(),
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
            ...$overrides,
        ]);
    }
}
