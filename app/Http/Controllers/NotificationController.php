<?php

namespace App\Http\Controllers;

use App\Models\PortalNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'read_at' => null,
            ]);
        } else {
            PortalNotification::query()
                ->where('user_id', $user->id)
                ->where('type', 'email_verification')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $notifications = PortalNotification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (PortalNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'action_url' => $notification->action_url,
                'is_read' => $notification->read_at !== null,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => PortalNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, PortalNotification $notification): JsonResponse
    {
        abort_unless($request->user() && $notification->user_id === $request->user()->id, 403);

        $notification->markRead();

        return response()->json([
            'message' => 'Notification marked as read.',
            'unread_count' => PortalNotification::query()
                ->where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }
}
