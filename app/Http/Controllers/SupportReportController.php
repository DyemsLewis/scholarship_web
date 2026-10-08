<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\SupportReport;
use App\Models\User;
use App\Support\AdminWorkspace;
use App\Support\ProviderWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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

    public function providerSupportWorkspace(Request $request): View
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->hasPortalPermission('manage_reports'), 403);

        return view('provider-support-staff-workspace');
    }

    public function providerSupportWorkspaceData(Request $request): JsonResponse
    {
        $staff = $request->user();
        abort_unless($staff?->isProvider(), 403);
        abort_unless($staff->hasPortalPermission('manage_reports'), 403);

        $validated = $request->validate([
            'queue' => ['sometimes', Rule::in(['needs_action', 'waiting', 'submitted', 'resolved'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'program_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $queue = $validated['queue'] ?? 'needs_action';
        $search = trim((string) ($validated['search'] ?? ''));
        $perPage = 5;
        $owner = $staff->providerOrganizationOwner()->loadMissing('providerProfile');
        $programs = $this->providerSupportProgramsQuery($staff)
            ->orderBy('title')
            ->get(['id', 'title', 'status']);

        if (! empty($validated['program_id'])) {
            abort_unless($programs->contains('id', (int) $validated['program_id']), 403);
        }

        $base = $this->providerSupportReportsQuery($staff);
        $summary = [
            'needs_action' => (clone $base)
                ->where('assigned_role', 'provider')
                ->where('provider_status', 'open')
                ->count(),
            'waiting' => (clone $base)
                ->where('assigned_role', 'provider')
                ->where('provider_status', 'resolved')
                ->where('status', 'open')
                ->count(),
            'submitted' => (clone $base)
                ->where('assigned_role', 'admin')
                ->where('admin_status', 'open')
                ->whereHas('applicant', fn (Builder $query) => $query->where('role', 'provider'))
                ->count(),
            'resolved' => (clone $base)
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $incoming): void {
                            $incoming->where('assigned_role', 'provider')->where('status', 'resolved');
                        })
                        ->orWhere(function (Builder $submitted): void {
                            $submitted
                                ->where('assigned_role', 'admin')
                                ->where('admin_status', 'resolved')
                                ->whereHas('applicant', fn (Builder $applicant) => $applicant->where('role', 'provider'));
                        });
                })
                ->count(),
            'total' => (clone $base)->count(),
        ];
        $nextReport = (clone $base)
            ->where('assigned_role', 'provider')
            ->where('provider_status', 'open')
            ->with(['applicant:id,role,first_name,last_name,email', 'scholarship:id,title'])
            ->oldest()
            ->first();
        $query = clone $base;

        match ($queue) {
            'needs_action' => $query
                ->where('assigned_role', 'provider')
                ->where('provider_status', 'open'),
            'waiting' => $query
                ->where('assigned_role', 'provider')
                ->where('provider_status', 'resolved')
                ->where('status', 'open'),
            'submitted' => $query
                ->where('assigned_role', 'admin')
                ->where('admin_status', 'open')
                ->whereHas('applicant', fn (Builder $applicant) => $applicant->where('role', 'provider')),
            'resolved' => $query->where(function (Builder $resolved): void {
                $resolved
                    ->where(function (Builder $incoming): void {
                        $incoming->where('assigned_role', 'provider')->where('status', 'resolved');
                    })
                    ->orWhere(function (Builder $submitted): void {
                        $submitted
                            ->where('assigned_role', 'admin')
                            ->where('admin_status', 'resolved')
                            ->whereHas('applicant', fn (Builder $applicant) => $applicant->where('role', 'provider'));
                    });
            }),
        };

        if (! empty($validated['program_id'])) {
            $query->where('scholarship_id', (int) $validated['program_id']);
        }

        if ($search !== '') {
            $likeSearch = '%'.$search.'%';
            $query->where(function (Builder $searchQuery) use ($likeSearch): void {
                $searchQuery
                    ->where('subject', 'like', $likeSearch)
                    ->orWhere('description', 'like', $likeSearch)
                    ->orWhere('context', 'like', $likeSearch)
                    ->orWhereHas('applicant', fn (Builder $applicant) => $applicant
                        ->where('email', 'like', $likeSearch)
                        ->orWhere('first_name', 'like', $likeSearch)
                        ->orWhere('last_name', 'like', $likeSearch))
                    ->orWhereHas('scholarship', fn (Builder $program) => $program->where('title', 'like', $likeSearch));
            });
        }

        $query->with([
            'applicant:id,role,first_name,last_name,email',
            'applicant.studentProfile:id,user_id,profile_photo_path,profile_photo_original_name,profile_photo_mime_type',
            'scholarship:id,title',
            'providerResolver:id,role,username,email',
            'adminResolver:id,role,username,email',
        ]);

        if ($queue === 'needs_action') {
            $query->oldest();
        } else {
            $query->latest('updated_at')->latest('id');
        }

        $reports = $query->paginate($perPage);
        $reports->setCollection($reports->getCollection()
            ->map(fn (SupportReport $report): array => $this->providerSupportWorkspaceItem($report)));

        return response()->json([
            'workspace' => [
                'role' => 'Support staff',
                'staff_name' => $staff->name,
                'organization_name' => $owner->providerProfile?->provider_name ?: $owner->name,
                'program_access_mode' => $staff->hasLimitedProviderProgramAccess() ? 'selected' : 'all',
            ],
            'summary' => $summary,
            'next_task' => $nextReport ? [
                'report_id' => $nextReport->id,
                'title' => $nextReport->subject,
                'applicant' => $nextReport->applicant?->name ?: 'Applicant',
                'program' => $nextReport->scholarship?->title ?: 'General concern',
                'submitted_at' => $nextReport->created_at?->format('M d, Y h:i A'),
            ] : null,
            'categories' => collect(SupportReport::PROVIDER_CATEGORIES)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values(),
            'programs' => $programs,
            'reports' => $reports->items(),
            'pagination' => $this->paginationPayload($reports),
            'active_queue' => $queue,
        ]);
    }

    public function providerPage(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        if (ProviderWorkspace::usesSupportStaffWorkspace($request->user())) {
            return redirect()->route(ProviderWorkspace::SUPPORT_STAFF_ROUTE, $request->query());
        }

        return view('provider-reports');
    }

    public function providerData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        return $this->queueResponse(
            $request,
            $this->providerSupportReportsQuery($request->user()),
            'provider',
            [
                'categories' => collect(SupportReport::PROVIDER_CATEGORIES)
                    ->map(fn (string $label, string $value): array => compact('value', 'label'))
                    ->values(),
                'programs' => $this->providerSupportProgramsQuery($request->user())
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
            $scholarship = $this->providerSupportProgramsQuery($request->user())
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
            abort_unless($this->canAccessProviderSupportReport($user, $report), 403);
        }

        abort_if(blank($report->attachment_path) || ! Storage::disk('local')->exists($report->attachment_path), 404);

        return Storage::disk('local')->response(
            $report->attachment_path,
            $report->attachment_original_name ?: 'support-attachment',
            ['Content-Type' => $report->attachment_mime_type ?: 'application/octet-stream'],
        );
    }

    public function viewApplicantPhoto(Request $request, SupportReport $report)
    {
        $staff = $request->user();
        abort_unless($staff?->isProvider(), 403);
        abort_unless($this->canAccessProviderSupportReport($staff, $report), 403);

        $profile = $report->applicant?->studentProfile;
        abort_if(blank($profile?->profile_photo_path) || ! Storage::disk('local')->exists($profile->profile_photo_path), 404);

        return Storage::disk('local')->response(
            $profile->profile_photo_path,
            $profile->profile_photo_original_name ?: 'applicant-photo',
            ['Content-Type' => $profile->profile_photo_mime_type ?: 'image/jpeg'],
        );
    }

    public function adminPage(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        if (AdminWorkspace::usesSupportOfficerWorkspace($request->user())) {
            return redirect()->route(AdminWorkspace::SUPPORT_OFFICER_ROUTE, $request->query());
        }

        if (AdminWorkspace::usesPortalManagerWorkspace($request->user())) {
            return redirect()->route('admin.workspaces.portal.support', $request->query());
        }

        return view('admin-reports');
    }

    public function supportOfficerWorkspace(Request $request): View
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($request->user()->hasPortalPermission('manage_reports'), 403);

        return view('admin-support-officer-workspace');
    }

    public function supportOfficerWorkspaceData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($request->user()->hasPortalPermission('manage_reports'), 403);

        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', Rule::in(['all', ...array_keys(SupportReport::CATEGORIES), ...array_keys(SupportReport::PROVIDER_CATEGORIES)])],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $category = $validated['category'] ?? 'all';
        $search = trim((string) ($validated['search'] ?? ''));
        $summaryQuery = SupportReport::query();
        $query = SupportReport::query();

        if ($category !== 'all') {
            $query->where('category', $category);
        }

        if ($search !== '') {
            $likeSearch = '%'.$search.'%';
            $query->where(function ($searchQuery) use ($likeSearch): void {
                $searchQuery
                    ->where('subject', 'like', $likeSearch)
                    ->orWhere('description', 'like', $likeSearch)
                    ->orWhere('context', 'like', $likeSearch)
                    ->orWhereHas('applicant', fn ($applicantQuery) => $applicantQuery
                        ->where('email', 'like', $likeSearch)
                        ->orWhere('first_name', 'like', $likeSearch)
                        ->orWhere('last_name', 'like', $likeSearch))
                    ->orWhereHas('scholarship', fn ($programQuery) => $programQuery
                        ->where('title', 'like', $likeSearch));
            });
        }

        return $this->queueResponse($request, $query, 'admin', [
            'categories' => collect(SupportReport::CATEGORIES)
                ->merge(SupportReport::PROVIDER_CATEGORIES)
                ->map(fn (string $label, string $value): array => compact('value', 'label'))
                ->values(),
            'summary' => [
                'needs_action' => (clone $summaryQuery)->where('admin_status', 'open')->count(),
                'privacy_open' => (clone $summaryQuery)->where('admin_status', 'open')->where('category', 'privacy')->count(),
                'shared_open' => (clone $summaryQuery)->where('admin_status', 'open')->where('assigned_role', 'provider')->count(),
                'provider_submitted_open' => (clone $summaryQuery)
                    ->where('admin_status', 'open')
                    ->where('assigned_role', 'admin')
                    ->whereHas('applicant', fn ($applicantQuery) => $applicantQuery->where('role', 'provider'))
                    ->count(),
            ],
        ]);
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
                    && $this->canAccessProviderSupportReport($user, $report),
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
                $report->fresh()->load([
                    'applicant:id,role,first_name,last_name,email',
                    'applicant.studentProfile:id,user_id,profile_photo_path,profile_photo_original_name,profile_photo_mime_type',
                    'scholarship:id,title',
                ]),
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

    private function providerSupportReportsQuery(User $staff): Builder
    {
        $query = SupportReport::query()
            ->where('provider_id', $staff->providerOrganizationId());

        if ($staff->hasLimitedProviderProgramAccess()) {
            $query->where(function (Builder $scope) use ($staff): void {
                $scope
                    ->whereNull('scholarship_id')
                    ->orWhereIn('scholarship_id', $staff->assignedProviderProgramIds());
            });
        }

        return $query;
    }

    private function providerSupportProgramsQuery(User $staff): Builder
    {
        $query = Scholarship::query()
            ->where('provider_id', $staff->providerOrganizationId());

        if ($staff->hasLimitedProviderProgramAccess()) {
            $query->whereIn('id', $staff->assignedProviderProgramIds());
        }

        return $query;
    }

    private function canAccessProviderSupportReport(User $staff, SupportReport $report): bool
    {
        if ((int) $report->provider_id !== $staff->providerOrganizationId()) {
            return false;
        }

        return ! $staff->hasLimitedProviderProgramAccess()
            || $report->scholarship_id === null
            || in_array((int) $report->scholarship_id, $staff->assignedProviderProgramIds(), true);
    }

    private function providerSupportWorkspaceItem(SupportReport $report): array
    {
        $payload = $this->reportPayload($report, true, 'provider');
        [$state, $label, $detail] = match (true) {
            $report->assigned_role === 'provider' && $report->provider_status === 'open' => [
                'needs_action',
                'Response needed',
                'Review the concern and record the provider response.',
            ],
            $report->assigned_role === 'provider' && $report->status === 'open' => [
                'waiting',
                'Waiting for platform',
                'The provider response is complete; platform support is still reviewing.',
            ],
            $report->assigned_role === 'admin' && $report->admin_status === 'open' => [
                'submitted',
                'With platform support',
                'Your organization submitted this report and is waiting for an update.',
            ],
            default => [
                'resolved',
                'Resolved',
                'All required support handling is complete.',
            ],
        };

        return [
            ...$payload,
            'work_state' => $state,
            'work_label' => $label,
            'work_detail' => $detail,
        ];
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
                'profile_photo_url' => $viewerRole === 'provider'
                    && $report->applicant?->isApplicant()
                    && filled($report->applicant?->studentProfile?->profile_photo_path)
                        ? route('provider.reports.applicant-photo', $report, false)
                        : null,
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
