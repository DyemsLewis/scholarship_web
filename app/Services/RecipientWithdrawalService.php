<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\RecipientWithdrawalRequest;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecipientWithdrawalService
{
    private const ACTIVE_STATUSES = ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'];

    public function authorizeApplicant(User $actor, ScholarshipApplication $application): void
    {
        abort_unless($actor->isApplicant() && $actor->isActive() && $application->applicant_id === $actor->id, 403);
    }

    public function authorizeProvider(User $actor, ScholarshipApplication $application): void
    {
        $application->loadMissing('scholarship');
        abort_unless($actor->isProvider() && $actor->isActive() && $actor->hasPortalPermission('manage_recipients')
            && $actor->canAccessProviderProgram($application->scholarship)
            && $actor->providerOrganizationOwner()->providerProfile?->verification_status === 'approved', 403);
    }

    public function available(ScholarshipApplication $application): bool
    {
        return $application->final_outcome === 'selected'
            && $application->student_response_status === 'accepted'
            && in_array($application->status, self::ACTIVE_STATUSES, true)
            && ! in_array($application->supportDecisions()->first()?->decision, ['completed', 'terminated'], true)
            && ! $application->recipientWithdrawalRequests()->where('status', 'pending')->exists();
    }

    public function submit(User $actor, ScholarshipApplication $application, string $reason): RecipientWithdrawalRequest
    {
        $this->authorizeApplicant($actor, $application);

        return DB::transaction(function () use ($actor, $application, $reason): RecipientWithdrawalRequest {
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($application->id);
            $this->authorizeApplicant($actor, $application);
            abort_if($application->recipientWithdrawalRequests()->where('status', 'pending')->exists(), 409,
                'A withdrawal request is already awaiting review. Reload the current request.');
            $this->assertActiveAcceptedAward($application);

            $withdrawal = $application->recipientWithdrawalRequests()->create([
                'applicant_id' => $actor->id,
                'pending_application_id' => $application->id,
                'status' => 'pending',
                'revision' => 1,
                'reason' => trim($reason),
                'agreement_snapshot' => $application->provider_contract_terms_snapshot,
                'agreement_version' => $application->student_response_terms_version ?: $application->provider_contract_terms_version,
                'agreement_accepted_at' => $application->provider_contract_terms_accepted_at,
                'requested_at' => now(),
            ]);
            $this->recordEvent($withdrawal, $actor, null, $withdrawal->reason);
            $this->notifyOfficers($withdrawal);

            return $withdrawal;
        });
    }

    public function cancel(User $actor, RecipientWithdrawalRequest $withdrawal, int $revision): RecipientWithdrawalRequest
    {
        $this->authorizeApplicant($actor, $withdrawal->application);

        return DB::transaction(function () use ($actor, $withdrawal, $revision): RecipientWithdrawalRequest {
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($withdrawal->scholarship_application_id);
            $this->authorizeApplicant($actor, $application);
            $withdrawal = $application->recipientWithdrawalRequests()->lockForUpdate()->findOrFail($withdrawal->id);
            $this->assertPendingRevision($withdrawal, $revision);
            $withdrawal->fill([
                'status' => 'cancelled', 'revision' => $withdrawal->revision + 1,
                'pending_application_id' => null, 'resolved_at' => now(), 'resolved_by' => $actor->id,
            ])->save();
            $this->recordEvent($withdrawal, $actor, 'pending', 'The applicant cancelled this pending request.');
            $this->notifyOfficers($withdrawal);

            return $withdrawal;
        });
    }

    public function review(User $actor, RecipientWithdrawalRequest $withdrawal, array $data): RecipientWithdrawalRequest
    {
        $this->authorizeProvider($actor, $withdrawal->application);

        return DB::transaction(function () use ($actor, $withdrawal, $data): RecipientWithdrawalRequest {
            // Application-first locking serializes requests with release and support decisions.
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($withdrawal->scholarship_application_id);
            $this->authorizeProvider($actor, $application);
            $withdrawal = $application->recipientWithdrawalRequests()->lockForUpdate()->findOrFail($withdrawal->id);
            $this->assertPendingRevision($withdrawal, (int) $data['expected_revision']);
            $decision = null;

            if ($data['outcome'] === 'approved') {
                $this->assertActiveAcceptedAward($application);
                $decision = $application->supportDecisions()->create([
                    'applicant_id' => $application->applicant_id,
                    'decision' => 'terminated', 'reason_category' => 'recipient_withdrew',
                    'effective_on' => now()->toDateString(), 'notice_given_on' => now()->toDateString(),
                    'reason' => $data['review_note'], 'decided_by' => $actor->id, 'decided_at' => now(),
                ]);
                $previousStatus = $application->status;
                // Keep the selection result, accepted terms, original outcome, and prior releases intact.
                $application->update(['status' => 'benefits_terminated']);
                app(RecipientBenefitEntitlementService::class)->cancelUnfulfilledForApplication($application);
                ApplicationStatusHistory::create([
                    'scholarship_application_id' => $application->id, 'changed_by' => $actor->id,
                    'from_status' => $previousStatus, 'to_status' => 'benefits_terminated',
                    'decision_reason' => 'recipient_withdrew', 'review_notes' => $data['review_note'], 'changed_at' => now(),
                ]);
            }

            $withdrawal->fill([
                'status' => $data['outcome'], 'revision' => $withdrawal->revision + 1,
                'pending_application_id' => null, 'review_note' => $data['review_note'],
                'resolved_by' => $actor->id, 'resolved_at' => now(), 'support_decision_id' => $decision?->id,
            ])->save();
            $this->recordEvent($withdrawal, $actor, 'pending', $data['review_note']);
            PortalNotification::create([
                'user_id' => $application->applicant_id, 'type' => 'recipient_withdrawal_reviewed',
                'title' => $withdrawal->status === 'approved' ? 'Scholarship withdrawal approved' : 'Scholarship withdrawal request declined',
                'message' => $application->scholarship->title.': '.$data['review_note'],
                'action_url' => route('dashboard.applications.show.section', ['application' => $application, 'section' => 'outcome'], false).'#scholarship-withdrawal',
                'deduplication_key' => "recipient-withdrawal:{$withdrawal->id}:{$withdrawal->revision}:applicant",
            ]);

            return $withdrawal;
        });
    }

    public function payload(RecipientWithdrawalRequest $withdrawal, User $actor): array
    {
        $withdrawal->loadMissing(['events', 'application.scholarship', 'application.applicant.studentProfile']);
        $application = $withdrawal->application;
        $actor->isApplicant() ? $this->authorizeApplicant($actor, $application) : $this->authorizeProvider($actor, $application);

        return [
            'id' => $withdrawal->id, 'status' => $withdrawal->status,
            'status_label' => match ($withdrawal->status) {
                'approved' => 'Withdrawal approved', 'declined' => 'Request declined',
                'cancelled' => 'Request cancelled', default => 'Awaiting provider review',
            },
            'revision' => $withdrawal->revision, 'reason' => $withdrawal->reason,
            'review_note' => $withdrawal->review_note,
            'requested_at' => $withdrawal->requested_at->toIso8601String(),
            'resolved_at' => $withdrawal->resolved_at?->toIso8601String(),
            'program' => ['id' => $application->scholarship_id, 'title' => $application->scholarship->title],
            'applicant' => [
                'name' => $application->applicant->name,
                'profile_photo_url' => $actor->isProvider() && filled($application->applicant->studentProfile?->profile_photo_path)
                    ? route('provider.applications.profile-photo.view', $application, false) : null,
            ],
            'read_url' => route($actor->isApplicant() ? 'dashboard.recipient-withdrawals.show' : 'provider.recipient-withdrawals.show', $withdrawal, false),
            'agreement' => [
                'version' => $withdrawal->agreement_version,
                'accepted_at' => $withdrawal->agreement_accepted_at?->toIso8601String(),
                'snapshot' => $withdrawal->agreement_snapshot,
            ],
            'can_cancel' => $actor->isApplicant() && $withdrawal->status === 'pending',
            'can_review' => $actor->isProvider() && $withdrawal->status === 'pending',
            'cancel_url' => $actor->isApplicant() && $withdrawal->status === 'pending'
                ? route('dashboard.recipient-withdrawals.cancel', $withdrawal, false) : null,
            'review_url' => $actor->isProvider() && $withdrawal->status === 'pending'
                ? route('provider.recipient-withdrawals.review', $withdrawal, false) : null,
            'history' => $withdrawal->events->map(fn ($event): array => [
                'revision' => $event->revision, 'from_status' => $event->from_status,
                'to_status' => $event->to_status, 'actor_name' => $event->actor_name,
                'actor_role' => $event->actor_role, 'note' => $event->note,
                'created_at' => $event->created_at->toIso8601String(),
            ])->all(),
            'notice' => 'A pending or declined request does not change support. Approval ends future benefits from the recorded decision time; it does not erase prior releases, impose a penalty, or blacklist the applicant.',
        ];
    }

    private function assertActiveAcceptedAward(ScholarshipApplication $application): void
    {
        $latestDecision = $application->supportDecisions()->first();
        if ($application->final_outcome !== 'selected' || $application->student_response_status !== 'accepted'
            || ! in_array($application->status, self::ACTIVE_STATUSES, true)
            || in_array($latestDecision?->decision, ['completed', 'terminated'], true)) {
            throw ValidationException::withMessages([
                'withdrawal' => 'Withdrawal requests are only available for accepted awards with active support. Refresh this record if the support decision changed.',
            ]);
        }
    }

    private function assertPendingRevision(RecipientWithdrawalRequest $withdrawal, int $revision): void
    {
        abort_if($withdrawal->status !== 'pending' || $withdrawal->revision !== $revision, 409,
            'This withdrawal request changed or was already resolved. Reload it before continuing.');
    }

    private function recordEvent(RecipientWithdrawalRequest $withdrawal, User $actor, ?string $previous, string $note): void
    {
        $withdrawal->events()->create([
            'revision' => $withdrawal->revision, 'from_status' => $previous,
            'to_status' => $withdrawal->status, 'actor_id' => $actor->id,
            'actor_name' => $actor->name, 'actor_role' => $actor->role, 'note' => $note, 'created_at' => now(),
        ]);
        ActivityLog::record($actor, 'recipient_withdrawal_'.$withdrawal->status,
            'Recorded a scholarship withdrawal request transition.', null, [
                'application_id' => $withdrawal->scholarship_application_id,
                'withdrawal_request_id' => $withdrawal->id, 'revision' => $withdrawal->revision,
                'from_status' => $previous, 'to_status' => $withdrawal->status,
            ]);
    }

    private function notifyOfficers(RecipientWithdrawalRequest $withdrawal): void
    {
        $application = $withdrawal->application()->with('scholarship')->firstOrFail();
        $officers = User::query()->where('role', 'provider')->where(function ($query) use ($application): void {
            $query->whereKey($application->scholarship->provider_id)->orWhere('parent_account_id', $application->scholarship->provider_id);
        })->get()->filter(fn (User $user): bool => $user->isActive() && $user->hasPortalPermission('manage_recipients')
            && $user->canAccessProviderProgram($application->scholarship));
        foreach ($officers as $officer) {
            PortalNotification::create([
                'user_id' => $officer->id, 'type' => 'recipient_withdrawal_request',
                'title' => $withdrawal->status === 'pending' ? 'Scholarship withdrawal requested' : 'Withdrawal request cancelled',
                'message' => $application->scholarship->title.': an accepted recipient updated their withdrawal request.',
                'action_url' => route('provider.workspaces.recipients.withdrawals.show', $withdrawal, false),
                'deduplication_key' => "recipient-withdrawal:{$withdrawal->id}:{$withdrawal->revision}:{$officer->id}",
            ]);
        }
    }
}
