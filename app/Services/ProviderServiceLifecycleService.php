<?php

namespace App\Services;

use App\Models\PortalNotification;
use App\Models\ProviderServicePurchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

class ProviderServiceLifecycleService
{
    /**
     * @var array<string, list<string>>
     */
    private const PAYMENT_TRANSITIONS = [
        'pending' => ['paid', 'failed'],
        'failed' => ['pending', 'paid'],
        'paid' => [],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const DELIVERY_TRANSITIONS = [
        'queued' => ['ready', 'needs_information', 'in_progress', 'cancelled'],
        'ready' => ['needs_information', 'in_progress', 'cancelled'],
        'needs_information' => ['ready', 'in_progress', 'cancelled'],
        'in_progress' => ['ready', 'needs_information', 'provider_review', 'cancelled'],
        'provider_review' => ['in_progress', 'completed', 'cancelled'],
        'completed' => ['in_progress'],
        'cancelled' => ['in_progress'],
    ];

    public function initialize(ProviderServicePurchase $purchase): ProviderServicePurchase
    {
        return DB::transaction(function () use ($purchase): ProviderServicePurchase {
            $locked = ProviderServicePurchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if (in_array($locked->fulfillment_status, ['in_progress', 'provider_review', 'completed'], true)) {
                $locked->started_at ??= $locked->paid_at ?: $locked->updated_at;
            }

            if ($locked->fulfillment_status === 'completed') {
                $locked->fulfilled_at ??= $locked->updated_at;
                $locked->closed_at ??= $locked->fulfilled_at;
            }

            $this->applyCurrentAction($locked);
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transitionPayment(
        ProviderServicePurchase $purchase,
        string $toStatus,
        ?User $actor = null,
        array $attributes = [],
        string $eventType = 'payment_status_changed',
    ): ProviderServicePurchase {
        return DB::transaction(function () use ($purchase, $toStatus, $actor, $attributes, $eventType): ProviderServicePurchase {
            $locked = ProviderServicePurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $fromStatus = (string) $locked->status;
            $deliveryFrom = (string) $locked->fulfillment_status;

            if ($fromStatus !== $toStatus && ! in_array($toStatus, self::PAYMENT_TRANSITIONS[$fromStatus] ?? [], true)) {
                throw new DomainException("Payment cannot move from {$fromStatus} to {$toStatus}.");
            }

            $locked->fill($attributes);
            $locked->status = $toStatus;

            if ($toStatus === 'paid') {
                $locked->paid_at ??= now();
                $locked->failed_at = null;
                $locked->failure_message = null;

                if ($locked->fulfillment_status === 'queued') {
                    $locked->fulfillment_status = 'ready';
                }
            } elseif ($toStatus === 'failed') {
                $locked->failed_at ??= now();
            }

            $this->applyCurrentAction($locked);
            $locked->save();

            if ($toStatus === 'paid' && $locked->service_request_id) {
                $locked->serviceRequest()->update([
                    'status' => 'converted',
                    'converted_at' => now(),
                ]);
            }

            if ($fromStatus !== $toStatus) {
                $this->recordEvent($locked, $actor, $eventType, [
                    'payment_from' => $fromStatus,
                    'payment_to' => $toStatus,
                    'delivery_from' => $deliveryFrom,
                    'delivery_to' => $locked->fulfillment_status,
                ]);
            }

            return $locked->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transitionDelivery(
        ProviderServicePurchase $purchase,
        string $toStatus,
        ?User $actor = null,
        array $attributes = [],
        string $eventType = 'delivery_status_changed',
    ): ProviderServicePurchase {
        return DB::transaction(function () use ($purchase, $toStatus, $actor, $attributes, $eventType): ProviderServicePurchase {
            $locked = ProviderServicePurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $fromStatus = (string) $locked->fulfillment_status;

            if ($locked->status !== 'paid') {
                throw new DomainException('Service delivery cannot change before payment is confirmed.');
            }

            if ($fromStatus !== $toStatus && ! in_array($toStatus, self::DELIVERY_TRANSITIONS[$fromStatus] ?? [], true)) {
                throw new DomainException("Service delivery cannot move from {$fromStatus} to {$toStatus}.");
            }

            $locked->fill($attributes);
            $locked->fulfillment_status = $toStatus;

            if (in_array($toStatus, ['in_progress', 'provider_review', 'completed'], true)) {
                $locked->started_at ??= now();
            }

            if ($toStatus === 'completed') {
                $locked->fulfilled_at ??= now();
                $locked->closed_at ??= now();
            } elseif ($toStatus === 'cancelled') {
                $locked->fulfilled_at = null;
                $locked->closed_at ??= now();
            } else {
                $locked->closed_at = null;
            }

            if ($toStatus === 'in_progress' && in_array($fromStatus, ['provider_review', 'completed'], true)) {
                $locked->revision_count = ((int) $locked->revision_count) + 1;
                $locked->reopened_at = now();
                $locked->fulfilled_at = null;
                $locked->provider_confirmed_at = null;
            }

            $this->applyCurrentAction($locked);
            $locked->save();

            if ($fromStatus !== $toStatus) {
                $this->recordEvent($locked, $actor, $eventType, [
                    'delivery_from' => $fromStatus,
                    'delivery_to' => $toStatus,
                ]);
            }

            return $locked->fresh();
        });
    }

    public function syncCurrentAction(ProviderServicePurchase $purchase): ProviderServicePurchase
    {
        return DB::transaction(function () use ($purchase): ProviderServicePurchase {
            $locked = ProviderServicePurchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->applyCurrentAction($locked);
            $locked->save();

            return $locked->fresh();
        });
    }

    public function canTransitionDelivery(ProviderServicePurchase $purchase, string $toStatus): bool
    {
        return $purchase->status === 'paid'
            && ($purchase->fulfillment_status === $toStatus
                || in_array($toStatus, self::DELIVERY_TRANSITIONS[$purchase->fulfillment_status] ?? [], true));
    }

    /** @param array<string, mixed> $metadata */
    public function recordAuditEvent(
        ProviderServicePurchase $purchase,
        ?User $actor,
        string $eventType,
        array $metadata = [],
    ): void {
        $purchase->lifecycleEvents()->create([
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'delivery_from' => $purchase->fulfillment_status,
            'delivery_to' => $purchase->fulfillment_status,
            'action_owner' => $purchase->current_action_owner,
            'metadata' => [
                'current_action' => $purchase->current_action,
                'action_due_at' => $purchase->action_due_at?->toISOString(),
                'revision_count' => (int) $purchase->revision_count,
                ...$metadata,
            ],
        ]);
    }

    private function applyCurrentAction(ProviderServicePurchase $purchase): void
    {
        $previousOwner = $purchase->current_action_owner;
        $previousAction = $purchase->current_action;
        $previousDueAt = $purchase->action_due_at;
        [$owner, $action] = $this->nextAction($purchase);

        $nextDueAt = match (true) {
            $owner === null => null,
            $purchase->status !== 'paid' => null,
            $owner === $previousOwner && $action === $previousAction && $previousDueAt !== null => $this->stableDueAt($purchase, $previousDueAt),
            default => $this->actionDueAt($purchase, $action),
        };

        $purchase->current_action_owner = $owner;
        $purchase->current_action = $action;
        $purchase->action_due_at = $nextDueAt;

        if ($previousOwner !== null && (
            $owner !== $previousOwner
            || $action !== $previousAction
            || $previousDueAt?->getTimestamp() !== $nextDueAt?->getTimestamp()
        )) {
            PortalNotification::query()
                ->whereNull('read_at')
                ->where('deduplication_key', 'like', "provider-service:{$purchase->id}:action:%")
                ->update(['read_at' => now()]);
        }
    }

    private function stableDueAt(ProviderServicePurchase $purchase, $previousDueAt): CarbonImmutable
    {
        $stableDueAt = CarbonImmutable::instance($previousDueAt);

        if (! $purchase->target_due_at) {
            return $stableDueAt;
        }

        $target = CarbonImmutable::instance($purchase->target_due_at);

        return $target->lessThan($stableDueAt) ? $target : $stableDueAt;
    }

    private function actionDueAt(ProviderServicePurchase $purchase, ?string $action): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $target = $purchase->target_due_at
            ? CarbonImmutable::instance($purchase->target_due_at)
            : null;

        $suggested = match ($action) {
            'Review the meeting request' => $this->meetingDecisionDueAt($purchase, $now),
            'Review the cancellation request' => $now->addDay(),
            'Provide requested information', 'Review the delivered work' => $now->addDays(3),
            'Assign and start the service' => $now->addDays(2),
            default => $now->addDays(5),
        };

        return $target && $target->lessThan($suggested) ? $target : $suggested;
    }

    private function meetingDecisionDueAt(ProviderServicePurchase $purchase, CarbonImmutable $now): CarbonImmutable
    {
        if (! $purchase->meeting_scheduled_for) {
            return $now->addDay();
        }

        $decisionDueAt = CarbonImmutable::instance($purchase->meeting_scheduled_for)->subDay();

        return $decisionDueAt->isPast() ? $now : $decisionDueAt->min($now->addDay());
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function nextAction(ProviderServicePurchase $purchase): array
    {
        if ($purchase->status === 'pending') {
            return ['provider', 'Complete service payment'];
        }

        if ($purchase->status === 'failed') {
            return ['provider', 'Restart service checkout'];
        }

        if ($purchase->status !== 'paid') {
            return ['platform', 'Review payment state'];
        }

        if ($purchase->cancellation_status === 'requested') {
            return ['platform', 'Review the cancellation request'];
        }

        return match ($purchase->fulfillment_status) {
            'needs_information' => ['provider', 'Provide requested information'],
            'provider_review' => ['provider', 'Review the delivered work'],
            'completed' => [null, null],
            'cancelled' => [null, null],
            default => $purchase->meeting_status === 'requested'
                ? ['platform', 'Review the meeting request']
                : ['platform', $purchase->fulfillment_status === 'in_progress'
                    ? 'Continue service delivery'
                    : 'Assign and start the service'],
        };
    }

    /**
     * @param  array<string, mixed>  $transition
     */
    private function recordEvent(
        ProviderServicePurchase $purchase,
        ?User $actor,
        string $eventType,
        array $transition,
    ): void {
        $purchase->lifecycleEvents()->create([
            'actor_id' => $actor?->id,
            'event_type' => $eventType,
            'payment_from' => $transition['payment_from'] ?? null,
            'payment_to' => $transition['payment_to'] ?? null,
            'delivery_from' => $transition['delivery_from'] ?? null,
            'delivery_to' => $transition['delivery_to'] ?? null,
            'action_owner' => $purchase->current_action_owner,
            'metadata' => [
                'current_action' => $purchase->current_action,
                'action_due_at' => $purchase->action_due_at?->toISOString(),
                'revision_count' => (int) $purchase->revision_count,
                'cancellation_status' => $purchase->cancellation_status,
                'cancellation_reason' => $purchase->cancellation_reason,
                'cancellation_decision_note' => $purchase->cancellation_decision_note,
                'refund_status' => $purchase->refund_status,
                'refund_reference' => $purchase->refund_reference,
            ],
        ]);
    }
}
