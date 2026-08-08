<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DemoAccountAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_demo_account_can_use_its_own_portal(): void
    {
        Mail::fake();
        $this->seed();

        $defaultPassword = env('DEMO_PASSWORD', 'password123');

        $this->loginAs(
            email: env('ADMIN_EMAIL', 'admin@scholarship.test'),
            password: env('ADMIN_PASSWORD', $defaultPassword),
            role: 'admin',
            redirect: '/admin',
        );

        $this->get('/admin')->assertOk();
        $this->getJson('/admin/dashboard/data')->assertOk();
        $this->get('/admin/manage-users')->assertOk();
        $this->get('/admin/accounts/create')->assertOk();
        $this->get('/admin/reviews')->assertOk();
        $this->get('/admin/logs')->assertOk();
        $this->get('/admin/profile')->assertOk();
        $this->getJson('/admin/users')->assertOk();
        $this->getJson('/admin/reviews/data')->assertOk();
        $this->getJson('/admin/profile/data')->assertOk()->assertJsonPath('user.role', 'admin');
        $this->getJson('/notifications')->assertOk();
        $this->get('/provider')->assertForbidden();
        $this->get('/dashboard')->assertRedirect('/admin');
        $this->getJson('/dashboard/data')->assertForbidden();
        $this->logoutCurrentUser();

        $providerAccounts = [
            [
                'email' => env('TULAY_ARAL_EMAIL', 'tulayaral@scholarship.test'),
                'password' => env('TULAY_ARAL_PASSWORD', $defaultPassword),
            ],
            [
                'email' => env('BUKAS_FOUNDATION_EMAIL', 'bukasfoundation@scholarship.test'),
                'password' => env('BUKAS_FOUNDATION_PASSWORD', $defaultPassword),
            ],
        ];

        foreach ($providerAccounts as $account) {
            $provider = $this->loginAs(
                email: $account['email'],
                password: $account['password'],
                role: 'provider',
                redirect: '/provider',
            );

            $ownPrograms = Scholarship::query()
                ->where('provider_id', $provider->id)
                ->orderBy('id')
                ->get();
            $otherProgram = Scholarship::query()
                ->where('provider_id', '!=', $provider->id)
                ->firstOrFail();

            $this->assertCount(2, $ownPrograms);
            $this->get('/provider')->assertOk();
            $this->get('/provider/programs')->assertOk();
            $this->get('/provider/programs/create')->assertOk();
            $this->get('/provider/applications')->assertOk();
            $this->get('/provider/review')->assertRedirect('/provider/applications?filter=pending_review');
            $this->get('/provider/profile')->assertOk();
            $this->getJson('/provider/dashboard/data')
                ->assertOk()
                ->assertJsonPath('user.role', 'provider')
                ->assertJsonCount(2, 'scholarships');
            $this->getJson('/provider/scholarships')->assertOk()->assertJsonCount(2, 'scholarships');
            $this->getJson('/provider/applications/data')->assertOk();
            $this->getJson('/provider/insights/data')->assertOk();
            $this->getJson('/provider/profile/data')->assertOk()->assertJsonPath('user.role', 'provider');
            $this->getJson('/notifications')->assertOk();

            foreach ($ownPrograms as $program) {
                $this->get("/provider/programs/{$program->id}/edit")->assertOk();
                $this->get("/provider/programs/{$program->id}/applications")->assertOk();
                $this->getJson("/provider/scholarships/{$program->id}")
                    ->assertOk()
                    ->assertJsonPath('scholarship.id', $program->id);
            }

            $this->getJson("/provider/scholarships/{$otherProgram->id}")->assertForbidden();
            $this->get('/admin')->assertForbidden();
            $this->get('/dashboard')->assertRedirect('/provider');
            $this->getJson('/dashboard/data')->assertForbidden();
            $this->logoutCurrentUser();
        }

        $applicant = $this->loginAs(
            email: env('STUDENT_EMAIL', 'student@scholarship.test'),
            password: env('STUDENT_PASSWORD', $defaultPassword),
            role: 'applicant',
            redirect: '/dashboard',
        );
        $publishedProgram = Scholarship::query()->where('status', 'published')->firstOrFail();

        $this->assertTrue($applicant->hasCompleteApplicantProfile());
        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard/scholarships')->assertOk();
        $this->get('/dashboard/applications')->assertOk();
        $this->get('/dashboard/documents')->assertOk();
        $this->get('/dashboard/profile')->assertOk();
        $this->getJson('/dashboard/data')->assertOk()->assertJsonPath('user.role', 'applicant');
        $this->getJson('/dashboard/applications/data')->assertOk();
        $this->getJson('/dashboard/documents/data')->assertOk();
        $this->getJson('/dashboard/profile/data')->assertOk()->assertJsonPath('user.role', 'applicant');
        $this->get("/dashboard/scholarships/{$publishedProgram->id}")->assertOk();
        $this->getJson("/dashboard/scholarships/{$publishedProgram->id}/data")
            ->assertOk()
            ->assertJsonPath('scholarship.id', $publishedProgram->id);
        $this->getJson('/notifications')->assertOk();
        $this->get('/admin')->assertForbidden();
        $this->get('/provider')->assertForbidden();
        $this->logoutCurrentUser();
    }

    private function loginAs(string $email, string $password, string $role, string $redirect): User
    {
        $user = User::query()->where('email', $email)->firstOrFail();

        $this->postJson('/login', [
            'email' => $email,
            'password' => $password,
            'remember' => false,
        ])
            ->assertOk()
            ->assertJsonPath('redirect', $redirect)
            ->assertJsonPath('email_verified', true)
            ->assertJsonPath('user.role', $role);

        $this->assertAuthenticatedAs($user);

        return $user;
    }

    private function logoutCurrentUser(): void
    {
        $this->postJson('/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertGuest();
    }
}
