<?php

namespace App\Http\Requests\Provider\Programs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProgramDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isProvider()
            && $this->user()->hasPortalPermission('manage_programs');
    }

    public function rules(): array
    {
        return [
            'filter' => ['sometimes', Rule::in(['all', 'drafts', 'review', 'published', 'closed'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['updated', 'deadline', 'title'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filter(): string
    {
        $queryFilter = $this->validated()['filter'] ?? null;

        if (is_string($queryFilter)) {
            return $queryFilter;
        }

        $routeFilter = $this->route('programFilter');

        if (is_string($routeFilter) && in_array($routeFilter, ['all', 'drafts', 'review', 'published', 'closed'], true)) {
            return $routeFilter;
        }

        return 'all';
    }
}
