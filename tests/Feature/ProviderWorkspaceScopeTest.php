<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProviderWorkspaceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_provider_scope_only_contains_assigned_organization_programs(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $owner->providerProfile()->updateOrCreate([], [
            'first_name' => 'Iris',
            'last_name' => 'Santos',
            'provider_name' => 'Northstar Learning Foundation',
        ]);
        $assigned = $this->program($owner, 'Assigned Program');
        $this->program($owner, 'Other Organization Program');

        $outsideOwner = User::factory()->create(['role' => 'provider']);
        $this->program($outsideOwner, 'Outside Provider Program');

        $staff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $owner->id,
            'account_title' => 'application_reviewer',
            'permissions' => ['verify_applications'],
            'assigned_program_ids' => [$assigned->id],
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        ScholarshipApplication::query()->create([
            'scholarship_id' => $assigned->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
        ]);

        $scope = app(ProviderWorkspaceScope::class);

        $this->assertSame([$assigned->id], $scope->programs($staff)->pluck('id')->all());
        $this->assertSame(1, $scope->applications($staff)->count());
        $this->assertSame('Northstar Learning Foundation', $scope->context($staff)['organization_name']);
        $this->assertSame('selected', $scope->context($staff)['program_access_mode']);
    }

    public function test_provider_owner_scope_contains_every_program_owned_by_the_organization(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $this->program($owner, 'First Program');
        $this->program($owner, 'Second Program');
        $this->program(User::factory()->create(['role' => 'provider']), 'Outside Program');

        $scope = app(ProviderWorkspaceScope::class);

        $this->assertSame(2, $scope->programs($owner)->count());
        $this->assertSame('all', $scope->context($owner)['program_access_mode']);
    }

    public function test_non_provider_accounts_cannot_build_provider_workspace_queries(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProviderWorkspaceScope::class)
            ->programs(User::factory()->create(['role' => 'applicant']));
    }

    private function program(User $provider, string $title): Scholarship
    {
        return Scholarship::query()->create([
            'provider_id' => $provider->id,
            'title' => $title,
            'description' => 'Dashboard scope test program.',
            'status' => 'published',
        ]);
    }
}
