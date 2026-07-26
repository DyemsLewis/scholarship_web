<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class RegistrationVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $verificationCode,
        public readonly int $expiresInMinutes,
    ) {}

    public function build(): self
    {
        return $this
            ->subject('Your Scholarship Portal verification code')
            ->text('emails.registration-verification-code');
    }
}
