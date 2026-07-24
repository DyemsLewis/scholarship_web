<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_registration_returns_to_login_without_authenticating(): void
    {
        Mail::fake();
        Notification::fake();

        $response = $this->postJson('/register', [
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
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('redirect', '/login?registered=1&verification_sent=1');

        $this->assertGuest();
        $user = User::query()->where('email', 'new.applicant@example.test')->firstOrFail();
        $this->assertSame('applicant', $user->role);
        $this->assertNotNull($user->studentProfile);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_provider_registration_returns_to_login_without_authenticating(): void
    {
        Mail::fake();
        Notification::fake();

        $response = $this->postJson('/register', [
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
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('redirect', '/login?registered=1&verification_sent=1');

        $this->assertGuest();
        $user = User::query()->where('email', 'new.provider@example.test')->firstOrFail();
        $this->assertSame('provider', $user->role);
        $this->assertNotNull($user->providerProfile);
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
