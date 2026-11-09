<?php

namespace App\Queries\Provider\Programs;

use App\Models\Scholarship;
use App\Models\User;

final class ProgramWorkspaceLinks
{
    private const QUEUES = [
        ['reviews', 'Application review', 'Screening and returned updates', 'fa-solid fa-file-circle-check', 'verify_applications', 'provider.workspaces.reviews.all'],
        ['selection', 'Selection activities', 'Sessions and applicant results', 'fa-solid fa-calendar-check', 'manage_selection_activities', 'provider.workspaces.selection.active'],
        ['decisions', 'Final decisions', 'Pending outcomes and waitlist', 'fa-solid fa-gavel', 'record_final_decisions', 'provider.workspaces.decisions.pending'],
        ['recipients', 'Recipients', 'Agreement responses and recipients', 'fa-solid fa-user-shield', 'manage_recipients', 'provider.workspaces.recipients.agreements'],
        ['monitoring', 'Monitoring', 'Check-ins and follow-ups', 'fa-solid fa-chart-line', 'manage_monitoring', 'provider.workspaces.monitoring.review'],
        ['releases', 'Benefit releases', 'Distributions and reported issues', 'fa-solid fa-hand-holding-heart', 'manage_benefit_releases', 'provider.workspaces.releases.record'],
    ];

    public function forProgram(User $actor, Scholarship $program): array
    {
        if (! $this->isOperational($actor, $program)) {
            return ['links' => [], 'monitoring_plan_url' => null];
        }

        $links = [];
        foreach (self::QUEUES as [$key, $label, $description, $icon, $permission, $route]) {
            if ($actor->hasPortalPermission($permission)) {
                $links[] = [
                    'key' => $key,
                    'label' => $label,
                    'description' => $description,
                    'icon' => $icon,
                    'url' => route($route, ['program_id' => $program->id, 'page' => 1], false),
                ];
            }
        }

        return [
            'links' => $links,
            'monitoring_plan_url' => $actor->hasAnyPortalPermission(['manage_programs', 'manage_monitoring'])
                ? route('provider.monitoring.plan', $program, false)
                : null,
        ];
    }

    private function isOperational(User $actor, Scholarship $program): bool
    {
        if (! in_array($program->status, ['published', 'closed'], true) || ! $actor->canAccessProviderProgram($program)) {
            return false;
        }

        $owner = $actor->providerOrganizationOwner();

        return $owner->isActive() && $owner->hasVerifiedEmail() && $owner->providerProfile?->isVerified();
    }
}
