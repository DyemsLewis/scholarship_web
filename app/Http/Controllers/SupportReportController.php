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
            'subject' => trim($validated['subject']),
            'description' => trim($validated['description']),
            'status' => 'open',
        ]);

        if ($scholarship) {
            PortalNotification::create([
                'user_id' => $scholarship->provider_id,
                'type' => 'support_report',
                'title' => 'New program concern',
                'message' => "{$request->user()->name} reported a concern about {$scholarship->title}.",
                'action_url' => '/provider/reports',
            ]);
        }

        User::query()
            ->where('role', 'admin')
            ->get()
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'support_report',
                'title' => $scholarship ? 'New program concern' : 'New applicant report',
                'message' => $scholarship
                    ? "{$request->user()->name} reported a concern about {$scholarship->title}."
                    : "{$request->user()->name} submitted a {$validated['category']} concern.",
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

        return $this->queueResponse(
            $request,
            SupportReport::query()->where('assigned_role', 'provider')->where('provider_id', $request->user()->id),
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
        );
    }

    public function updateStatus(Request $request, SupportReport $report): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->isProvider() || $user?->isAdmin(), 403);

        if ($user->isProvider()) {
            abort_unless($report->assigned_role === 'provider' && $report->provider_id === $user->id, 403);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'resolved'])],
        ]);
        $changed = $report->status !== $validated['status'];

        $report->update([
            'status' => $validated['status'],
            'resolved_by' => $validated['status'] === 'resolved' ? $user->id : null,
            'resolved_at' => $validated['status'] === 'resolved' ? now() : null,
        ]);

        if ($changed) {
            PortalNotification::create([
                'user_id' => $report->applicant_id,
                'type' => 'support_report_status',
                'title' => $validated['status'] === 'resolved' ? 'Report marked resolved' : 'Report reopened',
                'message' => "Your report '{$report->subject}' is now {$validated['status']}.",
                'action_url' => '/dashboard/reports',
            ]);

            ActivityLog::record(
                $user,
                'support_report_status_updated',
                "{$user->name} marked support report #{$report->id} as {$validated['status']}.",
                $request,
                ['support_report_id' => $report->id, 'status' => $validated['status']],
            );
        }

        return response()->json([
            'message' => $validated['status'] === 'resolved' ? 'Report marked resolved.' : 'Report reopened.',
            'report' => $this->reportPayload($report->fresh()->load(['applicant:id,first_name,last_name,email', 'scholarship:id,title'])),
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

    private function queueResponse(Request $request, $query): JsonResponse
    {
        $status = in_array($request->query('status'), ['open', 'resolved', 'all'], true)
            ? $request->query('status')
            : 'open';
        $counts = [
            'open' => (clone $query)->where('status', 'open')->count(),
            'resolved' => (clone $query)->where('status', 'resolved')->count(),
            'all' => (clone $query)->count(),
        ];

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $reports = $query
            ->with(['applicant:id,first_name,last_name,email', 'scholarship:id,title'])
            ->latest()
            ->paginate(8);

        return response()->json([
            'reports' => collect($reports->items())
                ->map(fn (SupportReport $report): array => $this->reportPayload($report, true))
                ->values(),
            'counts' => $counts,
            'pagination' => $this->paginationPayload($reports),
        ]);
    }

    private function reportPayload(SupportReport $report, bool $includeApplicant = false): array
    {
        $payload = [
            'id' => $report->id,
            'category' => $report->category,
            'category_label' => SupportReport::CATEGORIES[$report->category] ?? 'Concern',
            'subject' => $report->subject,
            'description' => $report->description,
            'status' => $report->status,
            'status_label' => ucfirst($report->status),
            'sent_to' => $report->assigned_role === 'provider'
                ? 'Program provider and platform support'
                : 'Platform support',
            'program' => $report->scholarship ? [
                'id' => $report->scholarship->id,
                'title' => $report->scholarship->title,
            ] : null,
            'created_at' => $report->created_at?->format('M d, Y h:i A'),
            'resolved_at' => $report->resolved_at?->format('M d, Y h:i A'),
        ];

        if ($includeApplicant) {
            $payload['applicant'] = [
                'id' => $report->applicant?->id,
                'name' => $report->applicant?->name,
                'email' => $report->applicant?->email,
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
