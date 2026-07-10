<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PortalNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $notificationTitle,
        public readonly string $notificationMessage,
        public readonly ?string $notificationActionUrl = null,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject("Scholarship Portal: {$this->notificationTitle}")
            ->text('emails.portal-notification');
    }
}
