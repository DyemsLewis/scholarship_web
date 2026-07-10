<?php

namespace App\Observers;

use App\Mail\PortalNotificationMail;
use App\Models\ActivityLog;
use App\Models\PortalNotification;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PortalNotificationObserver
{
    public function created(PortalNotification $notification): void
    {
        $notification->loadMissing('user');
        $user = $notification->user;

        if (! $user?->email || ! $this->shouldSendEmail($notification)) {
            return;
        }

        $actionUrl = $this->actionUrl($notification->action_url);

        try {
            Mail::to($user->email)->queue(new PortalNotificationMail(
                $user->name,
                $notification->title,
                $notification->message,
                $actionUrl,
            ));
        } catch (Throwable $error) {
            ActivityLog::record(
                $user,
                'portal_notification_email_failed',
                "Portal notification email could not be sent to {$user->email}.",
                null,
                [
                    'notification_id' => $notification->id,
                    'notification_type' => $notification->type,
                    'error' => $error->getMessage(),
                ],
            );
        }
    }

    private function shouldSendEmail(PortalNotification $notification): bool
    {
        return ! in_array($notification->type, [
            'email_verification',
        ], true);
    }

    private function actionUrl(?string $actionUrl): ?string
    {
        if (! filled($actionUrl)) {
            return null;
        }

        if (str_starts_with($actionUrl, 'http://') || str_starts_with($actionUrl, 'https://')) {
            return $actionUrl;
        }

        return url($actionUrl);
    }

}
