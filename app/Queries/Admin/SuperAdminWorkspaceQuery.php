<?php

namespace App\Queries\Admin;

use App\Domain\Admin\Workspace\AdminRoleCatalog;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class SuperAdminWorkspaceQuery
{
    private const AREA_OWNERS = [
        'accounts' => AdminRoleCatalog::ACCOUNT_MANAGER,
        'reviews' => AdminRoleCatalog::REVIEW_OFFICER,
        'support' => AdminRoleCatalog::SUPPORT_OFFICER,
        'services' => AdminRoleCatalog::BILLING_OFFICER,
        'finance' => AdminRoleCatalog::FINANCE_OFFICER,
        'records' => AdminRoleCatalog::RECORDS_OFFICER,
    ];

    public function __construct(
        private readonly PortalManagerWorkspaceQuery $operations,
        private readonly RecordsWorkspaceQuery $records,
    ) {}

    public function overview(): array
    {
        $staff = $this->adminStaff();
        $coverage = $this->roleCoverage($staff);
        $operations = $this->operations->overview();
        $areas = $this->operationalAreas($operations['priorities'], $coverage);
        $platform = $this->platformSummary();

        return [
            'summary' => [
                'managed_admins' => $staff->count(),
                'active_admins' => $staff->where('account_status', '!=', 'suspended')->count(),
                'covered_roles' => $coverage->where('covered', true)->count(),
                'total_roles' => $coverage->count(),
                'platform_users' => $platform['users'],
                'operational_attention' => $operations['summary']['total_attention'],
            ],
            'role_coverage' => $coverage,
            'operational_areas' => $areas,
            'escalations' => $areas
                ->filter(fn (array $area): bool => $area['count'] > 0 && ! $area['covered'])
                ->values(),
        ];
    }

    public function team(): array
    {
        $staff = $this->adminStaff();
        $coverage = $this->roleCoverage($staff);

        return [
            'summary' => [
                'team_members' => $staff->count(),
                'active' => $staff->where('account_status', '!=', 'suspended')->count(),
                'suspended' => $staff->where('account_status', 'suspended')->count(),
                'unassigned' => $staff->filter(fn (User $user): bool => AdminRoleCatalog::keyFor($user) === null)->count(),
            ],
            'coverage' => $coverage,
            'members' => $staff->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'title' => $user->account_title ?: 'Unassigned admin',
                'workspace' => Str::headline(AdminRoleCatalog::keyFor($user) ?: 'unassigned'),
                'permission_count' => count($user->effectivePortalPermissions()),
                'status' => $user->account_status ?: 'active',
                'email_verified' => $user->hasVerifiedEmail(),
                'updated_at' => $user->updated_at?->format('M d, Y'),
                'edit_url' => route('admin.accounts.edit', $user, false),
            ])->values(),
        ];
    }

    public function platform(): array
    {
        $staff = $this->adminStaff();
        $coverage = $this->roleCoverage($staff);
        $operations = $this->operations->overview();
        $activity = $this->records->activity(['period' => 'week', 'per_page' => 10]);
        $activity['items'] = collect($activity['items'])->map(function (array $item): array {
            unset($item['record_url']);

            return $item;
        })->values();

        return [
            'summary' => $this->platformSummary(),
            'operational_areas' => $this->operationalAreas($operations['priorities'], $coverage),
            'activity' => $activity,
        ];
    }

    private function adminStaff(): Collection
    {
        return User::query()
            ->with('adminProfile')
            ->where('role', 'admin')
            ->whereNotNull('parent_account_id')
            ->latest('updated_at')
            ->get();
    }

    private function roleCoverage(Collection $staff): Collection
    {
        return collect(AdminRoleCatalog::definitions())
            ->except(AdminRoleCatalog::SUPER_ADMIN)
            ->map(function (array $definition, string $key) use ($staff): array {
                $members = $staff->filter(fn (User $user): bool => AdminRoleCatalog::keyFor($user) === $key);
                $active = $members->where('account_status', '!=', 'suspended')->count();

                return [
                    'key' => $key,
                    'title' => $definition['title'],
                    'total' => $members->count(),
                    'active' => $active,
                    'covered' => $active > 0,
                ];
            })
            ->values();
    }

    private function operationalAreas(Collection $priorities, Collection $coverage): Collection
    {
        return $priorities->map(function (array $priority) use ($coverage): array {
            $ownerKey = self::AREA_OWNERS[$priority['key']] ?? null;
            $ownerCoverage = $coverage->firstWhere('key', $ownerKey);

            return [
                ...$priority,
                'covered' => (bool) ($ownerCoverage['covered'] ?? false),
                'active_owners' => (int) ($ownerCoverage['active'] ?? 0),
            ];
        })->values();
    }

    private function platformSummary(): array
    {
        return [
            'users' => User::query()->count(),
            'providers' => User::query()->where('role', 'provider')->whereNull('parent_account_id')->count(),
            'programs' => Scholarship::query()->count(),
            'published_programs' => Scholarship::query()->where('status', 'published')->count(),
            'applications' => ScholarshipApplication::query()->count(),
            'active_recipients' => ScholarshipApplication::query()->whereIn('status', [
                'awarded',
                'distribution_scheduled',
                'disbursed',
                'renewed',
            ])->count(),
        ];
    }
}
