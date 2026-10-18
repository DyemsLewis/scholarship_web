<?php

use App\Domain\Recipients\RecipientSupportCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('recipient_benefit_release_allocations as allocations')
            ->join('recipient_benefit_release_records as records', 'records.id', '=', 'allocations.recipient_benefit_release_record_id')
            ->join('recipient_benefit_entitlements as entitlements', 'entitlements.id', '=', 'allocations.recipient_benefit_entitlement_id')
            ->leftJoin('recipient_benefit_receipt_responses as responses', 'responses.recipient_benefit_release_record_id', '=', 'records.id')
            ->orderBy('allocations.id')
            ->select([
                'allocations.id as allocation_id',
                'allocations.created_at as allocation_created_at',
                'entitlements.id as entitlement_id',
                'entitlements.frequency',
                'entitlements.status as entitlement_status',
                'entitlements.ready_at',
                'records.status as record_status',
                'records.released_at',
                'responses.response_type',
                'responses.status as response_status',
                'responses.resolution_outcome',
                'responses.responded_at',
                'responses.resolved_at',
            ])
            ->each(function (object $row): void {
                [$allocationStatus, $entitlementStatus, $occurredAt] = $this->lifecycleState($row);

                DB::table('recipient_benefit_release_allocations')
                    ->where('id', $row->allocation_id)
                    ->update([
                        'status' => $allocationStatus,
                        'released_at' => $row->record_status === 'released' ? $row->released_at : null,
                        'updated_at' => now(),
                    ]);

                if (($row->frequency ?: 'one_time') !== 'one_time'
                    || $row->entitlement_status === RecipientSupportCatalog::ENTITLEMENT_CANCELLED) {
                    return;
                }

                $attributes = [
                    'status' => $entitlementStatus,
                    'fulfilled_at' => null,
                    'withheld_at' => null,
                    'updated_at' => now(),
                ];

                if ($entitlementStatus === RecipientSupportCatalog::ENTITLEMENT_READY) {
                    $attributes['ready_at'] = $row->ready_at ?? $occurredAt;
                    $attributes['scheduled_at'] = null;
                } elseif ($entitlementStatus === RecipientSupportCatalog::ENTITLEMENT_SCHEDULED) {
                    $attributes['scheduled_at'] = $row->allocation_created_at ?? $occurredAt;
                } elseif ($entitlementStatus === RecipientSupportCatalog::ENTITLEMENT_FULFILLED) {
                    $attributes['fulfilled_at'] = $occurredAt;
                } elseif ($entitlementStatus === RecipientSupportCatalog::ENTITLEMENT_WITHHELD) {
                    $attributes['withheld_at'] = $occurredAt;
                }

                DB::table('recipient_benefit_entitlements')
                    ->where('id', $row->entitlement_id)
                    ->update($attributes);
            });
    }

    public function down(): void
    {
        DB::table('recipient_benefit_release_allocations')
            ->whereIn('status', ['issue_open', 'fulfilled'])
            ->update(['status' => 'released']);
        DB::table('recipient_benefit_release_allocations')
            ->where('status', 'replacement_required')
            ->update(['status' => 'missed']);
        DB::table('recipient_benefit_entitlements')
            ->whereIn('status', [
                RecipientSupportCatalog::ENTITLEMENT_RELEASED,
                RecipientSupportCatalog::ENTITLEMENT_ISSUE_OPEN,
            ])
            ->update(['status' => RecipientSupportCatalog::ENTITLEMENT_SCHEDULED]);
    }

    /** @return array{string, string, mixed} */
    private function lifecycleState(object $row): array
    {
        if ($row->response_status === 'confirmed') {
            return ['fulfilled', RecipientSupportCatalog::ENTITLEMENT_FULFILLED, $row->responded_at ?? now()];
        }

        if ($row->response_status === 'open') {
            return ['issue_open', RecipientSupportCatalog::ENTITLEMENT_ISSUE_OPEN, $row->responded_at ?? now()];
        }

        if ($row->response_status === 'resolved') {
            if ($row->resolution_outcome === 'replacement_scheduled') {
                return ['replacement_required', RecipientSupportCatalog::ENTITLEMENT_READY, $row->resolved_at ?? now()];
            }

            return ['fulfilled', RecipientSupportCatalog::ENTITLEMENT_FULFILLED, $row->resolved_at ?? now()];
        }

        return match ($row->record_status) {
            'released' => ['released', RecipientSupportCatalog::ENTITLEMENT_RELEASED, $row->released_at ?? now()],
            'missed' => ['missed', RecipientSupportCatalog::ENTITLEMENT_READY, now()],
            'withheld' => ['withheld', RecipientSupportCatalog::ENTITLEMENT_WITHHELD, now()],
            'prepared' => ['prepared', RecipientSupportCatalog::ENTITLEMENT_SCHEDULED, $row->allocation_created_at ?? now()],
            default => ['scheduled', RecipientSupportCatalog::ENTITLEMENT_SCHEDULED, $row->allocation_created_at ?? now()],
        };
    }
};
