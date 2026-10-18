<?php

namespace App\Http\Requests\Provider\Applications;

class AssignStageSessionApplicationsRequest extends ProviderStageOperationRequest
{
    public function rules(): array
    {
        return [
            'application_ids' => ['required', 'array', 'min:1', 'max:500'],
            'application_ids.*' => ['required', 'integer', 'distinct'],
            'notes' => ['nullable', 'string', 'max:1500'],
        ];
    }
}
