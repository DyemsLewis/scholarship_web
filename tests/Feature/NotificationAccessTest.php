<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_mark_own_notification_read_and_unread_count_is_updated(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $notification = PortalNotification::create([
            'user_id' => $user->id,
            'type' => 'application_update',
            'title' => 'Application updated',
            'message' => 'Your application moved to review.',
        ]);

        $this->actingAs($user)
            ->patchJson("/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('notification.is_read', true);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_user_cannot_mark_another_users_notification_read(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $notification = PortalNotification::create([
            'user_id' => $owner->id,
            'type' => 'application_update',
            'title' => 'Private update',
            'message' => 'Only the owner should access this.',
        ]);

        $this->actingAs($otherUser)
            ->patchJson("/notifications/{$notification->id}/read")
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }
}
