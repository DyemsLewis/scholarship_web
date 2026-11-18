<?php

namespace App\Services;

use App\Models\PortalNotification;
use App\Models\RecipientOnboardingEvent;
use App\Models\RecipientOnboardingRecord;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\RecipientOnboardingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecipientOnboardingService
{
    public function initializeForAcceptedApplication(ScholarshipApplication $application): ?RecipientOnboardingRecord
    {
        return DB::transaction(function () use ($application): ?RecipientOnboardingRecord {
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($application->id);
            $policy = $this->offerPolicy($application);
            if ($policy === null) {
                return null;
            }
            $this->assertAcceptedOffer($application);

            $record = $application->recipientOnboarding()->first();
            if ($record) {
                $this->assertOfferMatches($application, $record);

                return $record;
            }

            $record = new RecipientOnboardingRecord;
            $record->forceFill([
                'scholarship_application_id' => $application->id,
                'policy_snapshot' => $policy,
                'policy_version' => RecipientOnboardingPolicy::version($policy),
                'offer_version' => $application->provider_contract_terms_version,
                'status' => $policy['final_agreement_required'] ? 'pending' : 'not_required',
                'revision' => 1,
            ])->save();
            $this->recordEvent($record, $application->applicant, 'initialized', [
                'status' => $record->status,
                'policy_version' => $record->policy_version,
                'offer_version' => $record->offer_version,
            ]);

            return $record;
        });
    }

    public function publishInstructions(ScholarshipApplication $application, User $actor, string $instructions, int $expectedRevision): RecipientOnboardingRecord
    {
        $instructions = trim($instructions);
        Validator::make(['instructions' => $instructions], ['instructions' => ['required', 'string', 'max:3000']])->validate();

        return DB::transaction(function () use ($application, $actor, $instructions, $expectedRevision): RecipientOnboardingRecord {
            $record = $this->editableRecord($application, $actor, $expectedRevision);
            $previous = $record->instructions;
            $record->forceFill([
                'instructions' => $instructions,
                'instructions_published_by' => $actor->id,
                'instructions_published_at' => now(),
                'revision' => $record->revision + 1,
            ])->save();
            $this->recordEvent($record, $actor, 'instructions_published', ['previous' => $previous, 'instructions' => $instructions]);
            $this->notifyRecipient($record, 'instructions', 'Provider onboarding instructions', 'The provider posted instructions for completing your final agreement.');

            return $record;
        });
    }

    public function confirmCompletion(ScholarshipApplication $application, User $actor, array $input, int $expectedRevision): RecipientOnboardingRecord
    {
        $data = Validator::make(['completion' => $input], [
            'completion' => ['required', 'array:completed_on,confirmed,note'],
            'completion.completed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'completion.confirmed' => ['required', 'accepted'],
            'completion.note' => ['nullable', 'string', 'max:1000'],
        ])->validate()['completion'];

        return DB::transaction(function () use ($application, $actor, $data, $expectedRevision): RecipientOnboardingRecord {
            $record = $this->editableRecord($application, $actor, $expectedRevision);
            $application = $record->application;
            $completedOn = CarbonImmutable::parse($data['completed_on'], config('app.timezone'));
            if (! $application->student_responded_at
                || $completedOn->startOfDay()->lt($application->student_responded_at->copy()->setTimezone(config('app.timezone'))->startOfDay())) {
                throw ValidationException::withMessages(['completion.completed_on' => 'Completion cannot be earlier than the applicant confirmed intention to proceed.']);
            }

            $record->forceFill([
                'status' => 'completed',
                'completed_on' => $completedOn->toDateString(),
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'confirmation_note' => trim($data['note'] ?? '') ?: null,
                'revision' => $record->revision + 1,
            ])->save();
            $this->recordEvent($record, $actor, 'completion_confirmed', [
                'completed_on' => $record->completed_on->toDateString(),
                'note' => $record->confirmation_note,
            ]);
            $this->notifyRecipient($record, 'completion', 'Provider onboarding completed', 'The provider recorded completion of your final-agreement onboarding. This does not confirm benefit delivery.');

            return $record;
        });
    }

    public function summary(ScholarshipApplication $application): array
    {
        $policy = $this->offerPolicy($application);
        $record = $policy === null ? null : $application->recipientOnboarding()->first();
        $matches = $record && $application->final_outcome === 'selected' && $application->student_response_status === 'accepted'
            && $application->student_response_terms_version === $application->provider_contract_terms_version
            && $record->offer_version === $application->provider_contract_terms_version
            && $record->policy_version === RecipientOnboardingPolicy::version($policy);
        $completed = $matches && $record->status === 'completed' && $record->confirmed_at && $record->completed_on;
        $required = $policy['final_agreement_required'] ?? false;
        $status = ! $required ? 'not_required' : ($completed ? 'completed' : 'pending');

        return [
            'source' => $policy === null ? 'legacy_offer' : 'offer_snapshot',
            'final_agreement_required' => $required,
            'status' => $status,
            'status_label' => match ($status) {
                'completed' => 'Completed', 'pending' => 'Pending', default => 'Not required',
            },
            'policy_version' => $policy === null ? null : RecipientOnboardingPolicy::version($policy),
            'instructions' => $policy['instructions'] ?? null,
            'provider_instructions' => $matches ? $record->instructions : null,
            'instructions_published_at' => $matches ? $record->instructions_published_at?->toISOString() : null,
            'completed_on' => $completed ? $record->completed_on->toDateString() : null,
            'confirmed_at' => $completed ? $record->confirmed_at->toISOString() : null,
            'provider_confirmation_recorded' => (bool) $completed,
            'notice' => $policy === null
                ? 'No final-agreement requirement was recorded with this offer. Existing support is unchanged.'
                : 'The provider handles the final agreement outside the platform. This records provider-confirmed completion, not a stored or independently authenticated contract.',
        ];
    }

    public function distributionReadiness(ScholarshipApplication $application): array
    {
        $summary = $this->summary($application);
        $required = $summary['final_agreement_required'];
        $allowed = ! $required || $summary['status'] === 'completed';

        return [
            'required' => $required,
            'status' => $summary['status'],
            'status_label' => $summary['status_label'],
            'can_distribute' => $allowed,
            'completed_on' => $summary['completed_on'],
            'block_reason' => $allowed ? null : 'The recipient officer or manager must record completion of the disclosed final agreement before a benefit can be distributed or a transfer recorded as sent. Scheduling and preparation are still allowed.',
        ];
    }

    public function assertDistributionAllowed(ScholarshipApplication $application, ?string $transferSentOn = null): void
    {
        // The caller holds the application lock shared with onboarding confirmation.
        $readiness = $this->distributionReadiness($application);
        if (! $readiness['can_distribute']) {
            throw ValidationException::withMessages(['status' => $readiness['block_reason']]);
        }
        if ($readiness['required'] && $transferSentOn !== null
            && CarbonImmutable::parse($transferSentOn)->toDateString() < $readiness['completed_on']) {
            throw ValidationException::withMessages(['transfer_sent_on' => 'The transfer date cannot be before the final agreement was completed.']);
        }
    }

    public function authorizeView(ScholarshipApplication $application, User $actor): void
    {
        $actor = User::query()->findOrFail($actor->id);
        $application->loadMissing('scholarship');
        $owner = $actor->providerOrganizationOwner();
        abort_unless($actor->isProvider() && $actor->isActive()
            && $actor->hasAnyPortalPermission(['manage_recipients', 'manage_monitoring', 'manage_benefit_releases'])
            && $actor->canAccessProviderProgram($application->scholarship)
            && $owner->isActive() && $owner->hasVerifiedEmail() && $owner->providerProfile?->isVerified(), 403);
        abort_unless($application->final_outcome === 'selected'
            || in_array($application->status, ['awarded', 'distribution_scheduled', 'disbursed', 'renewed', 'benefits_terminated'], true), 404);
    }

    public function providerPayload(ScholarshipApplication $application, User $actor): array
    {
        $application = $application->fresh(['scholarship', 'recipientOnboarding.confirmer', 'recipientOnboarding.instructionPublisher']);
        $this->authorizeView($application, $actor);
        $actor = $actor->fresh();
        $summary = $this->summary($application);
        $record = $application->recipientOnboarding;
        $canManage = $actor->hasPortalPermission('manage_recipients');
        $accepted = $application->student_response_status === 'accepted';
        $matches = $accepted && filled($application->provider_contract_terms_version)
            && $application->student_response_terms_version === $application->provider_contract_terms_version
            && (! $record || ($record->offer_version === $application->provider_contract_terms_version
                && $record->policy_version === $summary['policy_version']));
        $ended = in_array($application->status, ['benefits_terminated', 'withdrawn'], true)
            || in_array($application->supportDecisions()->first()?->decision, ['completed', 'terminated'], true);
        $reason = match (true) {
            $summary['source'] === 'legacy_offer' => 'This offer did not record a final-agreement requirement. Existing support is unchanged; use a new intake to introduce one.',
            ! $summary['final_agreement_required'] => 'This offer does not require a final agreement. No completion record is needed.',
            $application->student_response_status === 'declined' => 'The applicant declined the offer. Onboarding cannot be started.',
            ! $accepted => 'Wait until the selected applicant confirms intention to proceed before publishing instructions or recording completion.',
            ! $matches => 'The recorded offer versions do not match. Resolve the offer record before continuing.',
            $ended => 'Scholarship support has ended. This onboarding record is read-only.',
            $summary['status'] === 'completed' => 'Completion is recorded. It cannot be silently replaced.',
            ! $canManage => 'Only the recipient officer or an authorized manager can manage onboarding.',
            default => null,
        };
        $editable = $reason === null;

        return [
            ...$summary,
            'revision' => $record?->revision ?? 1,
            'today' => now()->toDateString(),
            'minimum_completed_on' => $application->student_responded_at?->copy()->setTimezone(config('app.timezone'))->toDateString(),
            'intention_confirmed_label' => $accepted ? $application->student_responded_at?->format('M d, Y h:i A') : null,
            'completed_label' => $summary['provider_confirmation_recorded'] ? $record?->completed_on?->format('M d, Y') : null,
            'confirmed_label' => $summary['provider_confirmation_recorded'] ? $record?->confirmed_at?->format('M d, Y h:i A') : null,
            'confirmed_by_name' => $summary['provider_confirmation_recorded'] ? $record?->confirmer?->name : null,
            'confirmation_note' => $canManage ? $record?->confirmation_note : null,
            'instructions_published_label' => $record?->instructions_published_at?->format('M d, Y h:i A'),
            'instructions_published_by_name' => $record?->instructionPublisher?->name,
            'can_manage' => $canManage,
            'read_only_reason' => $reason,
            'actions' => [
                'publish' => $editable ? route('provider.recipient-onboarding.instructions', $application, false) : null,
                'complete' => $editable ? route('provider.recipient-onboarding.complete', $application, false) : null,
            ],
            'history' => $canManage && $record ? $record->events()->reorder('revision', 'desc')->get()->map(fn (RecipientOnboardingEvent $event): array => [
                'revision' => $event->revision,
                'action' => $event->action,
                'label' => match ($event->action) {
                    'instructions_published' => 'Instructions published', 'completion_confirmed' => 'Completion confirmed', default => 'Onboarding started',
                },
                'actor' => $event->actor_name,
                'recorded_label' => $event->recorded_at?->format('M d, Y h:i A'),
                'details' => $event->details,
            ])->values()->all() : [],
        ];
    }

    private function offerPolicy(ScholarshipApplication $application): ?array
    {
        $snapshot = $application->provider_contract_terms_snapshot;

        // Never infer a legacy recipient's obligations from today's program settings.
        return is_array($snapshot) && array_key_exists('onboarding', $snapshot)
            ? RecipientOnboardingPolicy::normalize($snapshot['onboarding'])
            : null;
    }

    private function assertAcceptedOffer(ScholarshipApplication $application): void
    {
        if ($application->final_outcome !== 'selected' || $application->student_response_status !== 'accepted') {
            throw ValidationException::withMessages(['onboarding' => 'The applicant must be selected and confirm intention to proceed before provider onboarding.']);
        }
        abort_unless(filled($application->provider_contract_terms_version)
            && $application->student_response_terms_version === $application->provider_contract_terms_version, 409,
            'The offer confirmation does not match the current offer. Reload this recipient record.');
    }

    private function assertOfferMatches(ScholarshipApplication $application, RecipientOnboardingRecord $record): void
    {
        abort_unless($record->offer_version === $application->provider_contract_terms_version
            && $record->policy_version === RecipientOnboardingPolicy::version($this->offerPolicy($application)), 409,
            'The recorded onboarding requirement no longer matches this offer. Reload this recipient record.');
    }

    private function editableRecord(ScholarshipApplication $application, User $actor, int $expectedRevision): RecipientOnboardingRecord
    {
        $application = ScholarshipApplication::query()->with('scholarship')->lockForUpdate()->findOrFail($application->id);
        $actor = User::query()->findOrFail($actor->id);
        $owner = $actor->providerOrganizationOwner();
        abort_unless($actor->isProvider() && $actor->isActive() && $actor->hasPortalPermission('manage_recipients')
            && $actor->canAccessProviderProgram($application->scholarship)
            && $owner->isActive() && $owner->hasVerifiedEmail() && $owner->providerProfile?->isVerified(), 403);
        $this->assertAcceptedOffer($application);
        if (in_array($application->status, ['benefits_terminated', 'withdrawn'], true)
            || in_array($application->supportDecisions()->first()?->decision, ['completed', 'terminated'], true)) {
            throw ValidationException::withMessages(['onboarding' => 'Scholarship support has ended. The onboarding record is read-only.']);
        }
        $record = $this->initializeForAcceptedApplication($application);
        if (! $record || ! $record->policy_snapshot['final_agreement_required']) {
            throw ValidationException::withMessages(['onboarding' => 'This offer has no recorded final-agreement requirement to complete.']);
        }
        abort_unless($record->revision === $expectedRevision, 409, 'Onboarding changed. Reload before saving another update.');
        if ($record->status === 'completed') {
            throw ValidationException::withMessages(['onboarding' => 'Onboarding is already completed. Its confirmation cannot be silently replaced.']);
        }

        return $record->setRelation('application', $application);
    }

    private function recordEvent(RecipientOnboardingRecord $record, ?User $actor, string $action, array $details): void
    {
        $event = new RecipientOnboardingEvent;
        $event->forceFill([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'revision' => $record->revision,
            'details' => $details,
            'recorded_at' => now(),
        ]);
        $record->events()->save($event);
    }

    private function notifyRecipient(RecipientOnboardingRecord $record, string $kind, string $title, string $message): void
    {
        $record->loadMissing('application.scholarship');
        PortalNotification::create([
            'user_id' => $record->application->applicant_id,
            'type' => 'recipient_onboarding_'.$kind,
            'title' => $title,
            'message' => $record->application->scholarship->title.': '.$message,
            'action_url' => route('dashboard.monitoring.show', $record->application, false),
            'deduplication_key' => "recipient-onboarding:{$record->id}:{$record->revision}:{$kind}",
        ]);
    }
}
