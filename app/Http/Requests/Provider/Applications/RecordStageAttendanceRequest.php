<?php

namespace App\Http\Requests\Provider\Applications;

use App\Domain\Applications\ApplicationStageCatalog;
use Illuminate\Validation\Rule;

class RecordStageAttendanceRequest extends ProviderStageOperationRequest
{
    public function rules(): array
    {
        return [
            'attendance_status' => ['required', Rule::in(array_diff(
                ApplicationStageCatalog::ATTENDANCE_STATUSES,
                ['pending'],
            ))],
            'notes' => ['nullable', 'string', 'max:1500'],
        ];
    }
}
