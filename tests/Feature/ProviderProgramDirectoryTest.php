<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderProgramDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_coordinator_can_open_the_new_directory_and_legacy_pages_redirect_to_it(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $coordinator = $this->coordinator($owner);

        $this->actingAs($coordinator)
            ->get('/provider')
            ->assertOk()
            ->assertViewIs('provider-program-coordinator-dashboard');

        $this->actingAs($coordinator)
            ->get('/provider/programs')
            ->assertOk()
            ->assertViewIs('provider-program-directory');

        foreach (['drafts', 'review', 'published', 'closed'] as $section) {
            $this->actingAs($coordinator)
                ->get("/provider/programs/{$section}")
                ->assertOk()
                ->assertViewIs('provider-program-directory');

            $this->actingAs($coordinator)
                ->get("/provider/workspaces/programs/{$section}")
                ->assertRedirect("/provider/programs/{$section}");
        }

        $this->actingAs($coordinator)
            ->get('/provider/workspaces/programs')
            ->assertRedirect('/provider/programs');

        $this->assertSame('/provider/programs', $coordinator->fresh()->publicPayload()['provider_workspace_url']);
    }

    public function test_directory_data_is_scoped_to_the_organization_and_assigned_programs(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assignedDraft = $this->program($owner, 'Assigned draft', 'draft');
        $assignedPublished = $this->program($owner, 'Assigned published', 'published');
        $this->program($owner, 'Unassigned program', 'published');
        $outsideOwner = User::factory()->create(['role' => 'provider']);
        $this->program($outsideOwner, 'Outside organization', 'draft');
        $coordinator = $this->coordinator($owner, [$assignedDraft->id, $assignedPublished->id]);

        $response = $this->actingAs($coordinator)
            ->getJson('/provider/programs/data')
            ->assertOk()
            ->assertJsonPath('context.access_scope', 'assigned')
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.needs_action', 1)
            ->assertJsonPath('summary.published', 1)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'programs');

        $this->assertEqualsCanonicalizing(
            [$assignedDraft->id, $assignedPublished->id],
            collect($response->json('programs'))->pluck('id')->all(),
        );
    }

    public function test_coordinator_dashboard_is_scoped_and_orders_priority_work_and_deadlines(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $revision = $this->program($owner, 'Revision required', 'rejected');
        $draft = $this->program($owner, 'Incomplete draft', 'draft');
        $waiting = $this->program($owner, 'Waiting for review', 'pending_review');
        $urgent = $this->program($owner, 'Deadline approaching', 'published', [
            'deadline' => now()->addDays(3)->toDateString(),
        ]);
        $this->program($owner, 'Unassigned draft', 'draft');
        $outsideOwner = User::factory()->create(['role' => 'provider']);
        $this->program($outsideOwner, 'Outside deadline', 'published');
        $coordinator = $this->coordinator($owner, [$revision->id, $draft->id, $waiting->id, $urgent->id]);

        $response = $this->actingAs($coordinator)
            ->getJson('/provider/programs/dashboard/data')
            ->assertOk()
            ->assertJsonPath('context.access_scope', 'assigned')
            ->assertJsonPath('summary.total', 4)
            ->assertJsonPath('summary.needs_action', 2)
            ->assertJsonPath('summary.under_review', 1)
            ->assertJsonPath('summary.published', 1)
            ->assertJsonPath('priorities.0.id', $revision->id)
            ->assertJsonPath('priorities.0.priority_label', 'Address requested changes')
            ->assertJsonPath('deadlines.0.id', $urgent->id)
            ->assertJsonCount(2, 'priorities')
            ->assertJsonCount(1, 'deadlines')
            ->assertJsonCount(4, 'recent');

        $this->assertEqualsCanonicalizing(
            [$revision->id, $draft->id, $waiting->id, $urgent->id],
            collect($response->json('recent'))->pluck('id')->all(),
        );
    }

    public function test_directory_supports_lifecycle_filters_search_sorting_and_pagination(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $alpha = $this->program($owner, 'Alpha access grant', 'draft');
        $revision = $this->program($owner, 'Revision award', 'rejected');
        $this->program($owner, 'Published award', 'published');
        $coordinator = $this->coordinator($owner);

        $this->actingAs($coordinator)
            ->getJson('/provider/programs/data?filter=drafts&sort=title')
            ->assertOk()
            ->assertJsonPath('filter', 'drafts')
            ->assertJsonPath('programs.0.id', $alpha->id)
            ->assertJsonPath('programs.1.id', $revision->id)
            ->assertJsonCount(2, 'programs');

        $this->actingAs($coordinator)
            ->getJson('/provider/programs/data?search=Alpha')
            ->assertOk()
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('programs.0.id', $alpha->id);

        foreach (range(1, 9) as $number) {
            $this->program($owner, "Additional program {$number}", 'closed');
        }

        $this->actingAs($coordinator)
            ->getJson('/provider/programs/data?page=2')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 10)
            ->assertJsonPath('pagination.total', 12)
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonCount(2, 'programs');
    }

    public function test_program_record_uses_organization_and_assignment_boundaries(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $assigned = $this->program($owner, 'Accessible program', 'published');
        $unassigned = $this->program($owner, 'Restricted program', 'draft');
        $outsideOwner = User::factory()->create(['role' => 'provider']);
        $outside = $this->program($outsideOwner, 'Outside program', 'published');
        $coordinator = $this->coordinator($owner, [$assigned->id]);

        $this->actingAs($coordinator)
            ->get("/provider/programs/{$assigned->id}")
            ->assertOk()
            ->assertViewIs('provider-program-record');

        $this->actingAs($coordinator)
            ->getJson("/provider/programs/{$assigned->id}/data")
            ->assertOk()
            ->assertJsonPath('program.id', $assigned->id)
            ->assertJsonPath('program.title', 'Accessible program')
            ->assertJsonPath('program.status.label', 'Published');

        $this->actingAs($coordinator)->get("/provider/programs/{$unassigned->id}")->assertForbidden();
        $this->actingAs($coordinator)->get("/provider/programs/{$outside->id}")->assertForbidden();
    }

    public function test_program_coordinator_can_open_the_guided_builder_for_create_and_assigned_editing(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $draft = $this->program($owner, 'Builder draft', 'draft');
        $unassigned = $this->program($owner, 'Other draft', 'draft');
        $coordinator = $this->coordinator($owner, [$draft->id]);

        $this->actingAs($coordinator)
            ->get('/provider/programs/create')
            ->assertOk()
            ->assertViewIs('provider-program-form')
            ->assertSee('providerProgramForm', false);

        $this->actingAs($coordinator)
            ->get("/provider/programs/{$draft->id}/edit")
            ->assertRedirect("/provider/programs/{$draft->id}/edit/basics");

        $this->actingAs($coordinator)
            ->get("/provider/programs/{$draft->id}/edit/support")
            ->assertOk()
            ->assertViewIs('provider-program-form');

        $this->actingAs($coordinator)
            ->get("/provider/programs/{$unassigned->id}/edit/basics")
            ->assertForbidden();
    }

    public function test_program_record_only_exposes_edit_action_outside_admin_review(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $draft = $this->program($owner, 'Editable draft', 'draft');
        $review = $this->program($owner, 'Reviewing program', 'pending_review');
        $coordinator = $this->coordinator($owner);

        $this->actingAs($coordinator)
            ->getJson("/provider/programs/{$draft->id}/data")
            ->assertOk()
            ->assertJsonPath('program.actions.edit_url', "/provider/programs/{$draft->id}/edit/basics");

        $this->actingAs($coordinator)
            ->getJson("/provider/programs/{$review->id}/data")
            ->assertOk()
            ->assertJsonPath('program.actions.edit_url', null);
    }

    public function test_provider_without_program_permission_cannot_open_the_directory(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $reviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'permissions' => ['verify_applications'],
        ]);

        $this->actingAs($reviewer)->get('/provider/programs')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/programs/data')->assertForbidden();
        $this->actingAs($reviewer)->getJson('/provider/programs/dashboard/data')->assertForbidden();
    }

    private function coordinator(User $owner, ?array $programIds = null): User
    {
        return User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs'],
            'assigned_program_ids' => $programIds,
        ]);
    }

    private function program(User $owner, string $title, string $status, array $attributes = []): Scholarship
    {
        return Scholarship::query()->create([
            'provider_id' => $owner->id,
            'title' => $title,
            'description' => 'A focused scholarship program record for provider testing.',
            'category' => 'Academic scholarship',
            'program_cycle' => 'School year 2026-2027',
            'slots_available' => 10,
            'application_limit' => 100,
            'selection_stages' => ['screening', 'final_decision'],
            'status' => $status,
            'deadline' => now()->addMonth()->toDateString(),
            ...$attributes,
        ]);
    }
}
