<?php

namespace App\Queries\Admin\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\User;
use App\Queries\Admin\AccountDirectoryQuery;
use App\Support\AdminWorkPriority;
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
                ->orWhere('account_status', 'suspended')
                ->orWhereHas('supportAccountHandoffs', fn (Builder $handoff) => $handoff->where('status', 'pending'));
        });
        $attention = AccountDirectoryQuery::prioritize(clone $attentionQuery)
            ->with(['studentProfile', 'providerProfile', 'adminProfile'])
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
        $next = $attention->first();
        $suspended = ($next['status']['label'] ?? null) === 'Suspended';
        $referred = ($next['status']['label'] ?? null) === 'Referred by Support';

        return [...RoleDashboardPayload::make(
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
                ['key' => 'attention', 'label' => 'Access flags', 'value' => $attentionCount, 'detail' => 'Restrictions and account-holder steps', 'tone' => 'attention', 'url' => '/admin/workspaces/accounts/attention', 'icon' => 'fa-solid fa-triangle-exclamation'],
                ['key' => 'active', 'label' => 'All accounts', 'value' => (clone $visibleUsers)->count(), 'detail' => 'Within your access scope', 'url' => '/admin/workspaces/accounts/directory', 'icon' => 'fa-solid fa-users'],
                ['key' => 'applicants', 'label' => 'Applicants', 'value' => (clone $visibleUsers)->where('role', 'applicant')->count(), 'detail' => 'Learner accounts', 'url' => '/admin/workspaces/accounts/directory?role=applicant', 'icon' => 'fa-solid fa-user-graduate'],
                ['key' => 'providers', 'label' => 'Providers', 'value' => (clone $visibleUsers)->where('role', 'provider')->count(), 'detail' => 'Organization accounts', 'url' => '/admin/workspaces/accounts/directory?role=provider', 'icon' => 'fa-solid fa-building-columns'],
            ],
            sections: [
                [
                    'key' => 'access-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Account access follow-up',
                    'description' => 'Support referrals first, then restrictions and account-holder steps.',
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
        ), 'priority' => $next ? AdminWorkPriority::make(
            $next['title'],
            $referred ? 'Support needs an access check. Review the task in the account record and return a result; this does not approve a profile.' : ($suspended
                ? 'Review the restriction and its recorded reason before making an access change. Suspension is not automatically an error.'
                : 'The account holder needs to verify their email or change their password. Offer assistance if they report an access problem.'),
            $suspended || $referred ? 'Account Manager' : 'Account holder',
            $next['action'],
            state: $suspended || $referred ? 'actionable' : 'waiting',
        ) : AdminWorkPriority::make('No account access flags', 'There are no restrictions or account-holder steps in the attention queue.', 'Account Manager', state: 'clear')];
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
            (bool) $user->pending_support_handoff => ['Referred by Support', 'attention'],
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
            'image_fit' => $user->isProvider() ? 'contain' : 'cover',
            'status' => ['label' => $status, 'tone' => $tone],
            'hint' => $user->email,
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
