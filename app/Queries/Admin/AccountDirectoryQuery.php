<?php

namespace App\Queries\Admin;

use App\Models\User;

final class AccountDirectoryQuery
{
    /**
     * @param  array{search?: string|null, role?: string|null, attention?: string|null, per_page?: int|null}  $filters
     */
    public function payload(User $actor, array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $role = $filters['role'] ?? 'all';
        $attention = $filters['attention'] ?? 'all';
        $perPage = (int) ($filters['per_page'] ?? 10);
        $visibleUsers = User::query()
            ->when($actor->isManagedAccount(), fn ($query) => $query->where('role', '!=', 'admin'));
        $roleCounts = (clone $visibleUsers)
            ->selectRaw('role, count(*) as total')
            ->groupBy('role')
            ->pluck('total', 'role');

        $query = (clone $visibleUsers)
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
            ->latest();

        if ($role !== 'all') {
            $query->where('role', $role);
        }

        match ($attention) {
            'unverified' => $query->whereNull('email_verified_at'),
            'password_reset' => $query->where('must_reset_password', true),
            'suspended' => $query->where('account_status', 'suspended'),
            'needs_attention' => $query->where(function ($query): void {
                $query
                    ->whereNull('email_verified_at')
                    ->orWhere('must_reset_password', true)
                    ->orWhere('account_status', 'suspended');
            }),
            default => null,
        };

        if ($search !== '') {
            $likeSearch = '%'.$search.'%';

            $query->where(function ($query) use ($likeSearch): void {
                $query
                    ->where('account_status', 'like', $likeSearch)
                    ->orWhere('email', 'like', $likeSearch)
                    ->orWhere('username', 'like', $likeSearch)
                    ->orWhere('role', 'like', $likeSearch)
                    ->orWhereHas('studentProfile', fn ($profileQuery) => $profileQuery
                        ->where('first_name', 'like', $likeSearch)
                        ->orWhere('last_name', 'like', $likeSearch)
                        ->orWhere('contact_number', 'like', $likeSearch)
                    )
                    ->orWhereHas('providerProfile', fn ($profileQuery) => $profileQuery
                        ->where('first_name', 'like', $likeSearch)
                        ->orWhere('last_name', 'like', $likeSearch)
                        ->orWhere('contact_number', 'like', $likeSearch)
                        ->orWhere('provider_name', 'like', $likeSearch)
                    )
                    ->orWhereHas('adminProfile', fn ($profileQuery) => $profileQuery
                        ->where('first_name', 'like', $likeSearch)
                        ->orWhere('last_name', 'like', $likeSearch)
                        ->orWhere('contact_number', 'like', $likeSearch)
                        ->orWhere('display_name', 'like', $likeSearch)
                    );
            });
        }

        $users = $query->paginate($perPage);

        return [
            'stats' => [
                'total_users' => (int) $roleCounts->sum(),
                'admins' => (int) ($roleCounts['admin'] ?? 0),
                'applicants' => (int) ($roleCounts['applicant'] ?? 0),
                'providers' => (int) ($roleCounts['provider'] ?? 0),
                'active_users' => (clone $visibleUsers)->where('account_status', '!=', 'suspended')->count(),
                'recent_signups' => (clone $visibleUsers)->where('created_at', '>=', now()->subDays(7))->count(),
                'unverified_users' => (clone $visibleUsers)->whereNull('email_verified_at')->count(),
                'suspended_users' => (clone $visibleUsers)->where('account_status', 'suspended')->count(),
                'password_resets_required' => (clone $visibleUsers)->where('must_reset_password', true)->count(),
                'attention_total' => (clone $visibleUsers)
                    ->where(function ($query): void {
                        $query
                            ->whereNull('email_verified_at')
                            ->orWhere('must_reset_password', true)
                            ->orWhere('account_status', 'suspended');
                    })
                    ->count(),
            ],
            'users' => $users->getCollection()->map(fn (User $user) => [
                ...$user->publicPayload(),
                'avatar_url' => $this->avatarUrl($user),
                'created_at' => $user->created_at?->format('M d, Y'),
            ])->values(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ];
    }

    private function avatarUrl(User $user): ?string
    {
        if ($user->isApplicant() && filled($user->studentProfile?->profile_photo_path)) {
            return "/admin/workspaces/accounts/{$user->id}/photo";
        }

        return $user->isProvider() ? $user->publicPayload()['provider_logo_url'] : null;
    }
}
