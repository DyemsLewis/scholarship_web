<?php

namespace Tests\Feature;

use App\Models\ApplicationSchedule;
use App\Models\ProviderAssessment;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProgramSelectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_approval_advances_to_the_configured_stage_and_applies_its_shared_schedule(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'exam',
            'interview',
            'distribution',
        ]);
        ProviderAssessment::create([
            'provider_id' => $provider->id,
            'title' => 'Shared qualifying exam',
            'assessment_type' => 'qualifying_exam',
            'delivery_mode' => 'onsite',
            'venue' => 'Community Learning Center',
            'instructions' => 'Bring a school ID and pencil.',
            'status' => 'active',
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'exam',
                'title' => 'General qualifying exam',
                'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => 'onsite',
                'venue' => 'Community Learning Center',
                'location_address' => 'Mabini Street, Quezon City',
                'instructions' => 'Bring a school ID and arrive 15 minutes early.',
            ])
            ->assertOk()
            ->assertJsonPath('audience_count', 0);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Eligibility and required files were reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'exam_scheduled')
            ->assertJsonPath('application.schedules.0.type', 'exam')
            ->assertJsonPath('application.schedules.0.title', 'General qualifying exam');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'exam_scheduled',
        ]);
        $this->assertDatabaseHas('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'exam',
            'title' => 'General qualifying exam',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_schedule',
        ]);
    }

    public function test_shared_distribution_is_automatically_released_to_newly_approved_applicants(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'distribution',
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'distribution',
                'title' => 'Scholarship release day',
                'scheduled_at' => now()->addWeek()->format('Y-m-d H:i:s'),
                'mode' => 'onsite',
                'venue' => 'Foundation Office',
                'instructions' => 'Bring a valid school ID and release form.',
            ])
            ->assertOk()
            ->assertJsonPath('audience_count', 0);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Applicant passed document and eligibility review.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'distribution_scheduled')
            ->assertJsonPath('application.schedules.0.type', 'distribution');

        $application->refresh();

        $this->assertNotNull($application->distribution_scheduled_for);
        $this->assertSame('distribution_scheduled', $application->status);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_status',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_schedule',
        ]);
    }

    public function test_editing_a_shared_event_preserves_each_applicants_completed_tracking(): void
    {
        [$provider, $_applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'interview',
            'distribution',
        ], 'interview');
        $firstDate = now()->addDays(2)->setTime(9, 0);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'interview',
                'title' => 'Applicant interview',
                'scheduled_at' => $firstDate->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'online_url' => 'https://example.test/interview-room',
                'instructions' => 'Join ten minutes before the interview.',
            ])
            ->assertOk()
            ->assertJsonPath('audience_count', 1);

        $schedule = ApplicationSchedule::query()
            ->where('scholarship_application_id', $application->id)
            ->where('type', 'interview')
            ->firstOrFail();
        $schedule->update([
            'status' => 'completed',
            'attendance_status' => 'attended',
            'attendance_notes' => 'Applicant completed the interview.',
            'completed_at' => now(),
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'interview',
                'title' => 'Final applicant interview',
                'scheduled_at' => now()->addDays(4)->setTime(10, 0)->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'online_url' => 'https://example.test/final-interview-room',
                'instructions' => 'Use the updated meeting link.',
            ])
            ->assertOk();

        $schedule->refresh();

        $this->assertSame('Final applicant interview', $schedule->title);
        $this->assertSame('completed', $schedule->status);
        $this->assertSame('attended', $schedule->attendance_status);
        $this->assertSame('Applicant completed the interview.', $schedule->attendance_notes);
    }

    public function test_provider_cannot_publish_a_stage_that_is_not_in_the_program_plan(): void
    {
        [$provider, $_applicant, $scholarship] = $this->applicationWithPlan([
            'screening',
            'distribution',
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'interview',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'online_url' => 'https://example.test/interview',
                'instructions' => 'Join the interview room.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->assertSame(0, ScholarshipEvent::query()->count());
    }

    public function test_program_form_stores_optional_stages_in_the_canonical_order(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Interview Selection Scholarship',
                'description' => 'A program with an interview before final approval.',
                'selection_stages' => json_encode(['interview']),
                'status' => 'draft',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.selection_stages', [
                'screening',
                'interview',
                'distribution',
            ]);

        $this->assertDatabaseHas('scholarships', [
            'provider_id' => $provider->id,
            'title' => 'Interview Selection Scholarship',
            'selection_stages' => json_encode(['screening', 'interview', 'distribution']),
        ]);
    }

    private function applicationWithPlan(array $stages, string $status = 'under_review'): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Program Selection Test Scholarship',
            'description' => 'Used to verify program-level selection and scheduling.',
            'selection_stages' => $stages,
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);

        return [$provider, $applicant, $scholarship, $application];
    }
}
