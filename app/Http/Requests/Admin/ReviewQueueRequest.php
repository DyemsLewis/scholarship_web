<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin()
            && $this->user()->hasPortalPermission('manage_reviews');
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', Rule::in([
                'all',
                'pending',
                'approved',
                'rejected',
                'pending_review',
                'published',
                'unsubmitted',
                'attention',
                'documented',
                'released',
                'scheduled',
                'closed',
                'open',
                'stable',
                'reviewed',
                'active',
                'draft',
                'completed',
                'cancelled',
            ])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:25'],
        ];
    }
}
