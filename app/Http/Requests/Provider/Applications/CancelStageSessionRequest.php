<?php

namespace App\Http\Requests\Provider\Applications;

class CancelStageSessionRequest extends ProviderStageOperationRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:1500'],
        ];
    }
}
