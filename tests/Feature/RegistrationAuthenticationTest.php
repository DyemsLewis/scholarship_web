<?php

namespace Tests\Feature;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_account_is_created_only_after_email_code_verification(): void
    {
        Mail::fake();

        $registration = $this->postJson('/register', $this->applicantPayload());

        $registration
            ->assertStatus(202)
            ->assertJsonStructure(['registration_token', 'email', 'expires_in', 'resend_after']);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new.applicant@example.test']);
        $this->assertDatabaseHas('pending_registrations', [
            'email' => 'new.applicant@example.test',
            'role' => 'applicant',
        ]);

        $mail = $this->sentRegistrationMail('new.applicant@example.test');
        $verification = $this->postJson('/register/verify', [
            'registration_token' => $registration->json('registration_token'),
            'code' => $mail->verificationCode,
        ]);

        $verification
            ->assertCreated()
            ->assertJsonPath('redirect', '/login?registered=1&verified=1')
            ->assertJsonPath('email_verified', true);

        $this->assertGuest();
        $user = User::query()->where('email', 'new.applicant@example.test')->firstOrFail();
        $this->assertSame('applicant', $user->role);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNotNull($user->studentProfile);
        $this->assertDatabaseMissing('pending_registrations', ['email' => $user->email]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('redirect', '/dashboard');
    }

    public function test_provider_account_is_created_only_after_email_code_verification(): void
    {
        Mail::fake();

        $registration = $this->postJson('/register', $this->providerPayload());

        $registration->assertStatus(202);
        $this->assertDatabaseMissing('users', ['email' => 'new.provider@example.test']);

        $mail = $this->sentRegistrationMail('new.provider@example.test');
        $this->postJson('/register/verify', [
            'registration_token' => $registration->json('registration_token'),
            'code' => $mail->verificationCode,
        ])->assertCreated();

        $user = User::query()->where('email', 'new.provider@example.test')->firstOrFail();
        $this->assertSame('provider', $user->role);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertNotNull($user->providerProfile);
        $this->assertSame('Demo Scholarship Foundation', $user->providerProfile->provider_name);
    }

    public function test_incorrect_code_does_not_create_an_account(): void
    {
        Mail::fake();

        $registration = $this->postJson('/register', $this->applicantPayload());
        $mail = $this->sentRegistrationMail('new.applicant@example.test');
        $incorrectCode = $mail->verificationCode === '000000' ? '999999' : '000000';

        $this->postJson('/register/verify', [
            'registration_token' => $registration->json('registration_token'),
            'code' => $incorrectCode,
        ])->assertUnprocessable()->assertJsonPath('attempts_remaining', 4);

        $this->assertDatabaseMissing('users', ['email' => 'new.applicant@example.test']);
        $this->assertDatabaseHas('pending_registrations', [
            'email' => 'new.applicant@example.test',
            'attempts' => 1,
        ]);
    }

    public function test_expired_code_does_not_create_an_account(): void
    {
        Mail::fake();

        $registration = $this->postJson('/register', $this->applicantPayload());
        $mail = $this->sentRegistrationMail('new.applicant@example.test');
        PendingRegistration::query()->where('email', 'new.applicant@example.test')->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/register/verify', [
            'registration_token' => $registration->json('registration_token'),
            'code' => $mail->verificationCode,
        ])->assertUnprocessable()->assertJsonPath('restart_required', true);

        $this->assertDatabaseMissing('users', ['email' => 'new.applicant@example.test']);
        $this->assertDatabaseMissing('pending_registrations', ['email' => 'new.applicant@example.test']);
    }

    public function test_registration_code_can_be_resent_after_the_cooldown(): void
    {
        Mail::fake();

        $registration = $this->postJson('/register', $this->applicantPayload());
        PendingRegistration::query()->where('email', 'new.applicant@example.test')->update([
            'last_sent_at' => now()->subSeconds(PendingRegistration::RESEND_COOLDOWN_SECONDS + 1),
        ]);
        Mail::fake();

        $this->postJson('/register/resend-code', [
            'registration_token' => $registration->json('registration_token'),
        ])->assertOk()->assertJsonPath('resend_after', PendingRegistration::RESEND_COOLDOWN_SECONDS);

        $mail = $this->sentRegistrationMail('new.applicant@example.test');
        $this->assertDatabaseMissing('users', ['email' => 'new.applicant@example.test']);

        $this->postJson('/register/verify', [
            'registration_token' => $registration->json('registration_token'),
            'code' => $mail->verificationCode,
        ])->assertCreated();
    }

    private function sentRegistrationMail(string $email): RegistrationVerificationCodeMail
    {
        $sentMail = null;

        Mail::assertSent(
            RegistrationVerificationCodeMail::class,
            function (RegistrationVerificationCodeMail $mail) use ($email, &$sentMail): bool {
                $sentMail = $mail;

                return $mail->hasTo($email);
            },
        );

        $this->assertInstanceOf(RegistrationVerificationCodeMail::class, $sentMail);

        return $sentMail;
    }

    private function applicantPayload(): array
    {
        return [
            'first_name' => 'Demo',
            'last_name' => 'Applicant',
            'middle_initial' => 'A',
            'email' => 'new.applicant@example.test',
            'username' => 'new_applicant',
            'number' => '09171234567',
            'role' => 'applicant',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ];
    }

    private function providerPayload(): array
    {
        return [
            'first_name' => 'Demo',
            'last_name' => 'Provider',
            'middle_initial' => 'P',
            'email' => 'new.provider@example.test',
            'username' => 'new_provider',
            'number' => '09179876543',
            'role' => 'provider',
            'provider_name' => 'Demo Scholarship Foundation',
            'provider_type' => 'foundation',
            'provider_website' => 'https://example.test',
            'provider_address' => 'Antipolo City, Rizal',
            'provider_description' => 'A provider account used to verify registration behavior.',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ];
    }
}
