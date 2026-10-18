<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin()
            && $this->user()->hasPortalPermission('manage_accounts');
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role' => ['sometimes', 'nullable', Rule::in(['all', 'applicant', 'provider', 'admin'])],
            'attention' => ['sometimes', 'nullable', Rule::in(['all', 'needs_attention', 'unverified', 'password_reset', 'suspended'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:50'],
        ];
    }
}
