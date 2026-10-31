<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminAccountRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_primary_admin_can_create_each_role_without_inventing_a_middle_initial(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['applicant', 'provider', 'admin'] as $role) {
            $payload = $this->payload($role);
            unset($payload['middle_initial']);
            $response = $this->actingAs($admin)->postJson('/admin/users', $payload)
                ->assertCreated()
                ->assertJsonPath('user.middle_initial', null)
                ->assertJsonPath('user.name', 'Mira Cruz');

            $created = User::findOrFail($response->json('user.id'));
            $this->assertNull($created->middle_initial);
            if ($role === 'admin') {
                $this->assertTrue($created->must_reset_password);
                $this->assertNull($created->email_verified_at);
                $this->assertSame($admin->id, $created->parent_account_id);
                $this->assertSame(['manage_accounts'], $created->permissions);
            }
        }
    }

    public function test_blank_initials_can_be_cleared_when_editing_every_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['applicant', 'provider', 'admin'] as $role) {
            $target = User::factory()->create([
                'role' => $role,
                'parent_account_id' => $role === 'admin' ? $admin->id : null,
                'account_title' => $role === 'admin' ? 'Account manager' : null,
                'permissions' => $role === 'admin' ? ['manage_accounts'] : null,
            ]);
            $payload = $this->payload($role);
            $expectedName = $role === 'provider' ? $target->name : 'Mira Cruz';
            unset($payload['password'], $payload['password_confirmation']);
            $payload['middle_initial'] = '';

            $this->actingAs($admin)->patchJson("/admin/users/{$target->id}", $payload)
                ->assertOk()
                ->assertJsonPath('user.middle_initial', null)
                ->assertJsonPath('user.first_name', 'Mira')
                ->assertJsonPath('user.name', $expectedName);
            $this->assertNull($target->fresh()->middle_initial);
        }
    }

    public function test_provided_initials_still_normalize_to_uppercase_and_form_a_clean_name(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson('/admin/users', [
            ...$this->payload('applicant'),
            'middle_initial' => 'q',
        ])->assertCreated()
            ->assertJsonPath('user.middle_initial', 'Q')
            ->assertJsonPath('user.name', 'Mira Q. Cruz');
    }

    public function test_admins_can_update_their_own_profile_without_a_middle_initial(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'first_name' => 'Mira', 'last_name' => 'Cruz', 'display_name' => 'Mira Cruz',
            'email' => $admin->email, 'username' => $admin->username, 'contact_number' => '09175550111',
        ];
        $this->actingAs($admin)->patchJson('/admin/profile', $payload)
            ->assertOk()->assertJsonPath('user.middle_initial', null);
        $this->assertNull($admin->fresh()->adminProfile->middle_initial);

        $this->patchJson('/admin/profile', [...$payload, 'middle_initial' => 'ZZ'])
            ->assertUnprocessable()->assertJsonValidationErrors('middle_initial');
    }

    public function test_optional_does_not_allow_invalid_initials(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['AB', '4', '-'] as $initial) {
            $this->actingAs($admin)->postJson('/admin/users', [
                ...$this->payload('applicant'), 'middle_initial' => $initial,
            ])->assertUnprocessable()->assertJsonValidationErrors('middle_initial');
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_account_manager_can_create_no_initial_accounts_but_cannot_manage_admin_access(): void
    {
        $primary = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create([
            'role' => 'admin', 'parent_account_id' => $primary->id,
            'account_title' => 'Account manager', 'permissions' => ['manage_accounts'],
        ]);
        foreach (['applicant', 'provider'] as $role) {
            $this->actingAs($manager)->postJson('/admin/users', $this->payload($role))
                ->assertCreated()->assertJsonPath('user.middle_initial', null);
        }

        $this->postJson('/admin/users', $this->payload('admin'))->assertForbidden();
        $this->patchJson("/admin/users/{$primary->id}", $this->payload('admin'))->assertForbidden();
        $this->getJson("/admin/workspaces/accounts/{$primary->id}/data")->assertForbidden();
    }

    public function test_optional_initials_do_not_allow_changing_an_existing_portal_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $this->actingAs($admin)->patchJson("/admin/users/{$applicant->id}", $this->payload('provider'))
            ->assertUnprocessable();
        $this->assertSame('applicant', $applicant->fresh()->role);
    }

    private function payload(string $role): array
    {
        return [
            'first_name' => 'Mira', 'middle_initial' => null, 'last_name' => 'Cruz',
            'email' => "{$role}.refinement@example.test", 'username' => "{$role}.refinement",
            'contact_number' => '09175550111', 'role' => $role,
            'account_title' => $role === 'admin' ? 'Account manager' : null,
            'permissions' => $role === 'admin' ? ['manage_accounts'] : null,
            'password' => 'test-password-123', 'password_confirmation' => 'test-password-123',
        ];
    }
}
