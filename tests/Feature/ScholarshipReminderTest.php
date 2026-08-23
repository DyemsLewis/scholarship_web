<?php

namespace Tests\Feature;

use App\Mail\PortalNotificationMail;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBookmark;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScholarshipReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_deadline_reminders_are_created_once_for_applicant_and_provider(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-07-11 08:00:00');
        $applicant = User::factory()->create();
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Deadline Test Scholarship',
            'description' => 'A scholarship with a near deadline.',
            'status' => 'published',
            'deadline' => now()->addDays(7)->toDateString(),
        ]);
        ScholarshipBookmark::create([
            'scholarship_id' => $scholarship->id,
            'user_id' => $applicant->id,
        ]);

        $this->artisan('scholarships:send-reminders')->assertSuccessful();
        $this->artisan('scholarships:send-reminders')->assertSuccessful();

        $this->assertSame(2, PortalNotification::where('type', 'deadline_reminder')->count());
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id]);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $provider->id]);
        Mail::assertQueued(PortalNotificationMail::class, 2);
    }

    public function test_qualified_applicant_receives_one_formal_application_deadline_reminder(): void
    {
        Mail::fake();
        Carbon::setTestNow('2026-07-11 08:00:00');
        $applicant = User::factory()->create();
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Formal Application Test Scholarship',
            'description' => 'A scholarship with provider handoff instructions.',
            'status' => 'published',
            'handoff_deadline' => now()->addDays(3)->toDateString(),
            'handoff_instructions' => 'Submit the requested originals to the provider office.',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'approved',
            'submitted_at' => now()->subDay(),
        ]);

        $this->artisan('scholarships:send-reminders')->assertSuccessful();
        $this->artisan('scholarships:send-reminders')->assertSuccessful();

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'formal_application_reminder',
            'deduplication_key' => "formal-application:{$application->id}:applicant:3",
        ]);
        $this->assertSame(1, PortalNotification::where('type', 'formal_application_reminder')->count());
        Mail::assertQueued(PortalNotificationMail::class, 1);
    }
}
