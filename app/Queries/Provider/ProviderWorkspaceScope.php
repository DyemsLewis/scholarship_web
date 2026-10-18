<?php

namespace App\Queries\Provider;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ProviderWorkspaceScope
{
    public function programs(User $actor): Builder
    {
        $this->ensureProvider($actor);

        $query = Scholarship::query()
            ->where('provider_id', $actor->providerOrganizationId());

        if ($actor->hasLimitedProviderProgramAccess()) {
            $programIds = $actor->assignedProviderProgramIds();
            $query->whereIn('id', $programIds === [] ? [0] : $programIds);
        }

        return $query;
    }

    public function applications(User $actor): Builder
    {
        $programs = $this->programs($actor)->select('scholarships.id');

        return ScholarshipApplication::query()
            ->whereIn('scholarship_id', $programs);
    }

    /** @return array<string, mixed> */
    public function context(User $actor): array
    {
        $owner = $actor->providerOrganizationOwner()->loadMissing('providerProfile');

        return [
            'organization_id' => $owner->id,
            'organization_name' => $owner->providerProfile?->provider_name ?: $owner->name,
            'role' => $actor->account_title ?: 'Provider',
            'program_access_mode' => $actor->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            'assigned_program_count' => $this->programs($actor)->count(),
        ];
    }

    private function ensureProvider(User $actor): void
    {
        if (! $actor->isProvider()) {
            throw new InvalidArgumentException('Provider workspace data requires a provider account.');
        }
    }
}
