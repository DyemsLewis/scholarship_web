<?php

namespace Tests\Feature;

use App\Mail\PortalNotificationMail;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RolePermissionAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_admin_can_create_an_admin_with_limited_permissions(): void
    {
        Mail::fake();
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/admin/users', [
            'first_name' => 'Review',
            'last_name' => 'Officer',
            'middle_initial' => 'A',
            'email' => 'review.officer@example.test',
            'username' => 'review.officer',
            'contact_number' => '09171234567',
            'role' => 'admin',
            'account_title' => 'Review officer',
            'permissions' => ['manage_reviews', 'manage_accounts'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $staff = User::query()->findOrFail($response->json('user.id'));

        $this->assertSame($admin->id, $staff->parent_account_id);
        $this->assertSame(['manage_reviews'], $staff->permissions);
        $this->assertFalse($staff->hasVerifiedEmail());
        $this->assertTrue($staff->must_reset_password);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $staff->id,
            'type' => 'staff_account_created',
            'action_url' => '/login',
        ]);
        Mail::assertQueued(PortalNotificationMail::class, fn (PortalNotificationMail $mail) => (
            $mail->hasTo($staff->email)
            && str_contains($mail->notificationMessage, 'temporary password')
        ));
        Notification::assertSentTo($staff, VerifyEmail::class);

        $staff->forceFill([
            'email_verified_at' => now(),
            'must_reset_password' => false,
            'password_reset_required_at' => null,
        ])->save();

        $this->actingAs($staff)->get('/admin/reviews')->assertOk();
        $this->actingAs($staff)->get('/admin/manage-users')->assertForbidden();
        $this->actingAs($staff)->get('/admin/logs')->assertForbidden();
    }

    public function test_delegated_admin_cannot_create_or_manage_an_admin_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $admin->id,
            'account_title' => 'Account officer',
            'permissions' => ['manage_accounts'],
        ]);

        $this->actingAs($staff)->postJson('/admin/users', [
            'first_name' => 'Another',
            'last_name' => 'Admin',
            'middle_initial' => 'B',
            'email' => 'another.admin@example.test',
            'username' => 'another.admin',
            'contact_number' => '09171234568',
            'role' => 'admin',
            'account_title' => 'Administrator',
            'permissions' => ['manage_accounts'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertForbidden();

        $this->actingAs($staff)
            ->getJson("/admin/users/{$admin->id}")
            ->assertForbidden();
    }

    public function test_provider_team_account_uses_organization_programs_and_enforced_permissions(): void
    {
        Mail::fake();
        Notification::fake();
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $response = $this->actingAs($provider)->postJson('/provider/team/accounts', [
            'first_name' => 'Paolo',
            'last_name' => 'Coordinator',
            'middle_initial' => 'C',
            'email' => 'paolo.coordinator@example.test',
            'username' => 'paolo.coordinator',
            'contact_number' => '09171234569',
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs', 'manage_reports'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $staff = User::query()->findOrFail($response->json('account.id'));

        $this->assertSame($provider->id, $staff->parent_account_id);
        $this->assertSame(['manage_programs'], $staff->permissions);
        $this->assertFalse($staff->hasVerifiedEmail());
        $this->assertTrue($staff->must_reset_password);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $staff->id,
            'type' => 'staff_account_created',
            'action_url' => '/login',
        ]);
        Mail::assertQueued(PortalNotificationMail::class, fn (PortalNotificationMail $mail) => (
            $mail->hasTo($staff->email)
            && str_contains($mail->notificationMessage, 'temporary password')
        ));
        Notification::assertSentTo($staff, VerifyEmail::class);

        $staff->forceFill([
            'email_verified_at' => now(),
            'must_reset_password' => false,
            'password_reset_required_at' => null,
        ])->save();

        $programResponse = $this->actingAs($staff)->postJson('/provider/scholarships', [
            'title' => 'Team Managed Draft',
            'status' => 'draft',
        ])->assertCreated();
        $programId = $programResponse->json('scholarship.id');

        $this->assertDatabaseHas('scholarships', [
            'id' => $programId,
            'provider_id' => $provider->id,
        ]);

        $applicant = User::factory()->create(['role' => 'applicant']);
        ScholarshipApplication::create([
            'scholarship_id' => $programId,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'workflow_stage' => 'screening',
            'application_state' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($staff)
            ->getJson("/provider/scholarships/{$programId}")
            ->assertOk()
            ->assertJsonPath('scholarship.workflow_counts.needs_review', 0)
            ->assertJsonPath('scholarship.workflow_counts.all', 0)
            ->assertJsonCount(0, 'scholarship.activity_statuses');

        $this->actingAs($staff)->get('/provider/applications')->assertForbidden();
        $this->actingAs($staff)->get('/provider/reports')->assertForbidden();
    }

    public function test_new_provider_team_member_verifies_email_before_replacing_temporary_password(): void
    {
        Mail::fake();
        Notification::fake();

        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $response = $this->actingAs($provider)->postJson('/provider/team/accounts', [
            'first_name' => 'New',
            'last_name' => 'Reviewer',
            'middle_initial' => 'T',
            'email' => 'new.reviewer@example.test',
            'username' => 'new.reviewer',
            'contact_number' => '09171234559',
            'account_title' => 'application_reviewer',
            'permissions' => ['review_applications'],
            'password' => 'temporary123',
            'password_confirmation' => 'temporary123',
        ])->assertCreated();

        $staff = User::query()->findOrFail($response->json('account.id'));
        $this->postJson('/logout')->assertOk();

        $this->postJson('/login', [
            'email' => $staff->email,
            'password' => 'temporary123',
        ])->assertOk()
            ->assertJsonPath('redirect', '/account/setup')
            ->assertJsonPath('email_verified', false);

        $this->get('/provider')->assertRedirect('/account/setup');
        $this->getJson('/account/setup/data')
            ->assertOk()
            ->assertJsonPath('step', 'verification');

        $this->postJson('/account/setup/password', [
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertUnprocessable();

        $verificationUrl = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $staff->id,
            'hash' => sha1($staff->email),
        ]);

        $this->get($verificationUrl)->assertRedirect('/account/setup?verified=1');
        $this->assertTrue($staff->fresh()->hasVerifiedEmail());

        $this->postJson('/account/setup/password', [
            'password' => 'temporary123',
            'password_confirmation' => 'temporary123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/account/setup/password', [
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk()
            ->assertJsonPath('redirect', '/provider');

        $staff->refresh();
        $this->assertTrue($staff->hasVerifiedEmail());
        $this->assertFalse($staff->must_reset_password);
        $this->get('/provider')->assertOk();
    }

    public function test_provider_staff_can_update_personal_credentials_without_editing_organization_details(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update([
            'provider_name' => 'Original Foundation',
            'provider_address' => 'Original address',
        ]);
        $staff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs'],
        ]);

        $this->actingAs($staff)->patchJson('/provider/profile', [
            'first_name' => 'Updated',
            'last_name' => 'Coordinator',
            'middle_initial' => 'C',
            'email' => 'updated.coordinator@example.test',
            'username' => 'updated.coordinator',
            'contact_number' => '09171234561',
            'provider_name' => 'Unauthorized Change',
            'provider_address' => 'Unauthorized address',
        ])->assertOk()
            ->assertJsonPath('user.email', 'updated.coordinator@example.test')
            ->assertJsonPath('user.username', 'updated.coordinator');

        $this->assertSame('Original Foundation', $provider->fresh()->providerProfile->provider_name);
        $this->assertSame('Original address', $provider->fresh()->providerProfile->provider_address);
    }

    public function test_provider_can_create_a_custom_role_with_selected_permissions(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $response = $this->actingAs($provider)->postJson('/provider/team/accounts', [
            'first_name' => 'Custom',
            'last_name' => 'Staff',
            'middle_initial' => 'R',
            'email' => 'custom.staff@example.test',
            'username' => 'custom.staff',
            'contact_number' => '09171234560',
            'account_title' => 'custom',
            'permissions' => ['manage_programs', 'manage_reports'],
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $staff = User::query()->findOrFail($response->json('account.id'));

        $this->assertSame('custom', $staff->account_title);
        $this->assertSame(['manage_programs', 'manage_reports'], $staff->permissions);
        $this->assertSame('Custom role', $response->json('account.team_role_label'));
    }

    public function test_provider_team_account_cannot_access_another_provider_organization(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $staff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['review_applications'],
        ]);
        $otherProgram = Scholarship::create([
            'provider_id' => $otherProvider->id,
            'title' => 'Other Provider Program',
            'description' => 'This program belongs to another provider organization.',
            'status' => 'draft',
        ]);

        $this->actingAs($staff)
            ->getJson("/provider/scholarships/{$otherProgram->id}")
            ->assertForbidden();

        $this->actingAs($staff)
            ->getJson('/provider/scholarships')
            ->assertOk()
            ->assertJsonCount(0, 'scholarships');
    }

    public function test_managed_accounts_stop_when_their_primary_account_is_suspended(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $adminStaff = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $admin->id,
            'permissions' => ['manage_reviews'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $providerStaff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'permissions' => ['manage_programs'],
        ]);

        $admin->forceFill(['account_status' => 'suspended'])->save();
        $provider->forceFill(['account_status' => 'suspended'])->save();

        $this->actingAs($adminStaff)->get('/admin/reviews')->assertForbidden();
        $this->actingAs($providerStaff)->get('/provider/programs')->assertForbidden();
    }
}
