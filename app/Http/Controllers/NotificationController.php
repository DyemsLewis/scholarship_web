<?php

namespace App\Http\Controllers;

use App\Models\PortalNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function markRead(Request $request, PortalNotification $notification): JsonResponse
    {
        abort_unless($request->user() && $notification->user_id === $request->user()->id, 403);

        $notification->markRead();

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }
}
