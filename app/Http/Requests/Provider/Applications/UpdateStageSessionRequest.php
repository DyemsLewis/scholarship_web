<?php

namespace App\Http\Requests\Provider\Applications;

use App\Domain\Applications\ApplicationStageCatalog;
use Illuminate\Validation\Rule;

class UpdateStageSessionRequest extends ProviderStageOperationRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'after_or_equal:now'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'timezone' => ['nullable', 'timezone:all', 'max:64'],
            'mode' => ['required', Rule::in(ApplicationStageCatalog::SESSION_MODES)],
            'venue' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('mode'), ['onsite', 'hybrid'], true)),
                'nullable',
                'string',
                'max:500',
            ],
            'location_address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'meeting_url' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('mode'), ['online', 'hybrid'], true)),
                'nullable',
                'url:http,https',
                'max:2000',
            ],
            'capacity' => ['nullable', 'integer', 'between:1,10000'],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'proof_required' => ['sometimes', 'boolean'],
        ];
    }
}
