<?php

namespace Tests\Feature;

use App\Models\ApplicationSchedule;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApplicationScheduleWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_schedule_is_informational_and_does_not_change_the_application_stage(): void
    {
        [$provider, $applicant, $application] = $this->applicationAt('exam');

        $response = $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/schedules", [
                'type' => 'exam',
                'title' => 'Qualifying exam',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'mode' => 'onsite',
                'venue' => 'Provider office',
                'instructions' => 'Bring a school ID and arrive 15 minutes early.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'exam')
            ->assertJsonPath('schedule.attendance_status', 'not_required')
            ->assertJsonPath('schedule.requires_applicant_acknowledgment', false);

        $scheduleId = $response->json('schedule.id');

        $this->assertSame('exam_qualified', $application->fresh()->status);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_schedule',
        ]);

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/schedules/{$scheduleId}/acknowledge")
            ->assertUnprocessable();

        $this->assertNull(ApplicationSchedule::findOrFail($scheduleId)->applicant_acknowledged_at);
        $this->assertFalse(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('type', 'schedule_acknowledged')
            ->exists());
    }

    public function test_provider_records_a_stage_result_without_attendance_or_schedule_completion(): void
    {
        [$provider, , $application] = $this->applicationAt('interview');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/stages/interview/result", [
                'result' => 'passed',
                'notes' => 'The provider confirmed the interview result.',
            ])
            ->assertOk()
            ->assertJsonPath('application.workflow.current_stage', 'formal_application');

        $this->assertDatabaseHas('application_stage_progresses', [
            'scholarship_application_id' => $application->id,
            'stage_key' => 'interview',
            'status' => 'passed',
        ]);
    }

    public function test_shared_schedule_reaches_only_applicants_currently_at_that_stage(): void
    {
        [$provider, $applicant, $application, $scholarship] = $this->applicationAt('exam');
        [, , $formalApplication] = $this->applicationAt('formal_application', $provider, $scholarship);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'exam',
                'title' => 'Shared qualifying exam',
                'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'online_url' => 'https://example.test/exam-room',
                'instructions' => 'Open the link at the scheduled time.',
            ])
            ->assertOk()
            ->assertJsonPath('audience_count', 1);

        $this->assertDatabaseHas('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'exam',
            'attendance_status' => 'not_required',
        ]);
        $this->assertDatabaseMissing('application_schedules', [
            'scholarship_application_id' => $formalApplication->id,
            'type' => 'exam',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_schedule',
        ]);
    }

    public function test_program_schedule_can_be_archived_without_gating_applicant_results(): void
    {
        $provider = $this->provider();
        $scholarship = $this->scholarship($provider);
        $event = ScholarshipEvent::create([
            'scholarship_id' => $scholarship->id,
            'type' => 'exam',
            'title' => 'Archived exam schedule',
            'scheduled_at' => now()->subHour(),
            'mode' => 'onsite',
            'venue' => 'Provider office',
            'instructions' => 'Bring a school ID.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/scholarships/{$scholarship->id}/events/{$event->id}/complete")
            ->assertOk()
            ->assertJsonPath('event.status', 'completed')
            ->assertJsonPath('participant_count', 0);
    }

    public function test_only_exam_and_interview_can_be_published_as_schedules(): void
    {
        [$provider, , $application, $scholarship] = $this->applicationAt('exam');

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/schedules", [
                'type' => 'distribution',
                'title' => 'Award release',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'mode' => 'onsite',
                'venue' => 'Provider office',
                'instructions' => 'Bring a valid ID.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'exam',
                'title' => 'Online exam',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'instructions' => 'Join at the scheduled time.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('online_url');
    }

    private function applicationAt(
        string $stage,
        ?User $provider = null,
        ?Scholarship $scholarship = null,
    ): array {
        $provider ??= $this->provider();
        $scholarship ??= $this->scholarship($provider);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $status = match ($stage) {
            'exam' => 'exam_qualified',
            'interview' => 'interview',
            'formal_application', 'decision' => 'approved',
            default => 'under_review',
        };
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);

        if ($stage === 'decision') {
            $application->forceFill(['workflow_stage' => 'decision'])->save();
        }

        return [$provider, $applicant, $application, $scholarship];
    }

    private function provider(): User
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);

        return $provider;
    }

    private function scholarship(User $provider): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => fake()->unique()->sentence(4),
            'description' => 'Program used to verify informational schedules.',
            'selection_stages' => ['screening', 'exam', 'interview', 'formal_application', 'decision'],
            'status' => 'published',
        ]);
    }
}
