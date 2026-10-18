<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ApplicationReviewerDashboardQuery
{
    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $reviewer): array
    {
        $context = $this->scope->context($reviewer);
        $reviewable = $this->reviewable($reviewer);
        $mine = (clone $reviewable)->where('assigned_reviewer_id', $reviewer->id);
        $unassigned = (clone $reviewable)->whereNull('assigned_reviewer_id');
        $returned = (clone $reviewable)
            ->where('correction_status', 'submitted')
            ->where(fn (Builder $query) => $query
                ->where('assigned_reviewer_id', $reviewer->id)
                ->orWhereNull('assigned_reviewer_id'));
        $history = $this->scope->applications($reviewer)
            ->where('reviewed_by', $reviewer->id)
            ->whereNotNull('reviewed_at');
        $oldestSubmittedAt = (clone $reviewable)->min('submitted_at');

        $priorities = (clone $reviewable)
            ->where(fn (Builder $query) => $query
                ->where('assigned_reviewer_id', $reviewer->id)
                ->orWhereNull('assigned_reviewer_id'))
            ->with(['applicant.studentProfile', 'scholarship'])
            ->withCount('documents')
            ->orderByRaw("CASE WHEN correction_status = 'submitted' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN assigned_reviewer_id = ? THEN 0 ELSE 1 END', [$reviewer->id])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (ScholarshipApplication $application): array => $this->item(
                $application,
                $reviewer,
                '/provider/workspaces/reviews/assigned',
            ));

        $recent = (clone $history)
            ->with(['applicant.studentProfile', 'scholarship'])
            ->withCount('documents')
            ->latest('reviewed_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (ScholarshipApplication $application): array => $this->item(
                $application,
                $reviewer,
                '/provider/workspaces/reviews/history',
                true,
            ));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'application-reviewer',
                'eyebrow' => 'Application verification',
                'title' => 'Reviewer dashboard',
                'description' => 'Start with returned updates and the oldest assigned applications.',
                'icon' => 'fa-solid fa-magnifying-glass-chart',
                'organization_name' => $context['organization_name'],
                'access_scope' => $this->accessScope($context),
            ],
            primaryAction: [
                'label' => 'Open my queue',
                'url' => '/provider/workspaces/reviews/assigned',
            ],
            metrics: [
                [
                    'key' => 'assigned',
                    'label' => 'My reviews',
                    'value' => (clone $mine)->count(),
                    'detail' => 'Assigned to you',
                    'tone' => 'attention',
                    'url' => '/provider/workspaces/reviews/assigned',
                ],
                [
                    'key' => 'returned',
                    'label' => 'Returned updates',
                    'value' => (clone $returned)->count(),
                    'detail' => 'Applicants responded',
                    'tone' => 'attention',
                    'url' => '/provider/workspaces/reviews/returned',
                ],
                [
                    'key' => 'unassigned',
                    'label' => 'Unassigned',
                    'value' => (clone $unassigned)->count(),
                    'detail' => 'Available to claim',
                    'url' => '/provider/workspaces/reviews/unassigned',
                ],
                [
                    'key' => 'oldest',
                    'label' => 'Oldest wait',
                    'value' => $oldestSubmittedAt ? $this->waitingDays($oldestSubmittedAt).'d' : '0d',
                    'detail' => 'Across the review queue',
                ],
            ],
            sections: [
                [
                    'key' => 'review-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Review priorities',
                    'description' => 'Returned updates appear first, followed by assigned and available records.',
                    'empty_message' => 'No applications currently need verification.',
                    'action' => [
                        'label' => 'Open full queue',
                        'url' => '/provider/workspaces/reviews/assigned',
                    ],
                    'items' => $priorities,
                ],
                [
                    'key' => 'recent-reviews',
                    'eyebrow' => 'Reference',
                    'title' => 'Recently reviewed',
                    'description' => 'Your latest completed verification records.',
                    'empty_message' => 'Completed reviews will appear here.',
                    'action' => [
                        'label' => 'View history',
                        'url' => '/provider/workspaces/reviews/history',
                    ],
                    'items' => $recent,
                ],
            ],
        );
    }

    private function reviewable(User $reviewer): Builder
    {
        return $this->scope->applications($reviewer)
            ->where(fn (Builder $query) => $query
                ->where('workflow_stage', 'screening')
                ->orWhere(fn (Builder $legacy) => $legacy
                    ->whereNull('workflow_stage')
                    ->whereIn('status', ['submitted', 'under_review', 'qualified', 'shortlisted'])))
            ->whereNotIn('application_state', ['closed', 'withdrawn']);
    }

    /** @return array<string, mixed> */
    private function item(
        ScholarshipApplication $application,
        User $reviewer,
        string $returnTo,
        bool $completed = false,
    ): array {
        $profile = $application->applicant?->studentProfile;
        $isMine = $application->assigned_reviewer_id === $reviewer->id;
        $isReturned = $application->correction_status === 'submitted';
        $referenceAt = $completed
            ? $application->reviewed_at
            : ($application->submitted_at ?? $application->created_at);

        return [
            'id' => (int) $application->id,
            'title' => $application->applicant?->name ?: 'Applicant record',
            'subtitle' => $application->scholarship?->title ?: 'Scholarship program',
            'image_url' => $profile?->profile_photo_path
                ? route('provider.applications.profile-photo.view', $application, false)
                : null,
            'status' => [
                'label' => $completed
                    ? 'Reviewed'
                    : ($isReturned ? 'Returned update' : ($isMine ? 'Assigned to you' : 'Available to claim')),
                'tone' => $completed ? 'positive' : ($isReturned ? 'attention' : 'neutral'),
            ],
            'meta' => [
                $completed
                    ? 'Reviewed '.($referenceAt?->format('M d, Y') ?: 'recently')
                    : $this->waitingDays($referenceAt).' day'.($this->waitingDays($referenceAt) === 1 ? '' : 's').' waiting',
                ((int) ($application->documents_count ?? 0)).' file'.((int) ($application->documents_count ?? 0) === 1 ? '' : 's').' submitted',
            ],
            'action' => [
                'label' => $completed ? 'View record' : 'Open review',
                'url' => route('provider.applications.show', [
                    'application' => $application,
                    'return_to' => $returnTo,
                ], false),
            ],
        ];
    }

    private function waitingDays(mixed $date): int
    {
        if (! $date) {
            return 0;
        }

        return max(0, (int) CarbonImmutable::parse($date)->startOfDay()->diffInDays(CarbonImmutable::today()));
    }

    /** @param array<string, mixed> $context */
    private function accessScope(array $context): string
    {
        return $context['program_access_mode'] === 'selected'
            ? $context['assigned_program_count'].' assigned programs'
            : 'All organization programs';
    }
}
