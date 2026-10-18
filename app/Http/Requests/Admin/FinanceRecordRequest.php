<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin()
            && $this->user()->hasPortalPermission('view_finance');
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(['today', 'month', 'year', 'all'])],
            'status' => ['nullable', Rule::in(['pending', 'failed', 'all'])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:25'],
        ];
    }
}
