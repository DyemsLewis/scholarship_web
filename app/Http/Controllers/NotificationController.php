<?php

namespace App\Http\Controllers;

use App\Models\PortalNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user, 403);

        if (! $user->hasVerifiedEmail()) {
            PortalNotification::updateOrCreate([
                'user_id' => $user->id,
                'type' => 'email_verification',
                'title' => 'Verify your email address',
            ], [
                'message' => 'Please verify your email address to help secure your account and receive portal updates.',
                'action_url' => null,
            ]);
        } else {
            PortalNotification::query()
                ->where('user_id', $user->id)
                ->where('type', 'email_verification')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return response()->json([
            'notifications' => $this->latestNotifications($user->id),
            'unread_count' => $this->unreadCount($user->id),
            'email_verified' => $user->hasVerifiedEmail(),
        ]);
    }

    public function markRead(Request $request, PortalNotification $notification): JsonResponse
    {
        abort_unless($request->user() && $notification->user_id === $request->user()->id, 403);

        if ($notification->read_at === null) {
            $notification->markRead();
        }

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => $this->notificationPayload($notification->fresh()),
            'unread_count' => $this->unreadCount($request->user()->id),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user, 403);

        PortalNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'notifications' => $this->latestNotifications($user->id),
            'unread_count' => 0,
        ]);
    }

    private function latestNotifications(int $userId): Collection
    {
        return PortalNotification::query()
            ->where('user_id', $userId)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (PortalNotification $notification) => $this->notificationPayload($notification))
            ->values();
    }

    private function notificationPayload(PortalNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'action_url' => $notification->action_url,
            'is_read' => $notification->read_at !== null,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }

    private function unreadCount(int $userId): int
    {
        return PortalNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
