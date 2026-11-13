<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitFollowUp;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecipientBenefitFollowUpService
{
    public function authorize(User $actor, RecipientBenefitReleaseRecord $record): void
    {
        abort_unless($actor->isActive() && ($actor->isApplicant()
            ? $record->applicant_id === $actor->id
            : $actor->isProvider() && $actor->hasPortalPermission('manage_benefit_releases')
                && $actor->canAccessProviderProgram($record->release?->scholarship)
                && $actor->providerOrganizationOwner()->providerProfile?->verification_status === 'approved'), 403);
    }

    public function act(User $actor, RecipientBenefitReleaseRecord $record, array $input): RecipientBenefitFollowUp
    {
        $this->authorize($actor, $record);

        return DB::transaction(function () use ($actor, $record, $input): RecipientBenefitFollowUp {
            // Use the same application-first lock order as releases and support decisions.
            $application = ScholarshipApplication::query()->lockForUpdate()->findOrFail($record->scholarship_application_id);
            $record = RecipientBenefitReleaseRecord::query()->lockForUpdate()->findOrFail($record->id);
            $this->authorize($actor, $record);
            $action = $input['action'];
            $this->assertAvailable($record, $application, $action === 'link_replacement');
            $followUp = $record->followUp()->lockForUpdate()->first();
            abort_unless((int) $input['expected_revision'] === ($followUp?->revision ?? 0), 409, 'This follow-up changed. Reload before recording an action.');
            abort_unless($actor->isApplicant() ? in_array($action, ['continue', 'decline'], true) : in_array($action, ['notice', 'remind', 'close_unclaimed', 'link_replacement'], true), 403);

            if ($action === 'notice') {
                abort_if($followUp !== null, 409, 'A follow-up already exists for this release.');
                $deadline = $this->deadline($input['respond_by'] ?? '', 7);
                $followUp = $record->followUp()->create([
                    'status' => 'awaiting_response', 'revision' => 1, 'instructions' => $input['note'],
                    'notice_sent_at' => now(), 'respond_by' => $deadline, 'events' => [],
                ]);
            } else {
                abort_unless($followUp !== null, 409, 'Send a follow-up notice before recording this action.');
                $changes = ['revision' => $followUp->revision + 1];
                if ($action === 'remind') {
                    abort_unless($followUp->status === 'awaiting_response' && $followUp->notice_sent_at->lte(now()->subDays(2))
                        && (! $followUp->reminded_at || $followUp->reminded_at->lte(now()->subDays(2))), 409,
                        'Allow at least two days between contact attempts. A recipient response stops no-response closure.');
                    $deadline = $this->deadline($input['respond_by'] ?? '', 2);
                    $changes += ['reminded_at' => now(), 'respond_by' => $deadline->max($followUp->respond_by), 'instructions' => $input['note']];
                } elseif ($action === 'close_unclaimed') {
                    abort_unless($followUp->status === 'awaiting_response' && $followUp->respond_by->isPast()
                        && $followUp->reminded_at?->lte(now()->subDays(2)), 409,
                        'Send a reminder, allow at least two more days, and wait until the reply deadline has passed. Any reply stops no-response closure.');
                    $changes += ['status' => 'unclaimed', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => $input['note']];
                    $this->entitlementStatus($record, 'unclaimed');
                } elseif ($action === 'link_replacement') {
                    $replacementId = (int) $input['replacement_record_id'];
                    abort_unless(in_array($followUp->status, ['awaiting_response', 'responded'], true) && $this->replacements($record)->contains('id', $replacementId), 409,
                        'Choose an unused replacement release for the same recipient and benefits.');
                    $changes += ['status' => 'rescheduled', 'replacement_record_id' => $replacementId, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => $input['note']];
                } else {
                    abort_unless(in_array($followUp->status, ['awaiting_response', 'unclaimed', 'declined'], true), 409,
                        'Your response is already recorded or a replacement release has been arranged.');
                    $declined = $action === 'decline';
                    $changes += [
                        'status' => $declined ? 'declined' : 'responded', 'recipient_response' => $action,
                        'recipient_note' => $input['note'], 'responded_at' => now(),
                        'resolved_by' => $declined ? $actor->id : null, 'resolved_at' => $declined ? now() : null,
                        'resolution_note' => $declined ? 'The recipient explicitly declined this benefit.' : null,
                    ];
                    $this->entitlementStatus($record, $declined ? 'declined' : 'ready');
                }
                $followUp->update($changes);
            }

            $events = $followUp->events ?? [];
            $events[] = ['action' => $action, 'actor_role' => $actor->isApplicant() ? 'applicant' : 'provider', 'note' => $input['note'], 'at' => now()->toIso8601String(), 'respond_by' => $followUp->respond_by->toIso8601String()];
            $followUp->update(['events' => $events]);
            ActivityLog::record($actor, 'benefit_follow_up_'.$action, 'Recorded a benefit follow-up action.', metadata: [
                'release_record_id' => $record->id, 'follow_up_id' => $followUp->id, 'revision' => $followUp->revision,
            ]);
            $this->notify($actor, $record, $followUp, $action);

            return $followUp;
        });
    }

    public function payload(RecipientBenefitReleaseRecord $record, ?User $actor, bool $reportOpen = false): ?array
    {
        if ($record->status !== 'missed') {
            return null;
        }
        $record->loadMissing(['followUp', 'application.supportDecisions', 'release']);
        $followUp = $record->followUp;
        $status = $followUp?->status ?? 'not_started';
        if (in_array($status, ['not_started', 'awaiting_response', 'responded'], true) && $this->hasOneTimeReplacement($record)) {
            $status = 'rescheduled';
        }
        $active = $this->active($record->application);
        $provider = $actor?->isProvider() && $actor->hasPortalPermission('manage_benefit_releases');
        $canAct = $active && ! $reportOpen && $status !== 'rescheduled' && $record->release?->release_at?->isPast();
        $canArrange = $provider && $canAct && in_array($status, ['awaiting_response', 'responded'], true);

        return [
            'status' => $status, 'revision' => $followUp?->revision ?? 0,
            'label' => match ($status) {
                'awaiting_response' => 'Awaiting recipient reply', 'responded' => 'New arrangement requested',
                'unclaimed' => 'Unclaimed - no response', 'declined' => 'Benefit declined by recipient',
                'rescheduled' => 'Replacement release scheduled', default => 'Follow-up needed',
            },
            'instructions' => $followUp?->instructions, 'respond_by' => $followUp?->respond_by->toDateString(),
            'deadline_label' => $followUp?->respond_by->format('M d, Y'),
            'deadline_passed' => (bool) $followUp?->respond_by->isPast(),
            'recipient_note' => $followUp?->recipient_note, 'resolution_note' => $followUp?->resolution_note,
            'events' => $followUp?->events ?? [],
            'replacements' => $canArrange ? $this->replacements($record)->map(fn ($item) => ['id' => $item->id, 'label' => $item->release->title.' / '.$item->release->release_at->format('M d, Y')])->values() : [],
            'schedule_url' => $canArrange ? '/provider/workspaces/releases/schedule?program_id='.$record->release->scholarship_id : null,
            'can_notice' => $provider && $canAct && ! $followUp,
            'can_remind' => $provider && $canAct && $followUp?->status === 'awaiting_response'
                && $followUp->notice_sent_at->lte(now()->subDays(2)) && (! $followUp->reminded_at || $followUp->reminded_at->lte(now()->subDays(2))),
            'can_close' => $provider && $canAct && $followUp?->status === 'awaiting_response'
                && $followUp->respond_by->isPast() && (bool) $followUp->reminded_at?->lte(now()->subDays(2)),
            'can_reply' => $actor?->isApplicant() && $actor->id === $record->applicant_id && $canAct
                && in_array($followUp?->status, ['awaiting_response', 'unclaimed', 'declined'], true),
            'action_url' => route($provider ? 'provider.benefit-follow-ups.action' : 'dashboard.benefit-follow-ups.action', $record, false),
        ];
    }

    public function requiresAttention(RecipientBenefitReleaseRecord $record): bool
    {
        return $record->status === 'missed'
            && ! in_array($record->followUp?->status, ['unclaimed', 'declined'], true)
            && ! ($record->followUp?->status === 'rescheduled' && $record->followUp->replacement_record_id)
            && ! $this->hasOneTimeReplacement($record);
    }

    private function hasOneTimeReplacement(RecipientBenefitReleaseRecord $record): bool
    {
        $record->loadMissing('allocations.entitlement');

        return $record->allocations->isNotEmpty() && $record->allocations->every(fn ($allocation) => $allocation->entitlement?->isOneTime()
            && $allocation->entitlement->allocations()->where('id', '>', $allocation->id)->exists());
    }

    public function markOneTimeReplacement(RecipientBenefitReleaseRecord $replacement): void
    {
        $replacement->loadMissing('allocations.entitlement');
        $oneTimeIds = $replacement->allocations->where('entitlement.frequency', 'one_time')->pluck('recipient_benefit_entitlement_id');
        if ($oneTimeIds->isEmpty()) {
            return;
        }
        $followUps = RecipientBenefitFollowUp::query()->whereIn('status', ['awaiting_response', 'responded'])
            ->whereHas('releaseRecord.allocations', fn ($query) => $query->whereIn('recipient_benefit_entitlement_id', $oneTimeIds))
            ->with('releaseRecord.allocations')->lockForUpdate()->get();
        foreach ($followUps as $followUp) {
            if ($followUp->releaseRecord->allocations->pluck('recipient_benefit_entitlement_id')->diff($oneTimeIds)->isNotEmpty()) {
                continue;
            }
            $events = $followUp->events;
            $events[] = ['action' => 'rescheduled', 'actor_role' => 'provider', 'note' => 'A replacement release was scheduled. Receipt is not yet confirmed.', 'at' => now()->toIso8601String()];
            $followUp->update(['status' => 'rescheduled', 'revision' => $followUp->revision + 1, 'replacement_record_id' => $replacement->id, 'resolved_at' => now(), 'events' => $events]);
            break;
        }
    }

    private function replacements(RecipientBenefitReleaseRecord $record): \Illuminate\Support\Collection
    {
        $record->loadMissing(['allocations', 'release']);
        $ids = $record->allocations->pluck('recipient_benefit_entitlement_id')->sort()->values();
        return $record->application->benefitReleaseRecords()->whereKeyNot($record->id)->whereIn('status', ['scheduled', 'prepared', 'released'])
            ->whereHas('release', fn ($query) => $query->where('release_at', '>', $record->release->release_at))
            ->whereNotIn('id', RecipientBenefitFollowUp::query()->whereNotNull('replacement_record_id')->select('replacement_record_id'))
            ->with(['release', 'allocations'])->get()->filter(function ($candidate) use ($record, $ids): bool {
                if ($ids->isNotEmpty()) {
                    return $ids->all() === $candidate->allocations->pluck('recipient_benefit_entitlement_id')->sort()->values()->all();
                }

                return $candidate->release->benefit_description === $record->release->benefit_description
                    && $candidate->release->amount === $record->release->amount;
            });
    }

    private function deadline(string $value, int $days): Carbon
    {
        $date = Carbon::parse($value)->endOfDay();
        if ($date->lt(now()->addDays($days)) || $date->gt(now()->addDays(30)->endOfDay())) {
            throw ValidationException::withMessages(['respond_by' => "Allow at least {$days} days to reply, and choose a date within 30 days."]);
        }

        return $date;
    }

    private function active(ScholarshipApplication $application): bool
    {
        return $application->final_outcome === 'selected' && $application->student_response_status === 'accepted'
            && in_array($application->status, ['awarded', 'distribution_scheduled', 'disbursed', 'renewed'], true)
            && ! in_array($application->supportDecisions->first()?->decision, ['completed', 'terminated'], true);
    }

    private function assertAvailable(RecipientBenefitReleaseRecord $record, ScholarshipApplication $application, bool $linkingReplacement): void
    {
        abort_unless($this->active($application) && $record->status === 'missed' && ! $record->receiptResponse()->exists(), 409,
            'Follow-up is only available for an undelivered missed release with active, accepted support.');
        abort_if($record->release?->release_at?->isFuture(), 409, 'Wait until the release appointment has passed.');
        abort_if(app(BenefitDeliveryReportService::class)->openForRecord($record)->exists(), 409,
            'Resolve the open delivery report first. It cannot be closed as an unclaimed benefit.');
        foreach ($linkingReplacement ? [] : $record->allocations()->with('entitlement')->get() as $allocation) {
            if ($allocation->entitlement?->isOneTime()) {
                abort_if($allocation->entitlement->allocations()->where('id', '>', $allocation->id)->exists(), 409,
                    'A replacement release already exists for this benefit. Open the latest release.');
            }
        }
    }

    private function entitlementStatus(RecipientBenefitReleaseRecord $record, string $status): void
    {
        foreach ($record->allocations()->with('entitlement')->get() as $allocation) {
            $entitlement = $allocation->entitlement;
            if ($entitlement?->isOneTime() && in_array($entitlement->status, ['ready', 'unclaimed', 'declined'], true)) {
                $entitlement->update(['status' => $status]);
            }
        }
    }

    private function notify(User $actor, RecipientBenefitReleaseRecord $record, RecipientBenefitFollowUp $followUp, string $action): void
    {
        $applicantUrl = route('dashboard.monitoring.benefits.show', ['application' => $record->scholarship_application_id, 'record' => 'release-'.$record->id], false);
        $providerUrl = '/provider/workspaces/releases/distributions/'.$record->recipient_benefit_release_id;
        $users = $actor->isApplicant()
            ? User::query()->where('role', 'provider')->where(function ($query) use ($record): void {
                $query->whereKey($record->release->scholarship->provider_id)->orWhere('parent_account_id', $record->release->scholarship->provider_id);
            })->get()
                ->filter(fn (User $user) => $user->isActive() && $user->hasPortalPermission('manage_benefit_releases') && $user->canAccessProviderProgram($record->release->scholarship))
            : collect([$record->applicant]);
        foreach ($users as $user) {
            PortalNotification::create([
                'user_id' => $user->id, 'type' => 'benefit_follow_up',
                'title' => match ($action) {
                    'notice', 'remind' => 'Reply about your missed benefit release', 'close_unclaimed' => 'Benefit recorded as unclaimed',
                    'decline' => 'Recipient declined a benefit', 'link_replacement' => 'Replacement benefit release recorded',
                    default => 'Recipient requested a new benefit arrangement',
                },
                'message' => $record->release->title.': '.($actor->isApplicant() ? 'A recipient response is available. Review the benefit follow-up.'
                    : match ($action) {
                        'close_unclaimed' => 'No receipt has been recorded. This does not withdraw you from the scholarship. Open the record to ask for help.',
                        'link_replacement' => 'A replacement release is linked to this missed benefit. Check your latest benefit schedule.',
                        default => 'Open the follow-up and reply by '.$followUp->respond_by->format('M d, Y').'.',
                    }),
                'action_url' => $actor->isApplicant() ? $providerUrl : $applicantUrl,
                'deduplication_key' => "benefit-follow-up:{$followUp->id}:{$followUp->revision}:{$user->id}",
            ]);
        }
    }
}
