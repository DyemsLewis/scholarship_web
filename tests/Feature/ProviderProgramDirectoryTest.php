<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderProgramDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_program_directories_redirect_to_the_single_program_list(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);

        $this->actingAs($provider)
            ->get('/provider/programs/edit')
            ->assertRedirect('/provider/programs');

        $this->actingAs($provider)
            ->get('/provider/programs/manage')
            ->assertRedirect('/provider/programs');
    }

    public function test_program_application_tasks_have_dedicated_pages(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Focused Program Workflow',
            'description' => 'A scholarship used to verify focused provider workflow pages.',
            'status' => 'published',
        ]);

        foreach (['review', 'activities', 'results', 'decisions', 'recipients', 'waitlist'] as $workspace) {
            $this->actingAs($provider)
                ->get("/provider/programs/{$scholarship->id}/applications/{$workspace}")
                ->assertOk()
                ->assertViewIs('provider-applications')
                ->assertViewHas('scholarship', fn (Scholarship $viewScholarship): bool => (
                    $viewScholarship->is($scholarship)
                ));
        }
    }

    public function test_program_overview_and_updates_have_dedicated_pages(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Focused Program Workspace',
            'description' => 'A scholarship used to verify the overview and updates pages.',
            'status' => 'published',
        ]);

        foreach (['', '/updates'] as $workspace) {
            $this->actingAs($provider)
                ->get("/provider/programs/{$scholarship->id}{$workspace}")
                ->assertOk()
                ->assertViewIs('provider-program-workspace')
                ->assertViewHas('scholarship', fn (Scholarship $viewScholarship): bool => (
                    $viewScholarship->is($scholarship)
                ));
        }
    }

    public function test_legacy_program_application_entries_redirect_to_a_focused_page(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Legacy Application Workspace',
            'description' => 'A scholarship used to verify legacy workspace redirects.',
            'status' => 'published',
        ]);

        $this->actingAs($provider)
            ->get("/provider/programs/{$scholarship->id}/applications")
            ->assertRedirect("/provider/programs/{$scholarship->id}/applications/review");

        $this->actingAs($provider)
            ->get("/provider/applications?scholarship_id={$scholarship->id}&filter=ready_result")
            ->assertRedirect("/provider/programs/{$scholarship->id}/applications/results");
    }

    public function test_legacy_program_application_entry_rejects_another_provider_program(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $otherProvider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $otherProvider->id,
            'title' => 'Restricted Program',
            'description' => 'A scholarship owned by another provider.',
            'status' => 'published',
        ]);

        $this->actingAs($provider)
            ->get("/provider/applications?scholarship_id={$scholarship->id}")
            ->assertForbidden();
    }

    public function test_recipient_monitoring_tasks_have_dedicated_pages(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Focused Recipient Monitoring',
            'description' => 'A scholarship used to verify focused recipient monitoring pages.',
            'status' => 'published',
        ]);

        foreach (['', '/academic', '/releases', '/outcomes'] as $workspace) {
            $this->actingAs($provider)
                ->get("/provider/programs/{$scholarship->id}/monitoring{$workspace}")
                ->assertOk()
                ->assertViewIs('provider-program-monitoring')
                ->assertViewHas('scholarship', fn (Scholarship $viewScholarship): bool => (
                    $viewScholarship->is($scholarship)
                ));
        }
    }

    public function test_provider_cannot_open_another_organization_program_workspace(): void
    {
        $provider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $otherProvider = User::factory()->create([
            'role' => 'provider',
            'account_status' => 'active',
        ]);
        $scholarship = Scholarship::create([
            'provider_id' => $otherProvider->id,
            'title' => 'Private Program Workspace',
            'description' => 'A scholarship owned by a different provider organization.',
            'status' => 'published',
        ]);

        $workspaces = [
            '',
            '/updates',
            '/applications/review',
            '/applications/activities',
            '/applications/results',
            '/applications/decisions',
            '/applications/recipients',
            '/applications/waitlist',
            '/monitoring',
            '/monitoring/academic',
            '/monitoring/releases',
            '/monitoring/outcomes',
        ];

        foreach ($workspaces as $workspace) {
            $this->actingAs($provider)
                ->get("/provider/programs/{$scholarship->id}{$workspace}")
                ->assertForbidden();
        }
    }
}
