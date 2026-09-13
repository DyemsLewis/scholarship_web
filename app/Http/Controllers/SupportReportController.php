<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\SupportReport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SupportReportController extends Controller
{
    public function applicantPage(Request $request): View
    {
        abort_unless($request->user()?->isApplicant(), 403);

        return view('dashboard-reports');
    }

    public function applicantData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $reports = SupportReport::query()
            ->with('scholarship:id,title')
            ->where('applicant_id', $request->user()->id)
            ->latest()
            ->paginate(8);

        $programs = $this->reportableScholarships($request->user())
            ->orderBy('title')
            ->get(['id', 'title']);

        return response()->json([
            'categories' => collect(SupportReport::CATEGORIES)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values(),
            'privacy_request_types' => collect(SupportReport::PRIVACY_REQUEST_TYPES)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values(),
            'programs' => $programs,
            'reports' => collect($reports->items())
                ->map(fn (SupportReport $report): array => $this->reportPayload($report))
                ->values(),
            'pagination' => $this->paginationPayload($reports),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(SupportReport::CATEGORIES))],
            'privacy_request_type' => [
                Rule::requiredIf($request->input('category') === 'privacy'),
                'nullable',
                Rule::in(array_keys(SupportReport::PRIVACY_REQUEST_TYPES)),
            ],
            'scholarship_id' => [
                Rule::requiredIf($request->input('category') === 'program'),
                'nullable',
                'integer',
            ],
            'subject' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $scholarship = null;

        if ($validated['category'] === 'program') {
            $scholarship = $this->reportableScholarships($request->user())
                ->whereKey($validated['scholarship_id'])
                ->first();

            if (! $scholarship) {
                throw ValidationException::withMessages([
                    'scholarship_id' => 'Choose a program that is available to you.',
                ]);
            }
        }

        $report = SupportReport::create([
            'applicant_id' => $request->user()->id,
            'scholarship_id' => $scholarship?->id,
            'provider_id' => $scholarship?->provider_id,
            'assigned_role' => $scholarship ? 'provider' : 'admin',
            'category' => $validated['category'],
            'privacy_request_type' => $validated['category'] === 'privacy'
                ? $validated['privacy_request_type']
                : null,
            'subject' => trim($validated['subject']),
            'description' => trim($validated['description']),
            'status' => 'open',
            'provider_status' => $scholarship ? 'open' : 'not_required',
            'admin_status' => 'open',
        ]);

        if ($scholarship) {
            User::query()
                ->where('role', 'provider')
                ->where(function ($query) use ($scholarship): void {
                    $query
                        ->whereKey($scholarship->provider_id)
                        ->orWhere('parent_account_id', $scholarship->provider_id);
                })
                ->get()
                ->filter(fn (User $provider) => $provider->hasPortalPermission('manage_reports'))
                ->each(fn (User $provider) => PortalNotification::create([
                    'user_id' => $provider->id,
                    'type' => 'support_report',
                    'title' => 'New program concern',
                    'message' => "{$request->user()->name} reported a concern about {$scholarship->title}.",
                    'action_url' => '/provider/reports',
                ]));
        }

        User::query()
            ->where('role', 'admin')
            ->get()
            ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reports'))
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'support_report',
                'title' => $scholarship
                    ? 'New program concern'
                    : ($validated['category'] === 'privacy' ? 'New privacy request' : 'New applicant report'),
                'message' => $scholarship
                    ? "{$request->user()->name} reported a concern about {$scholarship->title}."
                    : ($validated['category'] === 'privacy'
                        ? "{$request->user()->name} submitted a ".(SupportReport::PRIVACY_REQUEST_TYPES[$validated['privacy_request_type']] ?? 'privacy request').'.'
                        : "{$request->user()->name} submitted a {$validated['category']} concern."),
                'action_url' => '/admin/reports',
            ]));

        ActivityLog::record(
            $request->user(),
            'support_report_created',
            "{$request->user()->name} submitted support report #{$report->id}.",
            $request,
            [
                'support_report_id' => $report->id,
                'assigned_role' => $report->assigned_role,
                'scholarship_id' => $report->scholarship_id,
                'privacy_request_type' => $report->privacy_request_type,
            ],
        );

        return response()->json([
            'message' => $scholarship
                ? 'Your report was sent to the scholarship provider and platform support.'
                : 'Your report was sent to platform support.',
            'report' => $this->reportPayload($report->load('scholarship:id,title')),
        ], 201);
    }

    public function providerPage(Request $request): View
    {
        abort_unless($request->user()?->isProvider(), 403);

        return view('provider-reports');
    }

    public function providerData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $providerId = $request->user()->providerOrganizationId();

        return $this->queueResponse(
            $request,
            SupportReport::query()
                ->where('provider_id', $providerId),
            'provider',
            [
                'categories' => collect(SupportReport::PROVIDER_CATEGORIES)
                    ->map(fn (string $label, string $value): array => compact('value', 'label'))
                    ->values(),
                'programs' => Scholarship::query()
                    ->where('provider_id', $providerId)
                    ->orderBy('title')
                    ->get(['id', 'title']),
            ],
        );
    }

    public function storeProvider(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $providerId = $request->user()->providerOrganizationId();
        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(SupportReport::PROVIDER_CATEGORIES))],
            'scholarship_id' => ['nullable', 'integer'],
            'context' => ['nullable', 'string', 'max:255'],
            'subject' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'attachment_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $scholarship = null;

        if (filled($validated['scholarship_id'] ?? null)) {
            $scholarship = Scholarship::query()
                ->where('provider_id', $providerId)
                ->whereKey($validated['scholarship_id'])
                ->first();

            if (! $scholarship) {
                throw ValidationException::withMessages([
                    'scholarship_id' => 'Choose a program managed by your organization.',
                ]);
            }
        }

        $report = SupportReport::create([
            'applicant_id' => $request->user()->id,
            'scholarship_id' => $scholarship?->id,
            'provider_id' => $providerId,
            'assigned_role' => 'admin',
            'category' => $validated['category'],
            'subject' => trim($validated['subject']),
            'description' => trim($validated['description']),
            'context' => filled($validated['context'] ?? null) ? trim($validated['context']) : null,
            'status' => 'open',
            'provider_status' => 'not_required',
            'admin_status' => 'open',
        ]);

        if ($request->hasFile('attachment_file')) {
            $file = $request->file('attachment_file');
            $path = $file->store("support-reports/{$providerId}", 'local');

            if (! $path) {
                $report->delete();

                throw ValidationException::withMessages([
                    'attachment_file' => 'The attachment could not be stored. Please try again.',
                ]);
            }

            $report->update([
                'attachment_path' => $path,
                'attachment_original_name' => $file->getClientOriginalName(),
                'attachment_mime_type' => $file->getMimeType(),
                'attachment_size' => $file->getSize(),
            ]);
        }

        User::query()
            ->where('role', 'admin')
            ->get()
            ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reports'))
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'support_report',
                'title' => 'New provider support report',
                'message' => "{$request->user()->name} reported a {$validated['category']} concern for their provider organization.",
                'action_url' => '/admin/reports',
            ]));

        ActivityLog::record(
            $request->user(),
            'provider_support_report_created',
            "{$request->user()->name} submitted provider support report #{$report->id}.",
            $request,
            [
                'support_report_id' => $report->id,
                'provider_id' => $providerId,
                'scholarship_id' => $report->scholarship_id,
            ],
        );

        return response()->json([
            'message' => 'Your report was sent to platform support.',
            'report' => $this->reportPayload(
                $report->fresh()->load(['applicant:id,role,first_name,last_name,email', 'scholarship:id,title']),
                true,
                'provider',
            ),
        ], 201);
    }

    public function viewAttachment(Request $request, SupportReport $report)
    {
        $user = $request->user();
        abort_unless($user?->isAdmin() || $user?->isProvider(), 403);

        if ($user->isProvider()) {
            abort_unless($report->provider_id === $user->providerOrganizationId(), 403);
        }

        abort_if(blank($report->attachment_path) || ! Storage::disk('local')->exists($report->attachment_path), 404);

        return Storage::disk('local')->response(
            $report->attachment_path,
            $report->attachment_original_name ?: 'support-attachment',
            ['Content-Type' => $report->attachment_mime_type ?: 'application/octet-stream'],
        );
    }

    public function adminPage(Request $request): View
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return view('admin-reports');
    }

    public function adminData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return $this->queueResponse(
            $request,
            SupportReport::query(),
            'admin',
        );
    }

    public function updateStatus(Request $request, SupportReport $report): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->isProvider() || $user?->isAdmin(), 403);

        if ($user->isProvider()) {
            abort_unless(
                $report->assigned_role === 'provider'
                    && $report->provider_id === $user->providerOrganizationId(),
                403,
            );
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'resolved'])],
        ]);

        $viewerRole = $user->isAdmin() ? 'admin' : 'provider';
        $roleStatusColumn = "{$viewerRole}_status";
        $roleResolverColumn = "{$viewerRole}_resolved_by";
        $roleResolvedAtColumn = "{$viewerRole}_resolved_at";

        [$report, $roleStatusChanged, $overallStatusChanged] = DB::transaction(function () use (
            $report,
            $user,
            $validated,
            $roleStatusColumn,
            $roleResolverColumn,
            $roleResolvedAtColumn,
        ): array {
            $lockedReport = SupportReport::query()
                ->whereKey($report->id)
                ->lockForUpdate()
                ->firstOrFail();
            $previousOverallStatus = $lockedReport->status;
            $roleStatusChanged = $lockedReport->{$roleStatusColumn} !== $validated['status'];

            $lockedReport->{$roleStatusColumn} = $validated['status'];
            $lockedReport->{$roleResolverColumn} = $validated['status'] === 'resolved' ? $user->id : null;
            $lockedReport->{$roleResolvedAtColumn} = $validated['status'] === 'resolved' ? now() : null;

            $providerComplete = $lockedReport->assigned_role !== 'provider'
                || $lockedReport->provider_status === 'resolved';
            $adminComplete = $lockedReport->admin_status === 'resolved';
            $lockedReport->status = $providerComplete && $adminComplete ? 'resolved' : 'open';
            $overallStatusChanged = $previousOverallStatus !== $lockedReport->status;

            if ($overallStatusChanged) {
                $lockedReport->resolved_by = $lockedReport->status === 'resolved' ? $user->id : null;
                $lockedReport->resolved_at = $lockedReport->status === 'resolved' ? now() : null;
            }

            $lockedReport->save();

            return [$lockedReport, $roleStatusChanged, $overallStatusChanged];
        });

        if ($overallStatusChanged) {
            $report->loadMissing('applicant');
            $actionUrl = $report->applicant?->isProvider()
                ? '/provider/reports'
                : '/dashboard/reports';

            PortalNotification::create([
                'user_id' => $report->applicant_id,
                'type' => 'support_report_status',
                'title' => $report->status === 'resolved' ? 'Report resolved' : 'Report reopened',
                'message' => $report->status === 'resolved'
                    ? "Your report '{$report->subject}' has been resolved by the responsible support teams."
                    : "Your report '{$report->subject}' was reopened for further review.",
                'action_url' => $actionUrl,
            ]);
        }

        if ($roleStatusChanged) {
            ActivityLog::record(
                $user,
                'support_report_status_updated',
                "{$user->name} marked the {$viewerRole} handling state for support report #{$report->id} as {$validated['status']}.",
                $request,
                [
                    'support_report_id' => $report->id,
                    'handler_role' => $viewerRole,
                    'role_status' => $validated['status'],
                    'overall_status' => $report->status,
                ],
            );
        }

        $waitingForOtherRole = $validated['status'] === 'resolved' && $report->status !== 'resolved';

        return response()->json([
            'message' => $waitingForOtherRole
                ? 'Your part is complete. The report remains open for the other support team.'
                : ($validated['status'] === 'resolved' ? 'Report resolved.' : 'Report reopened for your team.'),
            'report' => $this->reportPayload(
                $report->fresh()->load(['applicant:id,role,first_name,last_name,email', 'scholarship:id,title']),
                true,
                $viewerRole,
            ),
        ]);
    }

    private function reportableScholarships(User $applicant)
    {
        return Scholarship::query()
            ->where(function ($query) use ($applicant): void {
                $query
                    ->where('status', 'published')
                    ->orWhereHas('applications', fn ($applicationQuery) => $applicationQuery
                        ->where('applicant_id', $applicant->id));
            });
    }

    private function queueResponse(Request $request, $query, string $viewerRole, array $extra = []): JsonResponse
    {
        $status = in_array($request->query('status'), ['open', 'resolved', 'all'], true)
            ? $request->query('status')
            : 'open';
        $counts = [
            'open' => $this->queueStatusQuery(clone $query, $viewerRole, 'open')->count(),
            'resolved' => $this->queueStatusQuery(clone $query, $viewerRole, 'resolved')->count(),
            'all' => (clone $query)->count(),
        ];

        if ($status !== 'all') {
            $this->queueStatusQuery($query, $viewerRole, $status);
        }

        $reports = $query
            ->with([
                'applicant:id,role,first_name,last_name,email',
                'scholarship:id,title',
                'providerResolver:id,role,username,email',
                'adminResolver:id,role,username,email',
            ])
            ->latest()
            ->paginate(8);

        return response()->json([
            ...$extra,
            'reports' => collect($reports->items())
                ->map(fn (SupportReport $report): array => $this->reportPayload($report, true, $viewerRole))
                ->values(),
            'counts' => $counts,
            'pagination' => $this->paginationPayload($reports),
        ]);
    }

    private function queueStatusQuery($query, string $viewerRole, string $status)
    {
        if ($viewerRole !== 'provider') {
            return $query->where("{$viewerRole}_status", $status);
        }

        return $query->where(function ($statusQuery) use ($status): void {
            $statusQuery
                ->where(function ($incomingQuery) use ($status): void {
                    $incomingQuery
                        ->where('assigned_role', 'provider')
                        ->where('provider_status', $status);
                })
                ->orWhere(function ($submittedQuery) use ($status): void {
                    $submittedQuery
                        ->where('assigned_role', 'admin')
                        ->where('admin_status', $status);
                });
        });
    }

    private function reportPayload(
        SupportReport $report,
        bool $includeApplicant = false,
        ?string $viewerRole = null,
    ): array {
        $submittedByProvider = $report->applicant?->isProvider() && $report->assigned_role === 'admin';
        $roleStatus = $viewerRole === 'provider' && $submittedByProvider
            ? $report->admin_status
            : ($viewerRole ? $report->{"{$viewerRole}_status"} : $report->status);
        $categoryLabels = $submittedByProvider
            ? SupportReport::PROVIDER_CATEGORIES
            : SupportReport::CATEGORIES;
        $payload = [
            'id' => $report->id,
            'category' => $report->category,
            'category_label' => $categoryLabels[$report->category] ?? 'Concern',
            'privacy_request_type' => $report->privacy_request_type,
            'privacy_request_type_label' => $report->privacy_request_type
                ? (SupportReport::PRIVACY_REQUEST_TYPES[$report->privacy_request_type] ?? 'Privacy request')
                : null,
            'subject' => $report->subject,
            'description' => $report->description,
            'context' => $report->context,
            'status' => $roleStatus,
            'status_label' => ucfirst($roleStatus),
            'overall_status' => $report->status,
            'overall_status_label' => ucfirst($report->status),
            'provider_status' => $report->assigned_role === 'provider' ? $report->provider_status : null,
            'provider_status_label' => $report->assigned_role === 'provider'
                ? ucfirst($report->provider_status)
                : 'Not required',
            'provider_resolved_at' => $report->provider_resolved_at?->format('M d, Y h:i A'),
            'provider_resolved_by' => $viewerRole ? $report->providerResolver?->name : null,
            'admin_status' => $report->admin_status,
            'admin_status_label' => ucfirst($report->admin_status),
            'admin_resolved_at' => $report->admin_resolved_at?->format('M d, Y h:i A'),
            'admin_resolved_by' => $viewerRole ? $report->adminResolver?->name : null,
            'requires_both_roles' => $report->assigned_role === 'provider',
            'submitted_by_provider' => $submittedByProvider,
            'can_update_status' => $viewerRole === 'admin' || ($viewerRole === 'provider' && ! $submittedByProvider),
            'sent_to' => $report->assigned_role === 'provider'
                ? 'Program provider and platform support'
                : 'Platform support',
            'program' => $report->scholarship ? [
                'id' => $report->scholarship->id,
                'title' => $report->scholarship->title,
            ] : null,
            'created_at' => $report->created_at?->format('M d, Y h:i A'),
            'resolved_at' => $report->resolved_at?->format('M d, Y h:i A'),
            'attachment' => $report->attachment_path && $viewerRole ? [
                'original_name' => $report->attachment_original_name,
                'mime_type' => $report->attachment_mime_type,
                'size' => $report->attachment_size,
                'view_url' => "/{$viewerRole}/reports/{$report->id}/attachment",
                'download_url' => "/{$viewerRole}/reports/{$report->id}/attachment",
            ] : null,
        ];

        if ($includeApplicant) {
            $payload['applicant'] = [
                'id' => $report->applicant?->id,
                'name' => $report->applicant?->name,
                'email' => $report->applicant?->email,
                'role' => $report->applicant?->role,
            ];
        }

        return $payload;
    }

    private function paginationPayload(LengthAwarePaginator $paginator): array
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
