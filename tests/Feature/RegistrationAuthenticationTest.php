<?php

namespace Tests\Feature;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_log_in_with_a_unique_username(): void
    {
        $user = User::factory()->create([
            'username' => 'student.login',
            'password' => 'password123',
        ]);

        $this->postJson('/login', [
            'login' => $user->username,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('redirect', '/dashboard');

        $this->assertAuthenticatedAs($user);
    }

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
        $this->assertSame('Demo', $user->providerProfile->first_name);
        $this->assertSame('Provider', $user->providerProfile->last_name);
        $this->assertNull($user->providerProfile->provider_name);
        $this->assertNull($user->providerProfile->provider_type);
        $this->assertNull($user->providerProfile->provider_address);
        $this->assertNull($user->providerProfile->provider_contact_email);
        $this->assertNull($user->providerProfile->provider_contact_number);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()->assertJsonPath('redirect', '/provider');
    }

    #[DataProvider('middleInitialCases')]
    public function test_registration_accepts_an_optional_middle_initial_through_email_verification(array $initialPayload, ?string $expectedInitial): void
    {
        Mail::fake();

        foreach ([$this->applicantPayload(), $this->providerPayload()] as $payload) {
            unset($payload['middle_initial']);
            $payload = array_merge($payload, $initialPayload);

            $registration = $this->postJson('/register', $payload)->assertStatus(202);
            $mail = $this->sentRegistrationMail($payload['email']);

            $this->postJson('/register/verify', [
                'registration_token' => $registration->json('registration_token'),
                'code' => $mail->verificationCode,
            ])->assertCreated();

            $user = User::query()->where('email', $payload['email'])->firstOrFail();
            $profile = $user->isProvider() ? $user->providerProfile : $user->studentProfile;
            $this->assertNotNull($profile);
            $this->assertSame($expectedInitial, $profile->middle_initial);
            $this->assertSame(
                implode(' ', array_filter(['Demo', $expectedInitial ? $expectedInitial.'.' : null, $payload['last_name']])),
                $user->name,
            );
        }
    }

    #[DataProvider('middleInitialCases')]
    public function test_mobile_registration_accepts_an_optional_middle_initial(array $initialPayload, ?string $expectedInitial): void
    {
        $payload = $this->applicantPayload();
        unset($payload['middle_initial']);
        $payload['contact_number'] = $payload['number'];

        $this->postJson('/api/mobile/register', array_merge($payload, $initialPayload))
            ->assertCreated();

        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertSame($expectedInitial, $user->studentProfile->middle_initial);
    }

    public function test_registration_rejects_invalid_middle_initials_when_provided(): void
    {
        Mail::fake();

        foreach ([$this->applicantPayload(), $this->providerPayload()] as $payload) {
            $this->postJson('/register', array_merge($payload, ['middle_initial' => 'AB']))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('middle_initial');

            $this->postJson('/register', array_merge($payload, ['middle_initial' => '1']))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('middle_initial');
        }

        Mail::assertNothingSent();
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public static function middleInitialCases(): array
    {
        return [
            'omitted' => [[], null],
            'blank' => [['middle_initial' => ''], null],
            'null' => [['middle_initial' => null], null],
            'lowercase' => [['middle_initial' => 'q'], 'Q'],
        ];
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

    #[DataProvider('registrationRoles')]
    public function test_abandoned_registration_can_restart_with_the_same_email_and_username(string $role): void
    {
        Mail::fake();
        $payload = $role === 'provider' ? $this->providerPayload() : $this->applicantPayload();
        $first = $this->postJson('/register', $payload)->assertStatus(202);
        $second = $this->postJson('/register', $payload)->assertStatus(202);
        $this->assertNotSame($first->json('registration_token'), $second->json('registration_token'));
        $this->assertSame(1, PendingRegistration::query()->where('email', $payload['email'])->count());
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
        $this->postJson('/register/verify', ['registration_token' => $first->json('registration_token'), 'code' => '000000'])
            ->assertUnprocessable()->assertJsonPath('restart_required', true);
        $mail = null;
        Mail::assertSent(RegistrationVerificationCodeMail::class, function ($sent) use ($payload, &$mail) {
            if ($sent->hasTo($payload['email'])) {
                $mail = $sent;
            }

            return $sent->hasTo($payload['email']);
        });
        $this->postJson('/register/verify', ['registration_token' => $second->json('registration_token'), 'code' => $mail->verificationCode])->assertCreated();
    }

    public static function registrationRoles(): array
    {
        return ['applicant' => ['applicant'], 'provider' => ['provider']];
    }

    public function test_existing_unverified_accounts_can_finish_verification_without_replacing_the_account(): void
    {
        Mail::fake();
        foreach ([$this->applicantPayload(), $this->providerPayload()] as $payload) {
            $user = User::factory()->unverified()->create(['email' => $payload['email'], 'username' => $payload['username'], 'role' => $payload['role'], 'password' => 'password123']);
            $password = $user->password;
            $profile = $user->isProvider() ? $user->providerProfile : $user->studentProfile;
            $profile->update(['first_name' => 'Original', 'last_name' => 'Owner']);
            $registration = $this->postJson('/register', array_merge($payload, ['first_name' => 'Different']))
                ->assertStatus(202)->assertJsonPath('resuming_account', true);
            $mail = $this->sentRegistrationMail($payload['email']);
            $this->assertTrue($mail->resumingAccount);
            $this->assertFalse($user->fresh()->hasVerifiedEmail());
            $this->postJson('/register/verify', ['registration_token' => $registration->json('registration_token'), 'code' => $mail->verificationCode])
                ->assertOk()->assertJsonPath('redirect', '/login?verified=1');
            $this->assertTrue($user->fresh()->hasVerifiedEmail());
            $this->assertSame($password, $user->fresh()->password);
            $this->assertSame('Original', $profile->fresh()->first_name);
            $this->assertSame(1, User::query()->where('email', $payload['email'])->count());
            $this->assertGuest();
        }
    }

    public function test_unverified_existing_account_requires_its_original_password(): void
    {
        Mail::fake();
        $payload = $this->applicantPayload();
        $user = User::factory()->unverified()->create(['email' => $payload['email'], 'username' => $payload['username'], 'password' => 'original-password']);
        $this->postJson('/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('pending_registrations', 0);
        Mail::assertNothingSent();
    }

    public function test_verified_email_is_protected_even_with_different_casing(): void
    {
        Mail::fake();
        $payload = $this->applicantPayload();
        $user = User::factory()->create(['email' => strtoupper($payload['email']), 'username' => $payload['username'], 'password' => 'password123']);
        $this->postJson('/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertDatabaseCount('pending_registrations', 0);
        Mail::assertNothingSent();
    }

    public function test_suspended_managed_and_different_role_accounts_cannot_be_resumed_through_registration(): void
    {
        Mail::fake();
        $parent = User::factory()->create(['role' => 'provider']);
        foreach ([['account_status' => 'suspended'], ['parent_account_id' => $parent->id], ['role' => 'admin'], ['must_reset_password' => true], ['role' => 'provider']] as $index => $changes) {
            $payload = array_merge($this->applicantPayload(), ['email' => "protected{$index}@example.test", 'username' => "protected{$index}"]);
            User::factory()->unverified()->create(array_merge(['email' => $payload['email'], 'username' => $payload['username'], 'password' => 'password123'], $changes));
            $this->postJson('/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->assertDatabaseCount('pending_registrations', 0);
        Mail::assertNothingSent();
    }

    public function test_a_resumed_verification_cannot_be_used_after_the_password_changes(): void
    {
        Mail::fake();
        $payload = $this->applicantPayload();
        $user = User::factory()->unverified()->create(['email' => $payload['email'], 'username' => $payload['username'], 'password' => 'password123']);
        $registration = $this->postJson('/register', $payload)->assertStatus(202);
        $mail = $this->sentRegistrationMail($payload['email']);
        $user->update(['password' => 'replacement-password']);
        $this->postJson('/register/verify', ['registration_token' => $registration->json('registration_token'), 'code' => $mail->verificationCode])
            ->assertUnprocessable()->assertJsonPath('restart_required', true);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_expired_pending_registration_does_not_reserve_the_email(): void
    {
        Mail::fake();
        $payload = $this->applicantPayload();
        $this->postJson('/register', $payload)->assertStatus(202);
        PendingRegistration::query()->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/register', $payload)->assertStatus(202);
        $this->assertDatabaseCount('pending_registrations', 1);
        $this->assertDatabaseMissing('users', ['email' => $payload['email']]);
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
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ];
    }
}
