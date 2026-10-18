<?php

namespace App\Services;

use App\Domain\Recipients\RecipientSupportCatalog;
use App\Models\RecipientBenefitEntitlement;
use App\Models\RecipientBenefitReceiptResponse;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBenefit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RecipientBenefitEntitlementService
{
    /** @return EloquentCollection<int, RecipientBenefitEntitlement> */
    public function ensureForApplication(ScholarshipApplication $application): EloquentCollection
    {
        $application->loadMissing('scholarship.benefits');
        $program = $application->scholarship;

        if (! $program) {
            return new EloquentCollection;
        }

        foreach ($program->benefits as $benefit) {
            $entitlement = RecipientBenefitEntitlement::query()->firstOrCreate(
                [
                    'scholarship_application_id' => $application->id,
                    'scholarship_benefit_id' => $benefit->id,
                ],
                [
                    'scholarship_id' => $program->id,
                    'applicant_id' => $application->applicant_id,
                    'benefit_type' => $benefit->type,
                    'title' => $benefit->title,
                    'amount' => $benefit->amount,
                    'coverage' => $benefit->coverage,
                    'frequency' => $benefit->frequency ?: 'one_time',
                    'duration' => $benefit->duration,
                    'description' => $benefit->description,
                    'status' => $this->initialStatus($application, $benefit),
                    'assigned_at' => $application->outcome_at ?? now(),
                    'ready_at' => $application->student_response_status === 'accepted' ? now() : null,
                    'cancelled_at' => $this->isCancelled($application) ? now() : null,
                ],
            );

            if ($this->isCancelled($application)
                && $entitlement->status !== RecipientSupportCatalog::ENTITLEMENT_CANCELLED) {
                $entitlement->update([
                    'status' => RecipientSupportCatalog::ENTITLEMENT_CANCELLED,
                    'cancelled_at' => now(),
                ]);
            } elseif ($application->student_response_status === 'accepted'
                && $entitlement->status === RecipientSupportCatalog::ENTITLEMENT_PENDING_AGREEMENT) {
                $entitlement->update([
                    'status' => $entitlement->isOneTime()
                        ? RecipientSupportCatalog::ENTITLEMENT_READY
                        : RecipientSupportCatalog::ENTITLEMENT_ACTIVE,
                    'ready_at' => now(),
                ]);
            }
        }

        return $application->benefitEntitlements()->orderBy('id')->get();
    }

    /** @return EloquentCollection<int, RecipientBenefitEntitlement> */
    public function activateForAcceptedApplication(ScholarshipApplication $application): EloquentCollection
    {
        if ($application->student_response_status !== 'accepted') {
            return new EloquentCollection;
        }

        return $this->ensureForApplication($application);
    }

    /**
     * @param  array<int, int>  $benefitIds
     * @return EloquentCollection<int, ScholarshipBenefit>
     */
    public function resolveBenefitsForRelease(
        Scholarship $program,
        array $benefitIds,
        string $title,
        string $description,
        mixed $amount,
    ): EloquentCollection {
        $benefits = $program->benefits()->orderBy('sort_order')->orderBy('id')->get();

        if ($benefitIds !== []) {
            $selected = $benefits->whereIn('id', $benefitIds)->values();
            if ($selected->count() !== count(array_unique($benefitIds))) {
                throw ValidationException::withMessages([
                    'benefit_ids' => 'One or more selected benefits do not belong to this program.',
                ]);
            }

            return new EloquentCollection($selected->all());
        }

        if ($benefits->count() === 1) {
            return new EloquentCollection([$benefits->first()]);
        }

        if ($amount !== null && $amount !== '') {
            $amountMatches = $benefits->filter(
                fn (ScholarshipBenefit $benefit): bool => $benefit->amount !== null
                    && abs((float) $benefit->amount - (float) $amount) < 0.005,
            )->values();
            if ($amountMatches->count() === 1) {
                return new EloquentCollection($amountMatches->all());
            }
        }

        $releaseText = Str::lower(trim($title.' '.$description));
        $titleMatches = $benefits->filter(function (ScholarshipBenefit $benefit) use ($releaseText): bool {
            $benefitTitle = Str::lower(trim((string) $benefit->title));

            return $benefitTitle !== '' && Str::contains($releaseText, $benefitTitle);
        })->values();

        return new EloquentCollection($titleMatches->all());
    }

    /** @param EloquentCollection<int, ScholarshipBenefit> $benefits */
    public function releaseBlockReason(
        ScholarshipApplication $application,
        EloquentCollection $benefits,
    ): ?string {
        if ($benefits->isEmpty()) {
            return null;
        }

        $this->ensureForApplication($application);
        $entitlements = $application->benefitEntitlements()
            ->whereIn('scholarship_benefit_id', $benefits->modelKeys())
            ->get()
            ->keyBy('scholarship_benefit_id');

        foreach ($benefits as $benefit) {
            $entitlement = $entitlements->get($benefit->id);
            if (! $entitlement) {
                return "{$benefit->title} is not assigned to this recipient.";
            }
            if (! $entitlement->isOneTime()) {
                continue;
            }

            $reason = match ($entitlement->status) {
                RecipientSupportCatalog::ENTITLEMENT_SCHEDULED => "{$entitlement->title} already has a scheduled release.",
                RecipientSupportCatalog::ENTITLEMENT_RELEASED => "{$entitlement->title} is waiting for recipient confirmation.",
                RecipientSupportCatalog::ENTITLEMENT_ISSUE_OPEN => "{$entitlement->title} has an open recipient issue.",
                RecipientSupportCatalog::ENTITLEMENT_FULFILLED => "{$entitlement->title} was already fulfilled.",
                RecipientSupportCatalog::ENTITLEMENT_WITHHELD => "{$entitlement->title} is withheld and must be resolved first.",
                RecipientSupportCatalog::ENTITLEMENT_CANCELLED => "{$entitlement->title} is no longer available.",
                RecipientSupportCatalog::ENTITLEMENT_PENDING_AGREEMENT => "{$entitlement->title} is waiting for agreement acceptance.",
                default => null,
            };

            if ($reason) {
                return $reason;
            }
        }

        return null;
    }

    /** @param EloquentCollection<int, ScholarshipBenefit> $benefits */
    public function allocate(
        RecipientBenefitReleaseRecord $record,
        EloquentCollection $benefits,
    ): void {
        if ($benefits->isEmpty()) {
            return;
        }

        $record->loadMissing('application');
        $this->ensureForApplication($record->application);
        $entitlements = RecipientBenefitEntitlement::query()
            ->where('scholarship_application_id', $record->scholarship_application_id)
            ->whereIn('scholarship_benefit_id', $benefits->modelKeys())
            ->lockForUpdate()
            ->get();

        if ($entitlements->count() !== $benefits->count()) {
            throw ValidationException::withMessages([
                'benefit_ids' => 'The selected benefit entitlement could not be prepared for this recipient.',
            ]);
        }

        foreach ($entitlements as $entitlement) {
            if ($entitlement->isOneTime()
                && $entitlement->status !== RecipientSupportCatalog::ENTITLEMENT_READY) {
                throw ValidationException::withMessages([
                    'benefit_ids' => match ($entitlement->status) {
                        RecipientSupportCatalog::ENTITLEMENT_SCHEDULED => "{$entitlement->title} already has a scheduled release.",
                        RecipientSupportCatalog::ENTITLEMENT_FULFILLED => "{$entitlement->title} was already fulfilled.",
                        default => "{$entitlement->title} is not ready for release.",
                    },
                ]);
            }

            $record->allocations()->create([
                'recipient_benefit_entitlement_id' => $entitlement->id,
                'status' => $record->status,
                'scheduled_at' => now(),
            ]);

            if ($entitlement->isOneTime()) {
                $entitlement->update([
                    'status' => RecipientSupportCatalog::ENTITLEMENT_SCHEDULED,
                    'scheduled_at' => now(),
                ]);
            }
        }
    }

    public function syncReleaseResult(RecipientBenefitReleaseRecord $record): void
    {
        $record->loadMissing('allocations.entitlement');

        foreach ($record->allocations as $allocation) {
            $allocation->update([
                'status' => $record->status,
                'released_at' => $record->status === 'released' ? ($record->released_at ?? now()) : null,
            ]);

            $entitlement = $allocation->entitlement;
            if (! $entitlement?->isOneTime()) {
                continue;
            }

            $status = match ($record->status) {
                'released' => RecipientSupportCatalog::ENTITLEMENT_RELEASED,
                'missed' => RecipientSupportCatalog::ENTITLEMENT_READY,
                'withheld' => RecipientSupportCatalog::ENTITLEMENT_WITHHELD,
                default => RecipientSupportCatalog::ENTITLEMENT_SCHEDULED,
            };
            $this->setOneTimeStatus($entitlement, $status, $record->released_at);
        }
    }

    public function syncRecipientResponse(RecipientBenefitReceiptResponse $response): void
    {
        $response->loadMissing('releaseRecord.allocations.entitlement');
        $status = $response->response_type === 'confirmed'
            ? RecipientSupportCatalog::ENTITLEMENT_FULFILLED
            : RecipientSupportCatalog::ENTITLEMENT_ISSUE_OPEN;
        $allocationStatus = $response->response_type === 'confirmed' ? 'fulfilled' : 'issue_open';

        foreach ($response->releaseRecord?->allocations ?? [] as $allocation) {
            $allocation->update(['status' => $allocationStatus]);

            if ($allocation->entitlement?->isOneTime()) {
                $this->setOneTimeStatus(
                    $allocation->entitlement,
                    $status,
                    $response->responded_at,
                );
            }
        }
    }

    public function syncIssueResolution(RecipientBenefitReceiptResponse $response): void
    {
        $response->loadMissing('releaseRecord.allocations.entitlement');
        $replacementRequired = $response->resolution_outcome === 'replacement_scheduled';
        $entitlementStatus = $replacementRequired
            ? RecipientSupportCatalog::ENTITLEMENT_READY
            : RecipientSupportCatalog::ENTITLEMENT_FULFILLED;

        foreach ($response->releaseRecord?->allocations ?? [] as $allocation) {
            $allocation->update([
                'status' => $replacementRequired ? 'replacement_required' : 'fulfilled',
            ]);

            if ($allocation->entitlement?->isOneTime()) {
                $this->setOneTimeStatus(
                    $allocation->entitlement,
                    $entitlementStatus,
                    $response->resolved_at,
                );
            }
        }
    }

    private function initialStatus(
        ScholarshipApplication $application,
        ScholarshipBenefit $benefit,
    ): string {
        if ($this->isCancelled($application)) {
            return RecipientSupportCatalog::ENTITLEMENT_CANCELLED;
        }
        if ($application->student_response_status !== 'accepted') {
            return RecipientSupportCatalog::ENTITLEMENT_PENDING_AGREEMENT;
        }

        return ($benefit->frequency ?: 'one_time') === 'one_time'
            ? RecipientSupportCatalog::ENTITLEMENT_READY
            : RecipientSupportCatalog::ENTITLEMENT_ACTIVE;
    }

    private function isCancelled(ScholarshipApplication $application): bool
    {
        return $application->status === 'benefits_terminated'
            || $application->student_response_status === 'declined';
    }

    private function setOneTimeStatus(
        RecipientBenefitEntitlement $entitlement,
        string $status,
        mixed $occurredAt = null,
    ): void {
        $occurredAt ??= now();
        $attributes = [
            'status' => $status,
            'fulfilled_at' => null,
            'withheld_at' => null,
        ];

        if ($status === RecipientSupportCatalog::ENTITLEMENT_READY) {
            $attributes['ready_at'] = $entitlement->ready_at ?? $occurredAt;
            $attributes['scheduled_at'] = null;
        } elseif ($status === RecipientSupportCatalog::ENTITLEMENT_SCHEDULED) {
            $attributes['scheduled_at'] = $entitlement->scheduled_at ?? $occurredAt;
        } elseif ($status === RecipientSupportCatalog::ENTITLEMENT_FULFILLED) {
            $attributes['fulfilled_at'] = $occurredAt;
        } elseif ($status === RecipientSupportCatalog::ENTITLEMENT_WITHHELD) {
            $attributes['withheld_at'] = $occurredAt;
        }

        $entitlement->update($attributes);
    }
}
