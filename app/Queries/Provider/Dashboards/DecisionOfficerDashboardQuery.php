<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class DecisionOfficerDashboardQuery
{
    private const AWARD_SLOT_STATUSES = [
        'awarded',
        'distribution_scheduled',
        'disbursed',
        'renewed',
    ];

    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $officer): array
    {
        $context = $this->scope->context($officer);
        $base = $this->scope->applications($officer);
        $pending = $this->pending(clone $base);
        $waitlist = $this->waitlist(clone $base);
        $selected = (clone $base)->where(fn (Builder $query) => $query
            ->where('final_outcome', 'selected')
            ->orWhereIn('status', self::AWARD_SLOT_STATUSES));

        $programs = $this->scope->programs($officer)
            ->withCount(['applications as awarded_slots_count' => fn (Builder $query) => $query
                ->whereIn('status', self::AWARD_SLOT_STATUSES)])
            ->orderBy('title')
            ->get();
        $finitePrograms = $programs->whereNotNull('slots_available');
        $remainingSlots = $finitePrograms->sum(fn (Scholarship $program): int => $this->remainingSlots($program) ?? 0);

        $pendingItems = (clone $pending)
            ->with(['applicant.studentProfile', 'scholarship'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (ScholarshipApplication $application): array => $this->applicationItem(
                $application,
                'Decision required',
                'attention',
                '/provider/workspaces/decisions/pending',
            ));

        $capacityByProgram = $programs->keyBy('id');
        $waitlistItems = (clone $waitlist)
            ->with(['applicant.studentProfile', 'scholarship'])
            ->orderBy('waitlist_position')
            ->orderBy('waitlisted_at')
            ->limit(20)
            ->get()
            ->sortByDesc(fn (ScholarshipApplication $application): bool => ($this->remainingSlots(
                $capacityByProgram->get($application->scholarship_id),
            ) ?? 0) > 0)
            ->take(5)
            ->map(function (ScholarshipApplication $application) use ($capacityByProgram): array {
                $remaining = $this->remainingSlots($capacityByProgram->get($application->scholarship_id));
                $label = $remaining === null
                    ? 'No slot limit'
                    : ($remaining > 0 ? $remaining.' slot'.($remaining === 1 ? '' : 's').' available' : 'Program is full');

                return $this->applicationItem(
                    $application,
                    $label,
                    $remaining === null || $remaining > 0 ? 'positive' : 'neutral',
                    '/provider/workspaces/decisions/waitlist',
                );
            })
            ->values();

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'decision-officer',
                'eyebrow' => 'Final decisions',
                'title' => 'Decision dashboard',
                'description' => 'Resolve completed applications and review the waitlist when capacity changes.',
                'icon' => 'fa-solid fa-gavel',
                'organization_name' => $context['organization_name'],
                'access_scope' => $this->accessScope($context),
            ],
            primaryAction: [
                'label' => 'Open pending decisions',
                'url' => '/provider/workspaces/decisions/pending',
            ],
            metrics: [
                [
                    'key' => 'pending',
                    'label' => 'Pending decisions',
                    'value' => (clone $pending)->count(),
                    'detail' => 'Ready for a final outcome',
                    'tone' => 'attention',
                    'url' => '/provider/workspaces/decisions/pending',
                ],
                [
                    'key' => 'waitlist',
                    'label' => 'Waitlisted',
                    'value' => (clone $waitlist)->count(),
                    'detail' => 'Review when capacity opens',
                    'url' => '/provider/workspaces/decisions/waitlist',
                ],
                [
                    'key' => 'selected',
                    'label' => 'Selected',
                    'value' => (clone $selected)->count(),
                    'detail' => 'Recorded recipients',
                    'tone' => 'positive',
                    'url' => '/provider/workspaces/decisions/recorded',
                ],
                [
                    'key' => 'capacity',
                    'label' => 'Open slots',
                    'value' => $finitePrograms->isEmpty() ? 'No limit' : $remainingSlots,
                    'detail' => $finitePrograms->isEmpty() ? 'No program limits set' : 'Across limited programs',
                ],
            ],
            sections: [
                [
                    'key' => 'pending-decisions',
                    'eyebrow' => 'Current focus',
                    'title' => 'Decisions to record',
                    'description' => 'Oldest completed application stages appear first.',
                    'empty_message' => 'No final decisions are waiting.',
                    'action' => [
                        'label' => 'Open decision queue',
                        'url' => '/provider/workspaces/decisions/pending',
                    ],
                    'items' => $pendingItems,
                ],
                [
                    'key' => 'waitlist-review',
                    'eyebrow' => 'Capacity',
                    'title' => 'Waitlist review',
                    'description' => 'Candidates with available program capacity appear first.',
                    'empty_message' => 'No applicants are currently waitlisted.',
                    'action' => [
                        'label' => 'Open waitlist',
                        'url' => '/provider/workspaces/decisions/waitlist',
                    ],
                    'items' => $waitlistItems,
                ],
            ],
        );
    }

    private function pending(Builder $query): Builder
    {
        return $query
            ->where('workflow_stage', 'decision')
            ->whereNull('final_outcome')
            ->whereNotIn('application_state', ['closed', 'withdrawn']);
    }

    private function waitlist(Builder $query): Builder
    {
        return $query->where(fn (Builder $builder) => $builder
            ->where('final_outcome', 'waitlisted')
            ->orWhere('status', 'waitlisted'));
    }

    /** @return array<string, mixed> */
    private function applicationItem(
        ScholarshipApplication $application,
        string $statusLabel,
        string $tone,
        string $returnTo,
    ): array {
        $profile = $application->applicant?->studentProfile;
        $referenceAt = $application->waitlisted_at ?? $application->submitted_at ?? $application->created_at;

        return [
            'id' => (int) $application->id,
            'title' => $application->applicant?->name ?: 'Applicant record',
            'subtitle' => $application->scholarship?->title ?: 'Scholarship program',
            'image_url' => $profile?->profile_photo_path
                ? route('provider.applications.profile-photo.view', $application, false)
                : null,
            'status' => [
                'label' => $statusLabel,
                'tone' => $tone,
            ],
            'meta' => [
                $application->waitlist_position ? 'Waitlist position '.$application->waitlist_position : 'All configured stages complete',
                $this->waitingDays($referenceAt).' day'.($this->waitingDays($referenceAt) === 1 ? '' : 's').' waiting',
            ],
            'action' => [
                'label' => 'Open record',
                'url' => route('provider.applications.show', [
                    'application' => $application,
                    'return_to' => $returnTo,
                ], false),
            ],
        ];
    }

    private function remainingSlots(?Scholarship $program): ?int
    {
        if (! $program || $program->slots_available === null) {
            return null;
        }

        return max(0, (int) $program->slots_available - (int) ($program->awarded_slots_count ?? 0));
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
