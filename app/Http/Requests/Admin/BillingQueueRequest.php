<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin()
            && $this->user()->hasPortalPermission('manage_billing');
    }

    public function rules(): array
    {
        return [
            'queue' => ['nullable', Rule::in(['ready', 'active', 'waiting', 'completed'])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:25'],
        ];
    }
}
