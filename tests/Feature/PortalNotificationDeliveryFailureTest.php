<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\User;
use App\Observers\PortalNotificationObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class PortalNotificationDeliveryFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_queue_failure_is_recorded_without_losing_the_web_notification(): void
    {
        $user = User::factory()->create(['role' => 'applicant']);
        $notification = PortalNotification::withoutEvents(fn () => PortalNotification::create([
            'user_id' => $user->id,
            'type' => 'application_update',
            'title' => 'Application updated',
            'message' => 'Your application has a new update.',
            'action_url' => '/dashboard/applications',
        ]));

        Mail::shouldReceive('to')
            ->once()
            ->with($user->email)
            ->andThrow(new RuntimeException('Mail transport unavailable'));

        app(PortalNotificationObserver::class)->created($notification);

        $this->assertDatabaseHas('portal_notifications', [
            'id' => $notification->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'portal_notification_email_failed',
        ]);
    }
}
