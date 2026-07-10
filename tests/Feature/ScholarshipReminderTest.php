<?php

namespace Tests\Feature;

use App\Mail\PortalNotificationMail;
use App\Models\PortalNotification;
use App\Models\Scholarship;
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
}
