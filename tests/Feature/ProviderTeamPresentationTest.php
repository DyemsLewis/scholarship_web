<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderTeamPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reading_a_display_title_returns_a_canonical_role_without_changing_access_or_storage(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $staff = User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $owner->id,
            'account_title' => 'Application Reviewer', 'permissions' => ['verify_applications'],
        ]);

        $this->actingAs($owner)->getJson("/provider/team/accounts/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('account.team_role', 'application_reviewer')
            ->assertJsonPath('account.team_role_label', 'Application reviewer')
            ->assertJsonPath('account.permissions', ['verify_applications']);

        $this->assertSame('Application Reviewer', $staff->fresh()->account_title);
        $this->assertSame(['verify_applications'], $staff->fresh()->permissions);
        $this->assertNull($staff->fresh()->assigned_program_ids);
    }

    public function test_known_hyphenated_titles_and_canonical_titles_have_the_same_display_role(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        foreach (['selection_officer', 'Selection-Officer', ' SELECTION OFFICER '] as $title) {
            $staff = User::factory()->create([
                'role' => 'provider', 'parent_account_id' => $owner->id,
                'account_title' => $title, 'permissions' => ['manage_selection_activities'],
            ]);
            $this->actingAs($owner)->getJson("/provider/team/accounts/{$staff->id}")
                ->assertOk()->assertJsonPath('account.team_role', 'selection_officer')
                ->assertJsonPath('account.team_role_label', 'Selection officer');
            $this->assertSame($title, $staff->fresh()->account_title);
        }
    }

    public function test_unknown_titles_remain_custom_and_do_not_infer_or_expand_permissions(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $staff = User::factory()->create([
            'role' => 'provider', 'parent_account_id' => $owner->id,
            'account_title' => 'Scholarship liaison', 'permissions' => ['manage_programs'],
        ]);
        $this->actingAs($owner)->getJson("/provider/team/accounts/{$staff->id}")
            ->assertOk()->assertJsonPath('account.team_role', 'custom')
            ->assertJsonPath('account.team_role_label', 'Scholarship liaison')
            ->assertJsonPath('account.permissions', ['manage_programs']);
        $this->assertSame('Scholarship liaison', $staff->fresh()->account_title);
    }
}
