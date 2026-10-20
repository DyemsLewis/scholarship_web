<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class AccountManagerDashboardQuery
{
    /** @return array<string, mixed> */
    public function get(User $manager): array
    {
        $visibleUsers = $this->visibleUsers($manager);
        $attentionQuery = (clone $visibleUsers)->where(function (Builder $query): void {
            $query
                ->whereNull('email_verified_at')
                ->orWhere('must_reset_password', true)
                ->orWhere('account_status', 'suspended');
        });
        $attention = (clone $attentionQuery)
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
            ->orderByRaw("case when account_status = 'suspended' then 0 when email_verified_at is null then 1 when must_reset_password = 1 then 2 else 3 end")
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (User $user): array => $this->item($user));
        $recent = (clone $visibleUsers)
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
            ->when($attention->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('id', $attention->pluck('id')))
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (User $user): array => $this->item($user));
        $attentionCount = (clone $attentionQuery)->count();

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'account-manager',
                'eyebrow' => 'Account access',
                'title' => 'Account dashboard',
                'description' => 'Resolve access issues and manage portal accounts.',
                'icon' => 'fa-solid fa-user-shield',
                'access_scope' => $manager->isManagedAccount() ? 'Applicant and provider accounts' : 'All portal accounts',
            ],
            primaryAction: [
                'label' => $attentionCount > 0 ? 'Open attention queue' : 'Create account',
                'url' => $attentionCount > 0 ? '/admin/workspaces/accounts/attention' : '/admin/workspaces/accounts/create',
                'icon' => $attentionCount > 0 ? 'fa-solid fa-arrow-right' : 'fa-solid fa-user-plus',
            ],
            metrics: [
                ['key' => 'attention', 'label' => 'Need attention', 'value' => $attentionCount, 'detail' => 'Access issues', 'tone' => 'attention', 'url' => '/admin/workspaces/accounts/attention', 'icon' => 'fa-solid fa-triangle-exclamation'],
                ['key' => 'active', 'label' => 'Active accounts', 'value' => (clone $visibleUsers)->where('account_status', '!=', 'suspended')->count(), 'detail' => 'Sign-in enabled', 'tone' => 'positive', 'url' => '/admin/workspaces/accounts/directory', 'icon' => 'fa-solid fa-user-check'],
                ['key' => 'applicants', 'label' => 'Applicants', 'value' => (clone $visibleUsers)->where('role', 'applicant')->count(), 'detail' => 'Learner accounts', 'url' => '/admin/workspaces/accounts/directory?role=applicant', 'icon' => 'fa-solid fa-user-graduate'],
                ['key' => 'providers', 'label' => 'Providers', 'value' => (clone $visibleUsers)->where('role', 'provider')->count(), 'detail' => 'Organization accounts', 'url' => '/admin/workspaces/accounts/directory?role=provider', 'icon' => 'fa-solid fa-building-columns'],
            ],
            sections: [
                [
                    'key' => 'access-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Access issues to resolve',
                    'description' => 'Suspended, unverified, and password-reset accounts appear here.',
                    'empty_message' => 'No account access issue needs attention.',
                    'icon' => 'fa-solid fa-shield-halved',
                    'action' => ['label' => 'Open attention queue', 'url' => '/admin/workspaces/accounts/attention', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $attention,
                ],
                [
                    'key' => 'recent-accounts',
                    'eyebrow' => 'Recent activity',
                    'title' => 'Recently added accounts',
                    'description' => 'A short list of new accounts not already shown above.',
                    'empty_message' => 'No additional recent account is available.',
                    'icon' => 'fa-solid fa-clock-rotate-left',
                    'action' => ['label' => 'Open account directory', 'url' => '/admin/workspaces/accounts/directory', 'icon' => 'fa-solid fa-arrow-right'],
                    'items' => $recent,
                ],
            ],
        );
    }

    private function visibleUsers(User $manager): Builder
    {
        return User::query()
            ->when($manager->isManagedAccount(), fn (Builder $query) => $query->where('role', '!=', 'admin'));
    }

    /** @return array<string, mixed> */
    private function item(User $user): array
    {
        [$status, $tone] = match (true) {
            $user->isSuspended() => ['Suspended', 'attention'],
            ! $user->hasVerifiedEmail() => ['Email unverified', 'attention'],
            $user->must_reset_password => ['Password reset due', 'attention'],
            default => ['Access active', 'positive'],
        };
        $payload = $user->publicPayload();

        return [
            'id' => (int) $user->id,
            'title' => $user->name,
            'subtitle' => Str::headline($user->role).' account',
            'image_url' => $this->avatarUrl($user, $payload),
            'status' => ['label' => $status, 'tone' => $tone],
            'meta' => [
                $user->email,
                $user->created_at ? 'Created '.$user->created_at->format('M d, Y') : 'Creation date unavailable',
            ],
            'action' => [
                'label' => 'Open account',
                'url' => '/admin/workspaces/accounts/'.$user->id,
            ],
        ];
    }

    private function avatarUrl(User $user, array $payload): ?string
    {
        if ($user->isApplicant() && filled($user->studentProfile?->profile_photo_path)) {
            return '/admin/workspaces/accounts/'.$user->id.'/photo';
        }

        return $user->isProvider() ? ($payload['provider_logo_url'] ?? null) : null;
    }
}
