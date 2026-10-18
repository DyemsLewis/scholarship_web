<?php

namespace Tests\Feature;

use App\Domain\Recipients\RecipientSupportCatalog;
use App\Mail\PortalNotificationMail;
use App\Models\PortalNotification;
use App\Models\RecipientMonitoringSubmission;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientMonitoringCaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MonitoringReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_recipient_receives_calm_deduplicated_due_reminders(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-11-01 08:15:00');
        [, $applicant, , , $case] = $this->monitoringCase(now()->addDays(7)->toDateString());

        $this->artisan('monitoring:send-reminders')->assertSuccessful();
        $this->artisan('monitoring:send-reminders')->assertSuccessful();

        $this->assertSame(1, $this->notifications($applicant, 'monitoring_due_reminder')->count());
        $this->assertSame(1, $this->notifications($applicant, 'monitoring_due_reminder')->whereNull('read_at')->count());

        Carbon::setTestNow('2026-11-06 08:15:00');
        $this->artisan('monitoring:send-reminders')->assertSuccessful();
        Carbon::setTestNow('2026-11-08 08:15:00');
        $this->artisan('monitoring:send-reminders')->assertSuccessful();

        $this->assertSame(3, $this->notifications($applicant, 'monitoring_due_reminder')->count());
        $this->assertSame(1, $this->notifications($applicant, 'monitoring_due_reminder')->whereNull('read_at')->count());
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'deduplication_key' => "monitoring-case:{$case->id}:applicant:due:today:2026-11-08",
        ]);
        Mail::assertQueued(PortalNotificationMail::class, 3);
    }

    public function test_grace_window_uses_two_clear_recipient_checkpoints(): void
    {
        Carbon::setTestNow('2026-11-01 08:15:00');
        [, $applicant] = $this->monitoringCase('2026-10-31', gracePeriodDays: 7);

        $this->artisan('monitoring:send-reminders')->assertSuccessful();
        $this->artisan('monitoring:send-reminders')->assertSuccessful();
        Carbon::setTestNow('2026-11-06 08:15:00');
        $this->artisan('monitoring:send-reminders')->assertSuccessful();

        $notifications = $this->notifications($applicant, 'monitoring_grace_reminder');
        $this->assertSame(2, $notifications->count());
        $this->assertSame(1, $notifications->whereNull('read_at')->count());
        $this->assertSame('Monitoring request window closes soon', $notifications->sortByDesc('id')->first()->title);
    }

    public function test_waiting_provider_work_only_alerts_assigned_monitoring_staff(): void
    {
        Carbon::setTestNow('2026-11-10 08:15:00');
        [$owner, , $program, $application] = $this->monitoringCase('2026-11-30');
        $monitoringOfficer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_status' => 'active',
            'permissions' => ['manage_monitoring'],
            'assigned_program_ids' => [$program->id],
        ]);
        $otherProgramOfficer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_status' => 'active',
            'permissions' => ['manage_monitoring'],
            'assigned_program_ids' => [],
        ]);
        RecipientMonitoringSubmission::create([
            'recipient_monitoring_cycle_id' => $program->monitoringCycles()->value('id'),
            'scholarship_application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'submission_source' => 'applicant_upload',
            'original_name' => 'progress-record.pdf',
            'path' => 'monitoring/progress-record.pdf',
            'mime_type' => 'application/pdf',
            'submitted_at' => now()->subDays(2),
            'review_status' => 'pending',
        ]);

        $this->artisan('monitoring:send-reminders')->assertSuccessful();
        $this->artisan('monitoring:send-reminders')->assertSuccessful();

        $this->assertSame(1, $this->notifications($monitoringOfficer, 'monitoring_action_reminder')->count());
        $this->assertSame(0, $this->notifications($owner, 'monitoring_action_reminder')->count());
        $this->assertSame(0, $this->notifications($otherProgramOfficer, 'monitoring_action_reminder')->count());

        Carbon::setTestNow('2026-11-13 08:15:00');
        $this->artisan('monitoring:send-reminders')->assertSuccessful();

        $notifications = $this->notifications($monitoringOfficer, 'monitoring_action_reminder');
        $this->assertSame(2, $notifications->count());
        $this->assertSame(1, $notifications->whereNull('read_at')->count());
    }

    public function test_overdue_case_escalates_to_owner_after_seven_days_and_stops_when_closed(): void
    {
        Carbon::setTestNow('2026-11-01 08:15:00');
        [$owner, $applicant, $program, , $case] = $this->monitoringCase('2026-10-31');
        $monitoringOfficer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_status' => 'active',
            'permissions' => ['manage_monitoring'],
            'assigned_program_ids' => [$program->id],
        ]);

        $this->artisan('monitoring:send-reminders')->assertSuccessful();
        $this->artisan('monitoring:send-reminders')->assertSuccessful();

        $this->assertSame(1, $this->notifications($monitoringOfficer, 'monitoring_overdue_escalation')->count());
        $this->assertSame(0, $this->notifications($owner, 'monitoring_overdue_escalation')->count());
        $this->assertSame(1, $this->notifications($applicant, 'monitoring_window_closed')->count());

        Carbon::setTestNow('2026-11-07 08:15:00');
        $this->artisan('monitoring:send-reminders')->assertSuccessful();
        $this->artisan('monitoring:send-reminders')->assertSuccessful();

        $this->assertSame(2, $this->notifications($monitoringOfficer, 'monitoring_overdue_escalation')->count());
        $this->assertSame(1, $this->notifications($owner, 'monitoring_overdue_escalation')->count());
        $this->assertSame(1, $this->notifications($applicant, 'monitoring_window_closed')->count());

        $case = app(RecipientMonitoringCaseService::class)->sync($case->cycle, $case->application);
        app(RecipientMonitoringCaseService::class)->close(
            $case,
            $monitoringOfficer,
            RecipientSupportCatalog::OUTCOME_INCOMPLETE,
            'No records were received before the request window ended.',
        );
        $this->assertSame(0, PortalNotification::query()
            ->whereNull('read_at')
            ->where('deduplication_key', 'like', "monitoring-case:{$case->id}:%")
            ->count());

        Carbon::setTestNow('2026-11-14 08:15:00');
        $this->artisan('monitoring:send-reminders')->assertSuccessful();
        $this->assertSame(3, PortalNotification::query()
            ->where('type', 'monitoring_overdue_escalation')
            ->count());
    }

    private function monitoringCase(string $dueAt, int $gracePeriodDays = 0): array
    {
        $owner = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
            'permissions' => [],
        ]);
        $applicant = User::factory()->create([
            'role' => 'applicant',
            'account_status' => 'active',
        ]);
        $program = Scholarship::create([
            'provider_id' => $owner->id,
            'title' => 'Continuing Learner Grant',
            'description' => 'A recurring scholarship used to verify monitoring reminders.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'final_outcome' => 'selected',
            'student_response_status' => 'accepted',
            'student_responded_at' => now()->subMonth(),
            'submitted_at' => now()->subMonths(2),
        ]);
        $cycle = $program->monitoringCycles()->create([
            'created_by' => $owner->id,
            'title' => 'Semester progress check',
            'period_type' => 'semester',
            'opens_at' => now()->subMonth()->toDateString(),
            'due_at' => $dueAt,
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'grace_period_days' => $gracePeriodDays,
            'allow_exception_requests' => $gracePeriodDays > 0,
            'status' => 'open',
            'published_at' => now()->subMonth(),
        ]);
        $case = app(RecipientMonitoringCaseService::class)
            ->seedForCycle($cycle, collect([$application]))
            ->firstOrFail();

        return [$owner, $applicant, $program, $application, $case];
    }

    private function notifications(User $user, string $type)
    {
        return PortalNotification::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->get();
    }
}
