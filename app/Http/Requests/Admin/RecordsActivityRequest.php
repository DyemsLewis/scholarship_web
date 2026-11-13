<?php

namespace App\Http\Requests\Admin;

use App\Support\AdminAuditTrail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordsActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin()
            && $this->user()->hasPortalPermission('view_logs');
    }

    public function rules(): array
    {
        return [
            'category' => ['nullable', Rule::in(['all', 'security', 'accounts', 'reviews', 'operations', 'exports'])],
            'actor_role' => ['nullable', Rule::in(['all', 'admin', 'provider', 'applicant', 'system'])],
            'period' => ['nullable', Rule::in(['today', 'week', 'month', 'all'])],
            'search' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:80'],
            'record_type' => ['nullable', 'required_with:record_id', Rule::in(array_keys(AdminAuditTrail::RECORDS))],
            'record_id' => ['nullable', 'required_with:record_type', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:25'],
        ];
    }
}
