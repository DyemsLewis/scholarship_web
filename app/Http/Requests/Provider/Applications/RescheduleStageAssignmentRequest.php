<?php

namespace App\Http\Requests\Provider\Applications;

class RescheduleStageAssignmentRequest extends ProviderStageOperationRequest
{
    public function rules(): array
    {
        return [
            'target_session_id' => ['required', 'integer', 'exists:application_stage_sessions,id'],
            'notes' => ['required', 'string', 'max:1500'],
        ];
    }
}
