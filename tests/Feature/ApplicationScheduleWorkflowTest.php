<?php

namespace Tests\Feature;

use App\Models\ApplicationSchedule;
use App\Models\MobileApiToken;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
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
        $this->seed();
    }

    public function test_provider_can_announce_interview_and_applicant_can_acknowledge_it(): void
    {
        $provider = User::query()->where('email', 'tulayaral@scholarship.test')->firstOrFail();
        $applicant = User::query()->where('email', 'student@scholarship.test')->firstOrFail();
        $scholarship = Scholarship::query()->where('provider_id', $provider->id)->firstOrFail();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'qualified',
            'submitted_at' => now(),
        ]);
        $scheduledAt = now()->addDays(2)->setTime(9, 30);

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/schedules", [
                'type' => 'interview',
                'title' => 'Applicant interview',
                'scheduled_at' => $scheduledAt->format('Y-m-d H:i:s'),
                'mode' => 'onsite',
                'venue' => 'Tulay Aral Community Desk',
                'location_address' => 'Barangay San Isidro, Antipolo City, Rizal',
                'latitude' => 14.6255,
                'longitude' => 121.1245,
                'instructions' => 'Bring a school ID and arrive 15 minutes early.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'interview')
            ->assertJsonPath('application.schedules.0.type', 'interview')
            ->assertJsonPath('application.schedules.0.applicant_acknowledged', false);

        $schedule = ApplicationSchedule::query()->firstOrFail();

        $this->actingAs($applicant)
            ->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonPath('applications.0.schedules.0.id', $schedule->id)
            ->assertJsonPath('applications.0.schedules.0.applicant_acknowledged', false);

        $this->actingAs($applicant)
            ->getJson('/dashboard/applications/data')
            ->assertOk()
            ->assertJsonPath('applications.0.schedules.0.title', 'Applicant interview');

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_schedule',
        ]);

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/schedules/{$schedule->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('schedule.applicant_acknowledged', true)
            ->assertJsonPath('application.schedules.0.applicant_acknowledged', true);

        $this->assertNotNull($schedule->fresh()->applicant_acknowledged_at);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('type', 'schedule_acknowledged')
            ->exists());
    }

    public function test_exam_completion_tracks_attendance_and_advances_the_application(): void
    {
        $provider = User::query()->where('email', 'bukasfoundation@scholarship.test')->firstOrFail();
        $applicant = User::query()->where('email', 'student@scholarship.test')->firstOrFail();
        $scholarship = Scholarship::query()
            ->where('provider_id', $provider->id)
            ->where('title', 'Bukas Kinabukasan STEM Pathways Grant')
            ->firstOrFail();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'exam_qualified',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/schedules", [
                'type' => 'exam',
                'title' => 'STEM qualifying activity',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'mode' => 'onsite',
                'venue' => 'Bukas Kinabukasan Learning Hub',
                'instructions' => 'Bring a school ID and pencil.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'exam_scheduled');

        $schedule = ApplicationSchedule::query()->firstOrFail();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/schedules/{$schedule->id}", [
                'status' => 'scheduled',
                'attendance_status' => 'attended',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attendance_status');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/schedules/{$schedule->id}", [
                'status' => 'completed',
                'attendance_status' => 'attended',
                'attendance_notes' => 'Applicant completed the activity.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $schedule->update(['scheduled_at' => now()->subMinute()]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/schedules/{$schedule->id}", [
                'status' => 'completed',
                'attendance_status' => 'attended',
                'attendance_notes' => 'Applicant completed the activity.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'exam_taken')
            ->assertJsonPath('schedule.attendance_status', 'attended');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'exam_taken',
        ]);
    }

    public function test_distribution_announcement_requires_approval_and_records_release(): void
    {
        $provider = User::query()->where('email', 'tulayaral@scholarship.test')->firstOrFail();
        $otherProvider = User::query()->where('email', 'bukasfoundation@scholarship.test')->firstOrFail();
        $applicant = User::query()->where('email', 'student@scholarship.test')->firstOrFail();
        $scholarship = Scholarship::query()->where('provider_id', $provider->id)->firstOrFail();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'under_review',
            'submitted_at' => now(),
        ]);
        $payload = [
            'type' => 'distribution',
            'title' => 'Grant release',
            'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'mode' => 'onsite',
            'venue' => 'Tulay Aral Community Desk',
            'instructions' => 'Bring a school ID and signed acknowledgment receipt.',
            'awarded_amount' => 10000,
        ];

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/schedules", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $application->update(['status' => 'approved']);

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/schedules", $payload)
            ->assertOk()
            ->assertJsonPath('application.status', 'distribution_scheduled')
            ->assertJsonPath('application.awarded_amount', '10000.00');

        $schedule = ApplicationSchedule::query()->firstOrFail();

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/schedules/{$schedule->id}/acknowledge")
            ->assertOk();

        $payload['awarded_amount'] = 12000;

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/schedules", $payload)
            ->assertOk()
            ->assertJsonPath('application.awarded_amount', '12000.00')
            ->assertJsonPath('schedule.applicant_acknowledged', false);

        $this->actingAs($otherProvider)
            ->patchJson("/provider/applications/{$application->id}/schedules/{$schedule->id}", [
                'status' => 'cancelled',
                'attendance_status' => 'pending',
            ])
            ->assertForbidden();

        $schedule->update(['scheduled_at' => now()->subMinute()]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/schedules/{$schedule->id}", [
                'status' => 'completed',
                'attendance_status' => 'received',
                'attendance_notes' => 'Grant released and receipt confirmed.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'disbursed');

        $this->assertDatabaseHas('application_schedules', [
            'id' => $schedule->id,
            'status' => 'completed',
            'attendance_status' => 'received',
        ]);
    }

    public function test_mobile_app_receives_and_acknowledges_an_active_schedule(): void
    {
        $provider = User::query()->where('email', 'tulayaral@scholarship.test')->firstOrFail();
        $applicant = User::query()->where('email', 'student@scholarship.test')->firstOrFail();
        $scholarship = Scholarship::query()->where('provider_id', $provider->id)->firstOrFail();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'qualified',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/applications/{$application->id}/schedules", [
                'type' => 'interview',
                'title' => 'Mobile applicant interview',
                'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'online_url' => 'https://meet.example.test/interview',
                'instructions' => 'Join ten minutes before the interview.',
            ])
            ->assertOk();

        $schedule = ApplicationSchedule::query()->firstOrFail();
        $plainToken = 'application-schedule-mobile-token';

        MobileApiToken::create([
            'user_id' => $applicant->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($plainToken)
            ->getJson('/api/mobile/profile')
            ->assertOk()
            ->assertJsonPath('applications.0.schedules.0.id', $schedule->id)
            ->assertJsonPath('applications.0.schedules.0.applicant_acknowledged', false);

        $this->withToken($plainToken)
            ->patchJson("/api/mobile/applications/{$application->id}/schedules/{$schedule->id}/acknowledge")
            ->assertOk()
            ->assertJsonPath('schedule.applicant_acknowledged', true)
            ->assertJsonPath('application.schedules.0.applicant_acknowledged', true);

        $this->assertNotNull($schedule->fresh()->applicant_acknowledged_at);
    }
}
