<?php

namespace App\Http\Requests\Provider\Applications;

use App\Models\Scholarship;
use Illuminate\Foundation\Http\FormRequest;

abstract class ProviderStageOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $scholarship = $this->route('scholarship');

        return $actor?->isProvider()
            && $actor->hasPortalPermission('manage_selection_activities')
            && $scholarship instanceof Scholarship
            && (int) $scholarship->provider_id === $actor->providerOrganizationId()
            && $actor->canAccessProviderProgram($scholarship);
    }
}
