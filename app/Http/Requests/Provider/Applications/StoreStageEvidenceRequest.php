<?php

namespace App\Http\Requests\Provider\Applications;

use App\Domain\Applications\ApplicationStageCatalog;
use Illuminate\Validation\Rule;

class StoreStageEvidenceRequest extends ProviderStageOperationRequest
{
    public function rules(): array
    {
        return [
            'evidence_file' => [
                'required',
                'file',
                'max:10240',
                'mimes:pdf,jpg,jpeg,png,webp,csv,xls,xlsx',
            ],
            'category' => ['required', Rule::in(ApplicationStageCatalog::EVIDENCE_CATEGORIES)],
            'event_date' => ['nullable', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1500'],
            'visibility' => [
                'sometimes',
                Rule::in(ApplicationStageCatalog::EVIDENCE_VISIBILITIES),
            ],
        ];
    }
}
