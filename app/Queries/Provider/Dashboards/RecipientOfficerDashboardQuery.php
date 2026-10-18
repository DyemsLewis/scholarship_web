<?php

namespace App\Queries\Provider\Dashboards;

use App\Domain\Shared\Dashboard\RoleDashboardPayload;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Provider\ProviderWorkspaceScope;
use App\Support\RecipientAgreement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class RecipientOfficerDashboardQuery
{
    private const AWARD_STATUSES = ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'];

    public function __construct(private readonly ProviderWorkspaceScope $scope) {}

    /** @return array<string, mixed> */
    public function get(User $officer): array
    {
        $context = $this->scope->context($officer);
        $base = $this->recipients($officer);
        $awaiting = $this->queue(clone $base, 'awaiting');
        $active = $this->queue(clone $base, 'active');
        $declined = $this->queue(clone $base, 'declined');
        $closed = $this->queue(clone $base, 'closed');

        $responses = (clone $declined)
            ->with(['applicant.studentProfile', 'scholarship', 'supportDecisions.decider'])
            ->oldest('student_responded_at')
            ->limit(5)
            ->get()
            ->concat((clone $awaiting)
                ->with(['applicant.studentProfile', 'scholarship', 'supportDecisions.decider'])
                ->oldest('outcome_at')
                ->limit(5)
                ->get())
            ->take(5)
            ->map(fn (ScholarshipApplication $application): array => $this->item($application));

        $recent = (clone $active)
            ->with(['applicant.studentProfile', 'scholarship', 'supportDecisions.decider'])
            ->latest('student_responded_at')
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (ScholarshipApplication $application): array => $this->item($application));

        return RoleDashboardPayload::make(
            workspace: [
                'key' => 'recipient-officer',
                'eyebrow' => 'Recipient onboarding',
                'title' => 'Recipient dashboard',
                'description' => 'Resolve agreement responses before managing active scholarship support.',
                'icon' => 'fa-solid fa-user-shield',
                'organization_name' => $context['organization_name'],
                'access_scope' => $this->accessScope($context),
            ],
            primaryAction: [
                'label' => 'Open agreement responses',
                'url' => '/provider/workspaces/recipients/agreements',
            ],
            metrics: [
                [
                    'key' => 'awaiting',
                    'label' => 'Awaiting response',
                    'value' => (clone $awaiting)->count(),
                    'detail' => 'Applicant action needed',
                    'tone' => 'attention',
                    'url' => '/provider/workspaces/recipients/agreements',
                ],
                [
                    'key' => 'active',
                    'label' => 'Active recipients',
                    'value' => (clone $active)->count(),
                    'detail' => 'Support accepted',
                    'tone' => 'positive',
                    'url' => '/provider/workspaces/recipients/active',
                ],
                [
                    'key' => 'declined',
                    'label' => 'Declined',
                    'value' => (clone $declined)->count(),
                    'detail' => 'Responses to review',
                    'url' => '/provider/workspaces/recipients/declined',
                ],
                [
                    'key' => 'closed',
                    'label' => 'Closed support',
                    'value' => (clone $closed)->count(),
                    'detail' => 'Completed or ended',
                    'url' => '/provider/workspaces/recipients/closed',
                ],
            ],
            sections: [
                [
                    'key' => 'agreement-priorities',
                    'eyebrow' => 'Current focus',
                    'title' => 'Agreement priorities',
                    'description' => 'Declined responses appear first, followed by the longest waiting selections.',
                    'empty_message' => 'No agreement response currently needs attention.',
                    'action' => [
                        'label' => 'Open response queue',
                        'url' => '/provider/workspaces/recipients/agreements',
                    ],
                    'items' => $responses,
                ],
                [
                    'key' => 'recent-onboarding',
                    'eyebrow' => 'Recently accepted',
                    'title' => 'Ready for support',
                    'description' => 'Recipients who most recently accepted their scholarship terms.',
                    'empty_message' => 'Accepted recipients will appear here.',
                    'action' => [
                        'label' => 'View active recipients',
                        'url' => '/provider/workspaces/recipients/active',
                    ],
                    'items' => $recent,
                ],
            ],
        );
    }

    private function recipients(User $officer): Builder
    {
        return $this->scope->applications($officer)
            ->where(fn (Builder $query) => $query
                ->where('final_outcome', 'selected')
                ->orWhereIn('status', [...self::AWARD_STATUSES, 'benefits_terminated']));
    }

    private function queue(Builder $query, string $queue): Builder
    {
        $closed = static fn (Builder $builder) => $builder
            ->where('status', 'benefits_terminated')
            ->orWhereHas('supportDecisions', fn (Builder $decisions) => $decisions
                ->whereIn('decision', ['completed', 'terminated']));

        return match ($queue) {
            'awaiting' => $query->whereNull('student_response_status')->whereNot($closed),
            'active' => $query->where('student_response_status', 'accepted')->whereNot($closed),
            'declined' => $query->where('student_response_status', 'declined'),
            default => $query->where($closed),
        };
    }

    /** @return array<string, mixed> */
    private function item(ScholarshipApplication $application): array
    {
        $agreement = RecipientAgreement::payload($application);
        $status = $agreement['status'] ?? 'pending';
        $referenceAt = $application->student_responded_at ?? $application->outcome_at ?? $application->created_at;
        $declined = $status === 'declined';
        $accepted = $status === 'accepted';

        return [
            'id' => (int) $application->id,
            'title' => $application->applicant?->name ?: 'Recipient record',
            'subtitle' => $application->scholarship?->title ?: 'Scholarship program',
            'image_url' => $application->applicant?->studentProfile?->profile_photo_path
                ? route('provider.applications.profile-photo.view', $application, false)
                : null,
            'status' => [
                'label' => $declined ? 'Agreement declined' : ($accepted ? 'Accepted' : 'Awaiting response'),
                'tone' => $accepted ? 'positive' : 'attention',
            ],
            'meta' => [
                $declined
                    ? 'Response received '.($referenceAt?->format('M d, Y') ?: 'recently')
                    : $this->waitingDays($referenceAt).' day'.($this->waitingDays($referenceAt) === 1 ? '' : 's').' waiting',
                $accepted ? 'Ready for support management' : 'Recipient terms issued',
            ],
            'action' => [
                'label' => $accepted ? 'Manage support' : 'Open agreement',
                'url' => route('provider.applications.show', [
                    'application' => $application,
                    'return_to' => $accepted
                        ? '/provider/workspaces/recipients/active'
                        : ($declined
                            ? '/provider/workspaces/recipients/declined'
                            : '/provider/workspaces/recipients/agreements'),
                ], false),
            ],
        ];
    }

    private function waitingDays(mixed $date): int
    {
        return $date
            ? max(0, (int) CarbonImmutable::parse($date)->startOfDay()->diffInDays(CarbonImmutable::today()))
            : 0;
    }

    /** @param array<string, mixed> $context */
    private function accessScope(array $context): string
    {
        return $context['program_access_mode'] === 'selected'
            ? $context['assigned_program_count'].' assigned programs'
            : 'All organization programs';
    }
}
