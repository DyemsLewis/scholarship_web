<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\SupportReport;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use Illuminate\Database\Eloquent\Builder;

final class SupportStaffDashboardQuery
{
    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $staff): array
    {
        $context = $this->scope->context($staff);
        $base = $this->reports($staff);
        $needsAction = $this->queue(clone $base, 'needs_action');
        $waiting = $this->queue(clone $base, 'waiting');
        $submitted = $this->queue(clone $base, 'submitted');
        $resolved = $this->queue(clone $base, 'resolved');
        $relations = [
            'applicant.studentProfile',
            'applicant.providerProfile',
            'applicant.adminProfile',
            'scholarship:id,title',
        ];
        $priorities = (clone $needsAction)
            ->with($relations)
            ->oldest()
            ->limit(5)
            ->get()
            ->map(fn (SupportReport $report): array => $this->item($report, 'needs_action'));
        $platform = (clone $submitted)
            ->with($relations)
            ->latest('updated_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (SupportReport $report): array => $this->item($report, 'submitted'));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'support-staff',
                'eyebrow' => 'Applicant support',
                'title' => 'Support dashboard',
                'description' => 'Respond to applicant concerns first and track platform cases separately.',
                'icon' => 'fa-solid fa-headset',
                'organization_name' => $context['organization_name'],
                'access_scope' => $context['program_access_mode'] === 'selected'
                    ? $context['assigned_program_count'].' assigned programs'
                    : 'All organization programs',
            ],
            primaryAction: [
                'label' => 'Open cases needing response',
                'url' => '/provider/workspaces/support/needs-response',
            ],
            metrics: [
                ['key' => 'needs_action', 'label' => 'Need response', 'value' => (clone $needsAction)->count(), 'detail' => 'Applicant concerns', 'tone' => 'attention', 'url' => '/provider/workspaces/support/needs-response'],
                ['key' => 'waiting', 'label' => 'Waiting for platform', 'value' => (clone $waiting)->count(), 'detail' => 'Provider response complete', 'url' => '/provider/workspaces/support/waiting'],
                ['key' => 'submitted', 'label' => 'Platform reports', 'value' => (clone $submitted)->count(), 'detail' => 'Submitted by your team', 'url' => '/provider/workspaces/support/platform'],
                ['key' => 'resolved', 'label' => 'Resolved', 'value' => (clone $resolved)->count(), 'detail' => 'Closed support cases', 'tone' => 'positive', 'url' => '/provider/workspaces/support/resolved'],
            ],
            sections: [
                [
                    'key' => 'support-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Cases needing response',
                    'description' => 'Oldest unanswered applicant concerns appear first.',
                    'empty_message' => 'No applicant support case needs a response.',
                    'action' => ['label' => 'Open response queue', 'url' => '/provider/workspaces/support/needs-response'],
                    'items' => $priorities,
                ],
                [
                    'key' => 'platform-reports',
                    'eyebrow' => 'Platform support',
                    'title' => 'Open reports submitted by your team',
                    'description' => 'Track organization reports being handled by platform support.',
                    'empty_message' => 'No open platform report was submitted by your team.',
                    'action' => ['label' => 'View platform reports', 'url' => '/provider/workspaces/support/platform'],
                    'items' => $platform,
                ],
            ],
        );
    }

    private function reports(User $staff): Builder
    {
        $query = SupportReport::query()->where('provider_id', $staff->providerOrganizationId());

        if ($staff->hasLimitedProviderProgramAccess()) {
            $query->where(fn (Builder $scope) => $scope
                ->whereNull('scholarship_id')
                ->orWhereIn('scholarship_id', $staff->assignedProviderProgramIds()));
        }

        return $query;
    }

    private function queue(Builder $query, string $queue): Builder
    {
        return match ($queue) {
            'needs_action' => $query->where('assigned_role', 'provider')->where('provider_status', 'open'),
            'waiting' => $query->where('assigned_role', 'provider')->where('provider_status', 'resolved')->where('status', 'open'),
            'submitted' => $query->where('assigned_role', 'admin')->where('admin_status', 'open')->whereHas('applicant', fn (Builder $applicant) => $applicant->where('role', 'provider')),
            default => $query->where(fn (Builder $resolved) => $resolved
                ->where(fn (Builder $incoming) => $incoming->where('assigned_role', 'provider')->where('status', 'resolved'))
                ->orWhere(fn (Builder $submitted) => $submitted->where('assigned_role', 'admin')->where('admin_status', 'resolved')->whereHas('applicant', fn (Builder $applicant) => $applicant->where('role', 'provider')))),
        };
    }

    /** @return array<string, mixed> */
    private function item(SupportReport $report, string $state): array
    {
        return [
            'id' => (int) $report->id,
            'title' => $report->subject,
            'subtitle' => $report->scholarship?->title ?: 'General platform concern',
            'image_url' => null,
            'status' => [
                'label' => $state === 'needs_action' ? 'Response needed' : 'With platform support',
                'tone' => $state === 'needs_action' ? 'attention' : 'neutral',
            ],
            'meta' => [
                $state === 'needs_action'
                    ? ($report->applicant?->name ?: 'Applicant concern')
                    : 'Submitted by '.($report->applicant?->name ?: 'provider staff'),
                $report->created_at ? 'Opened '.$report->created_at->format('M d, Y') : 'Open date unavailable',
            ],
            'action' => [
                'label' => $state === 'needs_action' ? 'Open case' : 'Track report',
                'url' => '/provider/workspaces/support/cases/'.$report->id,
            ],
        ];
    }
}
