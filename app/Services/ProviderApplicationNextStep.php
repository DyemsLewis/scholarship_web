<?php

namespace App\Services;

use App\Domain\Applications\ApplicationStageCatalog;
use App\Models\ScholarshipApplication;
use App\Models\User;

final class ProviderApplicationNextStep
{
    public function forRecord(ScholarshipApplication $application, User $actor, string $workspace, array $payload): ?array
    {
        $stage = $payload['workflow_stage'] ?? $application->workflow_stage;
        $outcome = $payload['final_outcome'] ?? $application->final_outcome;
        $agreement = $payload['recipient_agreement'] ?? $payload['agreement'] ?? null;

        if ($outcome === 'selected' && ($agreement['requires_response'] ?? false)) {
            return $this->step('waiting', 'Wait for the applicant to accept the agreement',
                'Benefits cannot be released until the applicant accepts the terms.', 'Applicant',
                $workspace === 'recipient' ? null : $this->recordAction($application, $actor, 'recipient', 'manage_recipients', 'Open agreement'));
        }

        if (in_array($application->application_state, ['closed', 'withdrawn'], true) || $outcome) {
            return null;
        }

        if ($stage === 'screening' && $application->correction_status === 'requested') {
            return $this->step('waiting', 'Wait for the applicant to respond',
                'Screening cannot continue until the applicant sends the requested correction.', 'Applicant');
        }

        if ($stage === 'screening' && $workspace === 'review' && $application->correction_status === 'submitted') {
            $assignedToActor = (int) $application->assigned_reviewer_id === (int) $actor->id;

            return $this->step('handoff', 'Review the submitted update',
                'Check the corrected information before recording the screening result.', 'Assigned reviewer',
                $assignedToActor ? ['label' => 'Open screening result', 'section' => 'decision'] : null);
        }

        if (ApplicationStageCatalog::supportsSessions($stage)) {
            $stageName = str($stage)->replace('_', ' ')->toString();

            return $this->step('handoff', 'Continue to '.$stageName,
                'The selection team handles any required scheduling, evidence, and the individual outcome.', 'Selection officer',
                $this->recordAction($application, $actor, 'selection', 'manage_selection_activities', 'Open selection record'));
        }

        if ($stage === 'decision' && $workspace !== 'decision') {
            return $this->step('handoff', 'Record the final decision',
                'Earlier stages are complete. The final decision team can review the record and make the award decision.', 'Final decision officer',
                $this->recordAction($application, $actor, 'decision', 'record_final_decisions', 'Open final decision'));
        }

        return null;
    }

    public function step(string $state, string $title, string $detail, string $owner, ?array $action = null): array
    {
        return compact('state', 'title', 'detail', 'owner', 'action');
    }

    private function recordAction(ScholarshipApplication $application, User $actor, string $workspace, string $permission, string $label): ?array
    {
        return $actor->hasPortalPermission($permission) ? [
            'label' => $label,
            'url' => route('provider.applications.show', ['application' => $application, 'workspace' => $workspace], false),
        ] : null;
    }
}
