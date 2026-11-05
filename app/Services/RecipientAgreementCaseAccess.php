<?php

namespace App\Services;

use App\Domain\Recipients\RecipientAgreementCaseCatalog as Catalog;
use App\Models\RecipientAgreementCase;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class RecipientAgreementCaseAccess
{
    public function authorizeCreate(User $actor, ScholarshipApplication $application, string $type): void
    {
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->isActive(), 403);

        if ($type === Catalog::BENEFIT_DELIVERY && $actor->isApplicant()) {
            abort_unless($application->applicant_id === $actor->id, 403);

            return;
        }

        abort_unless($type === Catalog::AGREEMENT_CONCERN && $this->canUseProviderCases($actor, $type), 403);
        $application->load('scholarship');
        abort_unless($actor->canAccessProviderProgram($application->scholarship), 403);
    }

    public function authorizeDraft(User $actor, RecipientAgreementCase $case): void
    {
        abort_unless($case->opened_by === $actor->id, 403);
        $this->authorizeCreate($actor, $case->application, $case->case_type);
        abort_if($case->status !== 'draft', 409, 'This draft has changed. Reload the current case.');
    }

    public function visibleTo(User $actor): Builder
    {
        $query = RecipientAgreementCase::query();
        $actor = User::query()->find($actor->id);
        if (! $actor?->isActive()) {
            return $query->whereRaw('1 = 0');
        }

        if ($actor->isApplicant()) {
            return $query->whereHas('application', fn (Builder $q) => $q->where('applicant_id', $actor->id))
                ->where(fn (Builder $q) => $q
                    ->where('opened_by', $actor->id)
                    ->orWhere(fn (Builder $shared) => $shared->where('status', '!=', 'draft')->whereNotNull('notice_sent_at')));
        }

        if ($actor->isProvider()) {
            $types = array_values(array_filter(Catalog::TYPES, fn (string $type): bool => $this->canUseProviderCases($actor, $type)));

            return $query->whereIn('case_type', $types)
                ->whereHas('application.scholarship', function (Builder $q) use ($actor): void {
                    $q->where('provider_id', $actor->providerOrganizationId());
                    if ($actor->hasLimitedProviderProgramAccess()) {
                        $q->whereIn('id', $actor->assignedProviderProgramIds());
                    }
                })
                ->where(fn (Builder $q) => $q
                    ->where('opened_by', $actor->id)
                    ->orWhere(fn (Builder $shared) => $shared->where('status', '!=', 'draft')->whereNotNull('notice_sent_at')));
        }

        if ($actor->isAdmin() && $actor->hasPortalPermission('manage_reviews')) {
            if ($actor->isManagedAccount()) {
                $owner = $actor->parentAccount()->first();
                if (! $owner?->isAdmin() || ! $owner->isActive()) {
                    return $query->whereRaw('1 = 0');
                }
            }

            return $query->where('status', '!=', 'draft')->whereNotNull('notice_sent_at');
        }

        return $query->whereRaw('1 = 0');
    }

    public function authorizeView(User $actor, RecipientAgreementCase $case): void
    {
        abort_unless($this->visibleTo($actor)->whereKey($case->id)->exists(), 403);
    }

    private function canUseProviderCases(User $actor, string $type): bool
    {
        if (! $actor->isProvider() || ! $actor->hasPortalPermission(Catalog::providerPermission($type) ?? '')) {
            return false;
        }

        $owner = $actor->providerOrganizationOwner();

        return $owner->isActive() && $owner->providerProfile?->verification_status === 'approved';
    }
}
