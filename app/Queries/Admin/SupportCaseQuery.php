<?php

namespace App\Queries\Admin;

use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class SupportCaseQuery
{
    public function summary(): array
    {
        $nextCase = $this->forQueue('needs_response')
            ->with($this->directoryRelations())
            ->oldest()
            ->first();

        return [
            'needs_response' => $this->forQueue('needs_response')->count(),
            'waiting' => $this->forQueue('waiting')->count(),
            'resolved' => $this->forQueue('resolved')->count(),
            'privacy_open' => SupportReport::query()
                ->where('admin_status', 'open')
                ->where('category', 'privacy')
                ->count(),
            'next_case' => $nextCase ? $this->directoryItem($nextCase) : null,
        ];
    }

    public function directory(array $filters): array
    {
        $queue = $filters['queue'] ?? 'needs_response';
        $query = $this->forQueue($queue)
            ->with($this->directoryRelations());

        $category = $filters['category'] ?? 'all';
        if ($category !== 'all') {
            $query->where('category', $category);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('subject', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('context', 'like', "%{$search}%")
                    ->orWhereHas('applicant', fn (Builder $reporter) => $reporter
                        ->where('email', 'like', "%{$search}%"))
                    ->orWhereHas('applicant.studentProfile', fn (Builder $profile) => $profile
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('applicant.providerProfile', fn (Builder $profile) => $profile
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('provider_name', 'like', "%{$search}%"))
                    ->orWhereHas('applicant.adminProfile', fn (Builder $profile) => $profile
                        ->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('display_name', 'like', "%{$search}%"))
                    ->orWhereHas('scholarship', fn (Builder $program) => $program
                        ->where('title', 'like', "%{$search}%"));
            });
        }

        $paginator = $query
            ->orderBy($queue === 'needs_response' ? 'created_at' : 'updated_at', $queue === 'needs_response' ? 'asc' : 'desc')
            ->paginate((int) ($filters['per_page'] ?? 10))
            ->withQueryString();

        return [
            'items' => collect($paginator->items())->map(fn (SupportReport $report): array => $this->directoryItem($report))->values(),
            'pagination' => $this->pagination($paginator),
            'categories' => $this->categories(),
        ];
    }

    public function record(SupportReport $report): array
    {
        $report->load([
            'applicant.studentProfile',
            'applicant.providerProfile',
            'applicant.adminProfile',
            'scholarship:id,title',
            'providerResolver.studentProfile',
            'providerResolver.providerProfile',
            'providerResolver.adminProfile',
            'adminResolver.studentProfile',
            'adminResolver.providerProfile',
            'adminResolver.adminProfile',
        ]);

        $item = $this->directoryItem($report);

        return [
            ...$item,
            'description' => $report->description,
            'context' => $report->context,
            'privacy_request_type' => $report->privacy_request_type,
            'privacy_request_type_label' => $report->privacy_request_type
                ? (SupportReport::PRIVACY_REQUEST_TYPES[$report->privacy_request_type] ?? 'Privacy request')
                : null,
            'contact_number' => $report->applicant?->contact_number,
            'provider_response' => $report->provider_response,
            'admin_response' => $report->admin_response,
            'provider_status' => $report->assigned_role === 'provider' ? $report->provider_status : 'not_required',
            'provider_status_label' => $report->assigned_role === 'provider' ? ucfirst($report->provider_status) : 'Not required',
            'admin_status' => $report->admin_status,
            'admin_status_label' => ucfirst($report->admin_status),
            'overall_status' => $report->status,
            'overall_status_label' => ucfirst($report->status),
            'provider_resolved_by' => $report->providerResolver?->name,
            'provider_resolved_at' => $report->provider_resolved_at?->format('M d, Y h:i A'),
            'admin_resolved_by' => $report->adminResolver?->name,
            'admin_resolved_at' => $report->admin_resolved_at?->format('M d, Y h:i A'),
            'resolved_at' => $report->resolved_at?->format('M d, Y h:i A'),
            'attachment' => filled($report->attachment_path) ? [
                'original_name' => $report->attachment_original_name,
                'mime_type' => $report->attachment_mime_type,
                'size' => $report->attachment_size,
                'view_url' => route('admin.reports.attachment', $report, false),
                'download_url' => route('admin.reports.attachment', $report, false),
            ] : null,
        ];
    }

    public function categories(): array
    {
        return collect(SupportReport::CATEGORIES)
            ->merge(SupportReport::PROVIDER_CATEGORIES)
            ->map(fn (string $label, string $value): array => compact('value', 'label'))
            ->values()
            ->all();
    }

    private function forQueue(string $queue): Builder
    {
        return match ($queue) {
            'waiting' => SupportReport::query()
                ->where('admin_status', 'resolved')
                ->where('status', 'open'),
            'resolved' => SupportReport::query()->where('status', 'resolved'),
            default => SupportReport::query()->where('admin_status', 'open'),
        };
    }

    private function directoryItem(SupportReport $report): array
    {
        $submittedByProvider = $report->applicant?->isProvider() && $report->assigned_role === 'admin';
        $categoryLabels = $submittedByProvider
            ? SupportReport::PROVIDER_CATEGORIES
            : SupportReport::CATEGORIES;
        $state = match (true) {
            $report->admin_status === 'open' => 'needs_response',
            $report->status === 'open' => 'waiting',
            default => 'resolved',
        };

        return [
            'id' => $report->id,
            'subject' => $report->subject,
            'category' => $report->category,
            'category_label' => $categoryLabels[$report->category] ?? 'Concern',
            'reporter' => [
                'name' => $report->applicant?->name ?: 'Unknown reporter',
                'email' => $report->applicant?->email,
                'type' => $submittedByProvider ? 'Provider team' : 'Applicant',
                'image_url' => $this->reporterImageUrl($report->applicant),
                'image_fit' => $submittedByProvider ? 'contain' : 'cover',
            ],
            'program' => $report->scholarship ? [
                'id' => $report->scholarship->id,
                'title' => $report->scholarship->title,
            ] : null,
            'state' => $state,
            'state_label' => match ($state) {
                'waiting' => 'Waiting for provider',
                'resolved' => 'Resolved',
                default => 'Needs response',
            },
            'coordination_label' => $report->assigned_role === 'provider'
                ? 'Provider and platform'
                : 'Platform support',
            'requires_provider' => $report->assigned_role === 'provider',
            'has_attachment' => filled($report->attachment_path),
            'submitted_at' => $report->created_at?->format('M d, Y h:i A'),
            'updated_at' => $report->updated_at?->format('M d, Y h:i A'),
            'action_url' => route('admin.workspaces.support.cases.show', $report, false),
        ];
    }

    /** @return list<string> */
    private function directoryRelations(): array
    {
        return [
            'applicant.studentProfile',
            'applicant.providerProfile',
            'applicant.adminProfile',
            'scholarship:id,title',
        ];
    }

    private function reporterImageUrl(?User $reporter): ?string
    {
        if (! $reporter) {
            return null;
        }

        if ($reporter->isProvider()) {
            $path = $reporter->providerProfile?->logo_path;

            return filled($path) ? asset(ltrim($path, '/')) : null;
        }

        if ($reporter->isApplicant() && filled($reporter->studentProfile?->profile_photo_path)) {
            return route('admin.workspaces.support.reporters.photo', $reporter, false);
        }

        return null;
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
