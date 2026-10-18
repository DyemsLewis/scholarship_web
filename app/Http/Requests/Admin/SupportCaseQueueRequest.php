<?php

namespace App\Http\Requests\Admin;

use App\Models\SupportReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportCaseQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin()
            && $this->user()->hasPortalPermission('manage_reports');
    }

    public function rules(): array
    {
        return [
            'queue' => ['nullable', Rule::in(['needs_response', 'waiting', 'resolved'])],
            'category' => ['nullable', Rule::in([
                'all',
                ...array_keys(SupportReport::CATEGORIES),
                ...array_keys(SupportReport::PROVIDER_CATEGORIES),
            ])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:25'],
        ];
    }
}
