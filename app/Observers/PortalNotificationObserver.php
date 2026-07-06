<?php

namespace App\Observers;

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

        $subject = "Scholarship Portal: {$notification->title}";
        $actionUrl = $this->actionUrl($notification->action_url);
        $body = $this->emailBody($notification, $actionUrl);

        try {
            Mail::raw($body, fn ($message) => $message
                ->to($user->email)
                ->subject($subject));
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

    private function emailBody(PortalNotification $notification, ?string $actionUrl): string
    {
        $lines = [
            "Hello {$notification->user->name},",
            '',
            $notification->title,
            '',
            $notification->message,
        ];

        if ($actionUrl) {
            $lines[] = '';
            $lines[] = "Open this update: {$actionUrl}";
        }

        $lines[] = '';
        $lines[] = 'You are receiving this because your Scholarship Portal account has a new notification.';

        return implode("\n", $lines);
    }
}
