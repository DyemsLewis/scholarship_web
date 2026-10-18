<?php

namespace App\Queries\Admin;

use App\Models\ActivityLog;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class RecordsWorkspaceQuery
{
    private const EXPORT_ACTIONS = [
        'records_users_exported',
        'records_applications_exported',
        'records_monitoring_exported',
    ];

    public function activity(array $filters): array
    {
        $query = ActivityLog::query()->latest();
        if (filled($filters['action'] ?? null) && $filters['action'] !== 'all') {
            $query->where('action', $filters['action']);
        }
        $this->applyCategory($query, $filters['category'] ?? 'all');
        $this->applyActorRole($query, $filters['actor_role'] ?? 'all');
        $this->applyPeriod($query, $filters['period'] ?? 'month');
        $this->applySearch($query, $filters['search'] ?? null);

        $paginator = $query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString();

        return [
            ...$this->page($paginator),
            'summary' => [
                'total' => ActivityLog::query()->count(),
                'today' => ActivityLog::query()->whereDate('created_at', today())->count(),
                'security_7_days' => ActivityLog::query()
                    ->where('action', 'login_failed')
                    ->where('created_at', '>=', now()->subDays(7))
                    ->count(),
                'exports_30_days' => ActivityLog::query()
                    ->whereIn('action', self::EXPORT_ACTIONS)
                    ->where('created_at', '>=', now()->subDays(30))
                    ->count(),
            ],
        ];
    }

    public function record(ActivityLog $entry): array
    {
        return [
            ...$this->activityItem($entry),
            'user_agent' => $entry->user_agent,
            'metadata' => collect($entry->metadata ?? [])
                ->map(fn (mixed $value, string $key): array => [
                    'label' => Str::headline($key),
                    'value' => $this->metadataValue($value),
                ])
                ->values(),
        ];
    }

    public function exportDesk(): array
    {
        $monitoringRecords = ScholarshipApplication::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('status', ['awarded', 'distribution_scheduled', 'disbursed', 'renewed', 'benefits_terminated'])
                    ->orWhereHas('monitoringSubmissions')
                    ->orWhereHas('benefitReleaseRecords')
                    ->orWhereHas('supportDecisions');
            })
            ->count();

        return [
            'datasets' => [
                [
                    'key' => 'users',
                    'name' => 'User account records',
                    'description' => 'Identity, role, verification, and account status.',
                    'records' => User::query()->count(),
                    'download_url' => route('admin.export.users', [], false),
                    'last_exported_at' => $this->lastExportedAt('records_users_exported'),
                ],
                [
                    'key' => 'applications',
                    'name' => 'Scholarship applications',
                    'description' => 'Application, decision, document, and award records.',
                    'records' => ScholarshipApplication::query()->count(),
                    'download_url' => route('admin.export.applications', [], false),
                    'last_exported_at' => $this->lastExportedAt('records_applications_exported'),
                ],
                [
                    'key' => 'monitoring',
                    'name' => 'Recipient monitoring',
                    'description' => 'Requirements, releases, support, and oversight outcomes.',
                    'records' => $monitoringRecords,
                    'download_url' => route('admin.export.monitoring', [], false),
                    'last_exported_at' => $this->lastExportedAt('records_monitoring_exported'),
                ],
            ],
            'recent_exports' => ActivityLog::query()
                ->whereIn('action', self::EXPORT_ACTIONS)
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (ActivityLog $entry): array => $this->activityItem($entry))
                ->values(),
        ];
    }

    private function page(LengthAwarePaginator $paginator): array
    {
        return [
            'items' => collect($paginator->items())->map(fn (ActivityLog $entry): array => $this->activityItem($entry))->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function activityItem(ActivityLog $entry): array
    {
        return [
            'id' => $entry->id,
            'actor_name' => $entry->actor_name ?: 'System',
            'actor_role' => $entry->actor_role ?: 'system',
            'action' => $entry->action,
            'action_label' => Str::headline($entry->action),
            'category' => $this->categoryFor($entry->action),
            'description' => $entry->description,
            'ip_address' => $entry->ip_address,
            'created_at' => $entry->created_at?->format('M d, Y h:i A'),
            'record_url' => route('admin.workspaces.records.activity.show', $entry, false),
        ];
    }

    private function applyCategory(Builder $query, string $category): void
    {
        if ($category === 'all') {
            return;
        }

        if ($category === 'exports') {
            $query->whereIn('action', self::EXPORT_ACTIONS);

            return;
        }

        $patterns = match ($category) {
            'security' => ['login', 'logout', 'password', 'email_verification'],
            'accounts' => ['account', 'registered', 'profile'],
            'reviews' => ['review', 'verification'],
            default => ['program', 'scholarship', 'application', 'monitoring', 'benefit', 'recipient', 'support', 'billing', 'service'],
        };

        $query->where(function (Builder $nested) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $nested->orWhere('action', 'like', "%{$pattern}%");
            }
        });
    }

    private function applyActorRole(Builder $query, string $role): void
    {
        if ($role === 'system') {
            $query->whereNull('actor_role');
        } elseif ($role !== 'all') {
            $query->where('actor_role', $role);
        }
    }

    private function applyPeriod(Builder $query, string $period): void
    {
        if ($period === 'today') {
            $query->where('created_at', '>=', today());
        } elseif ($period === 'week') {
            $query->where('created_at', '>=', now()->subDays(7));
        } elseif ($period === 'month') {
            $query->where('created_at', '>=', now()->subDays(30));
        }
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $nested) use ($search): void {
            $nested->where('description', 'like', "%{$search}%")
                ->orWhere('actor_name', 'like', "%{$search}%")
                ->orWhere('action', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%");
        });
    }

    private function categoryFor(string $action): string
    {
        if (in_array($action, self::EXPORT_ACTIONS, true)) {
            return 'exports';
        }
        if (Str::contains($action, ['login', 'logout', 'password', 'email_verification'])) {
            return 'security';
        }
        if (Str::contains($action, ['review', 'verification'])) {
            return 'reviews';
        }
        if (Str::contains($action, ['account', 'registered', 'profile'])) {
            return 'accounts';
        }

        return 'operations';
    }

    private function metadataValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'Not recorded';
        }

        return filled($value) ? (string) $value : 'Not recorded';
    }

    private function lastExportedAt(string $action): ?string
    {
        return ActivityLog::query()->where('action', $action)->latest()->first()?->created_at?->format('M d, Y h:i A');
    }
}
