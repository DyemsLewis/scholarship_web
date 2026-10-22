<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipFunnelEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderApplicantPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_manager_can_preview_the_organization_as_an_applicant(): void
    {
        $owner = $this->providerOwner();
        $manager = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'organization_profile_manager',
            'permissions' => ['manage_profile'],
        ]);
        $this->program($owner, ['status' => 'published']);

        $this->actingAs($manager)
            ->get('/provider/previews/organization')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertViewIs('dashboard-providers')
            ->assertViewHas('preview', true);

        $this->actingAs($manager)
            ->getJson('/provider/previews/organization/data')
            ->assertOk()
            ->assertJsonPath('preview.enabled', true)
            ->assertJsonPath('provider.name', 'Northstar Learning Foundation')
            ->assertJsonPath('provider.verification_label', 'Verified organization')
            ->assertJsonCount(1, 'provider.programs');
    }

    public function test_program_coordinator_can_preview_a_draft_without_recording_applicant_analytics(): void
    {
        $owner = $this->providerOwner();
        $program = $this->program($owner, [
            'status' => 'draft',
            'views_count' => 7,
        ]);
        $coordinator = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs'],
            'assigned_program_ids' => [$program->id],
        ]);

        $this->actingAs($coordinator)
            ->get("/provider/previews/programs/{$program->id}")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertViewIs('dashboard-scholarship-detail')
            ->assertViewHas('preview', true);

        $response = $this->actingAs($coordinator)
            ->getJson("/provider/previews/programs/{$program->id}/data?section=summary&scenario=eligible")
            ->assertOk()
            ->assertJsonPath('preview.enabled', true)
            ->assertJsonPath('preview.scenario', 'eligible')
            ->assertJsonPath('detail.preview.enabled', true)
            ->assertJsonPath('detail.preview.status', 'Draft')
            ->assertJsonPath('detail.decision.state', 'eligible')
            ->assertJsonPath('detail.decision.action.disabled', true)
            ->assertJsonPath('detail.saved.disabled', true)
            ->assertJsonCount(5, 'detail.navigation');

        foreach ($response->json('detail.navigation') as $item) {
            $this->assertStringStartsWith("/provider/previews/programs/{$program->id}", $item['href']);
        }

        $this->assertSame(7, $program->fresh()->views_count);
        $this->assertSame(0, ScholarshipFunnelEvent::query()->count());
    }

    public function test_preview_supports_an_ineligible_sample_and_rejects_invalid_scenarios(): void
    {
        $owner = $this->providerOwner();
        $program = $this->program($owner);

        $this->actingAs($owner)
            ->getJson("/provider/previews/programs/{$program->id}/data?section=eligibility&scenario=ineligible")
            ->assertOk()
            ->assertJsonPath('preview.scenario', 'ineligible')
            ->assertJsonPath('detail.decision.state', 'blocked')
            ->assertJsonCount(1, 'detail.content.blockers');

        $this->actingAs($owner)
            ->getJson("/provider/previews/programs/{$program->id}/data?scenario=unknown")
            ->assertOk()
            ->assertJsonPath('preview.scenario', 'general')
            ->assertJsonPath('detail.preview.scenario', 'general');
    }

    public function test_preview_access_is_limited_by_role_permission_and_program_assignment(): void
    {
        $owner = $this->providerOwner();
        $program = $this->program($owner);
        $otherProgram = $this->program($owner, ['title' => 'Restricted Program']);
        $coordinator = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'permissions' => ['manage_programs'],
            'assigned_program_ids' => [$program->id],
        ]);
        $reviewer = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'permissions' => ['verify_applications'],
        ]);
        $applicant = User::factory()->create();

        $this->actingAs($coordinator)
            ->getJson("/provider/previews/programs/{$otherProgram->id}/data")
            ->assertForbidden();
        $this->actingAs($reviewer)
            ->getJson("/provider/previews/programs/{$program->id}/data")
            ->assertForbidden();
        $this->actingAs($applicant)
            ->getJson("/provider/previews/programs/{$program->id}/data")
            ->assertForbidden();
    }

    public function test_preview_does_not_publish_a_draft_to_applicants(): void
    {
        $owner = $this->providerOwner();
        $program = $this->program($owner, ['status' => 'draft']);
        $applicant = User::factory()->create();

        $this->actingAs($owner)
            ->getJson("/provider/previews/programs/{$program->id}/data")
            ->assertOk();

        $this->actingAs($applicant)
            ->getJson("/dashboard/scholarships/{$program->id}/data")
            ->assertNotFound();
    }

    private function providerOwner(): User
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->update([
            'provider_name' => 'Northstar Learning Foundation',
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);

        return $owner->fresh();
    }

    private function program(User $owner, array $attributes = []): Scholarship
    {
        return Scholarship::query()->create([
            'provider_id' => $owner->id,
            'title' => 'Northstar Scholars Program',
            'category' => 'Academic merit',
            'description' => 'A complete scholarship program used to review the applicant-facing presentation.',
            'application_mode' => 'online',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'requirements' => 'School ID',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'draft',
            ...$attributes,
        ]);
    }
}
