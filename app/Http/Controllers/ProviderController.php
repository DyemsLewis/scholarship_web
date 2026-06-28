<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\ProviderVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\DecisionSupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProviderController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider');
    }

    public function programs(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-programs');
    }

    public function programForm(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-program-form');
    }

    public function applications(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-applications');
    }

    public function profile(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-profile');
    }

    public function insights(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-insights');
    }

    public function profileData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->withCount('bookmarks')
            ->latest()
            ->get();
        $applicationsCount = ScholarshipApplication::query()
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->id))
            ->count();

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'stats' => [
                'scholarships' => $scholarships->count(),
                'applications' => $applicationsCount,
                'drafts' => $scholarships->where('status', 'draft')->count(),
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'verification_documents' => $request->user()
                ->providerVerificationDocuments()
                ->latest()
                ->get()
                ->map(fn (ProviderVerificationDocument $document) => $this->verificationDocumentPayload($document))
                ->values(),
            'notifications' => $this->notificationsPayload($request),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $user = $request->user();
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'provider_name' => ['required', 'string', 'max:255'],
            'provider_type' => ['nullable', Rule::in(['school', 'foundation', 'government', 'company', 'non_profit', 'other'])],
            'provider_website' => ['nullable', 'string', 'max:255'],
            'provider_address' => ['nullable', 'string', 'max:500'],
            'provider_description' => ['nullable', 'string', 'max:1500'],
        ]);

        $middleInitial = strtoupper($validated['middle_initial']);

        $user->update([
            'email' => $validated['email'],
            'username' => $validated['username'],
        ]);

        $profile = $user->providerProfile;
        $user->providerProfile()->updateOrCreate([
            'user_id' => $user->id,
        ], [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => $middleInitial,
            'contact_number' => $validated['contact_number'],
            'provider_name' => $validated['provider_name'],
            'provider_type' => $validated['provider_type'] ?? null,
            'provider_website' => $validated['provider_website'] ?? null,
            'provider_address' => $validated['provider_address'] ?? null,
            'provider_description' => $validated['provider_description'] ?? null,
            'verification_status' => $profile?->verification_status ?? 'pending',
            'verification_notes' => $profile?->verification_notes,
            'verified_by' => $profile?->verified_by,
            'verified_at' => $profile?->verified_at,
        ]);

        ActivityLog::record(
            $user,
            'provider_profile_updated',
            "{$validated['provider_name']} updated their provider profile.",
            $request,
            ['provider_id' => $user->id],
        );

        return response()->json([
            'message' => 'Provider profile updated successfully.',
            'user' => $user->fresh(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
        ]);
    }

    public function uploadVerificationDocument(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $validated = $request->validate([
            'document_type' => ['required', Rule::in([
                'organization_registration',
                'authorization_letter',
                'valid_id',
                'school_or_office_proof',
                'other',
            ])],
            'document_file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ]);

        $file = $validated['document_file'];
        $path = $file->store("provider-verification/{$request->user()->id}", 'local');

        $document = ProviderVerificationDocument::create([
            'provider_id' => $request->user()->id,
            'uploaded_by' => $request->user()->id,
            'document_type' => $validated['document_type'],
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'status' => 'submitted',
            'uploaded_at' => now(),
        ]);

        User::query()
            ->where('role', 'admin')
            ->get()
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'provider_verification_document',
                'title' => 'Provider document uploaded',
                'message' => "{$request->user()->provider_name} uploaded a verification document.",
                'action_url' => '/admin/reviews',
            ]));

        ActivityLog::record(
            $request->user(),
            'provider_verification_document_uploaded',
            "{$request->user()->name} uploaded a provider verification document.",
            $request,
            ['document_id' => $document->id, 'document_type' => $document->document_type],
        );

        return response()->json([
            'message' => 'Verification document uploaded.',
            'document' => $this->verificationDocumentPayload($document),
            'verification_documents' => $request->user()
                ->providerVerificationDocuments()
                ->latest()
                ->get()
                ->map(fn (ProviderVerificationDocument $item) => $this->verificationDocumentPayload($item))
                ->values(),
        ], 201);
    }

    public function deleteVerificationDocument(Request $request, ProviderVerificationDocument $document): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($document->provider_id === $request->user()->id, 403);

        if (Storage::disk('local')->exists($document->path)) {
            Storage::disk('local')->delete($document->path);
        }

        $document->delete();

        return response()->json([
            'message' => 'Verification document removed.',
            'verification_documents' => $request->user()
                ->providerVerificationDocuments()
                ->latest()
                ->get()
                ->map(fn (ProviderVerificationDocument $item) => $this->verificationDocumentPayload($item))
                ->values(),
        ]);
    }

    public function downloadVerificationDocument(Request $request, ProviderVerificationDocument $document)
    {
        abort_unless($request->user()?->isProvider() && $document->provider_id === $request->user()->id, 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    public function insightsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->withCount('bookmarks')
            ->latest()
            ->get();
        $applications = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents.reviewer', 'scholarship'])
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->id))
            ->latest('submitted_at')
            ->get();
        $applications->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));
        $recommendationCounts = $applications
            ->groupBy('dss_recommendation')
            ->map(fn ($items) => $items->count());
        $submitted = $applications->count();
        $completeApplications = $applications
            ->filter(fn (ScholarshipApplication $application) => $this->documentReadiness($application)['percent'] === 100)
            ->count();
        $approved = $applications->where('status', 'approved')->count();
        $totalViews = $scholarships->sum(fn (Scholarship $scholarship) => $scholarship->views_count ?? 0);
        $totalSaves = $scholarships->sum(fn (Scholarship $scholarship) => $scholarship->bookmarks_count ?? 0);
        $missingDocuments = $applications
            ->flatMap(fn (ScholarshipApplication $application) => $this->documentReadiness($application)['missing'])
            ->countBy()
            ->sortDesc()
            ->take(8)
            ->map(fn (int $total, string $document) => [
                'document' => $document,
                'total' => $total,
            ])
            ->values();
        $documentIssues = $applications
            ->flatMap(fn (ScholarshipApplication $application) => $application->documents)
            ->filter(fn (ApplicationDocument $document) => in_array($document->status, ['pending', 'rejected', 'needs_replacement'], true))
            ->groupBy('document_name')
            ->map(fn ($items, string $document) => [
                'document' => $document,
                'total' => $items->count(),
                'pending' => $items->where('status', 'pending')->count(),
                'needs_replacement' => $items->where('status', 'needs_replacement')->count(),
                'rejected' => $items->where('status', 'rejected')->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->take(8);
        $documentReviewQueue = $applications
            ->flatMap(fn (ScholarshipApplication $application) => $application->documents->map(fn (ApplicationDocument $document) => [
                ...$this->documentPayload($document),
                'application_id' => $application->id,
                'application_status' => $application->status,
                'applicant' => $application->applicant?->name,
                'applicant_email' => $application->applicant?->email,
                'scholarship' => $application->scholarship?->title,
                'scholarship_image_url' => $application->scholarship
                    ? $this->scholarshipImageUrl($application->scholarship)
                    : asset('uploads/scholarship-default.jpg'),
                'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            ]))
            ->sortBy(fn (array $document) => [
                'pending' => 0,
                'needs_replacement' => 1,
                'rejected' => 2,
                'accepted' => 3,
            ][$document['status'] ?? 'pending'] ?? 4)
            ->values()
            ->take(12);

        return response()->json([
            'user' => $request->user()->loadMissing(['providerProfile'])->publicPayload(),
            'summary' => [
                'programs' => $scholarships->count(),
                'published_programs' => $scholarships->where('status', 'published')->count(),
                'total_views' => $totalViews,
                'total_saves' => $totalSaves,
                'applications' => $submitted,
                'complete_applications' => $completeApplications,
                'approved_applications' => $approved,
                'average_dss_score' => round((float) $applications->avg('dss_score'), 1),
            ],
            'funnel' => [
                ['label' => 'Views', 'value' => $totalViews],
                ['label' => 'Saved', 'value' => $totalSaves],
                ['label' => 'Submitted', 'value' => $submitted],
                ['label' => 'Complete checklist', 'value' => $completeApplications],
                ['label' => 'Approved', 'value' => $approved],
            ],
            'program_insights' => $scholarships->map(function (Scholarship $scholarship) use ($applications) {
                $programApplications = $applications->filter(fn (ScholarshipApplication $application) => $application->scholarship_id === $scholarship->id);
                $completeApplications = $programApplications
                    ->filter(fn (ScholarshipApplication $application) => $this->documentReadiness($application)['percent'] === 100)
                    ->count();

                return [
                    'id' => $scholarship->id,
                    'title' => $scholarship->title,
                    'status' => $scholarship->status,
                    'views' => $scholarship->views_count ?? 0,
                    'saves' => $scholarship->bookmarks_count ?? 0,
                    'applications' => $programApplications->count(),
                    'complete_applications' => $completeApplications,
                    'average_match_score' => round((float) $programApplications->avg('eligibility_score'), 1),
                    'average_dss_score' => round((float) $programApplications->avg('dss_score'), 1),
                ];
            })->sortByDesc('applications')->values(),
            'top_missing_documents' => $missingDocuments,
            'document_issues' => $documentIssues,
            'document_review_queue' => $documentReviewQueue,
            'dss_summary' => [
                'average_score' => round((float) $applications->avg('dss_score'), 1),
                'highly_recommended' => $recommendationCounts['highly_recommended'] ?? 0,
                'recommended' => $recommendationCounts['recommended'] ?? 0,
                'needs_review' => $recommendationCounts['needs_review'] ?? 0,
                'not_recommended' => $recommendationCounts['not_recommended'] ?? 0,
            ],
        ]);
    }

    public function applicationsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->withCount('bookmarks')
            ->latest()
            ->get();
        $applications = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship'])
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->id))
            ->latest('submitted_at')
            ->get();
        $applications->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));
        $statusCounts = $applications
            ->groupBy('status')
            ->map(fn ($items) => $items->count());
        $recommendationCounts = $applications
            ->groupBy('dss_recommendation')
            ->map(fn ($items) => $items->count());

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'stats' => [
                'scholarships' => $scholarships->count(),
                'applications' => $applications->count(),
                'drafts' => $scholarships->where('status', 'draft')->count(),
                'under_review' => $statusCounts['under_review'] ?? 0,
                'approved' => $statusCounts['approved'] ?? 0,
                'rejected' => $statusCounts['rejected'] ?? 0,
                'average_match_score' => round((float) $applications->avg('eligibility_score'), 1),
                'average_dss_score' => round((float) $applications->avg('dss_score'), 1),
                'pending_documents' => $applications->flatMap(fn (ScholarshipApplication $application) => $application->documents)->where('status', 'pending')->count(),
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
            'status_counts' => [
                'submitted' => $statusCounts['submitted'] ?? 0,
                'under_review' => $statusCounts['under_review'] ?? 0,
                'qualified' => $statusCounts['qualified'] ?? 0,
                'approved' => $statusCounts['approved'] ?? 0,
                'rejected' => $statusCounts['rejected'] ?? 0,
            ],
            'recommendation_counts' => [
                'highly_recommended' => $recommendationCounts['highly_recommended'] ?? 0,
                'recommended' => $recommendationCounts['recommended'] ?? 0,
                'needs_review' => $recommendationCounts['needs_review'] ?? 0,
                'low_priority' => $recommendationCounts['low_priority'] ?? 0,
                'not_recommended' => $recommendationCounts['not_recommended'] ?? 0,
            ],
            'program_performance' => $scholarships->map(function (Scholarship $scholarship) use ($applications) {
                $programApplications = $applications->filter(fn (ScholarshipApplication $application) => $application->scholarship_id === $scholarship->id);
                $completeApplications = $programApplications
                    ->filter(fn (ScholarshipApplication $application) => $this->documentReadiness($application)['percent'] === 100)
                    ->count();

                return [
                    'id' => $scholarship->id,
                    'title' => $scholarship->title,
                    'status' => $scholarship->status,
                    'applications' => $programApplications->count(),
                    'complete_applications' => $completeApplications,
                    'average_match_score' => round((float) $programApplications->avg('eligibility_score'), 1),
                    'average_dss_score' => round((float) $programApplications->avg('dss_score'), 1),
                    'saved_count' => $scholarship->bookmarks_count ?? 0,
                    'deadline' => $scholarship->deadline?->format('M d, Y'),
                    'days_left' => $scholarship->deadline ? now()->startOfDay()->diffInDays($scholarship->deadline->startOfDay(), false) : null,
                ];
            })->values(),
        ]);
    }

    public function updateApplicationStatus(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->id, 403);

        $outcomeStatuses = ['awarded', 'not_awarded', 'disbursed', 'renewed'];
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                'submitted',
                'under_review',
                'qualified',
                'shortlisted',
                'interview',
                'approved',
                'awarded',
                'not_awarded',
                'disbursed',
                'renewed',
                'rejected',
            ])],
            'decision_reason' => ['nullable', 'string', 'max:255'],
            'review_notes' => ['nullable', 'string', 'max:1500'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'outcome_notes' => ['nullable', 'string', 'max:2000'],
            'outcome_at' => ['nullable', 'date'],
        ]);

        $previousStatus = $application->status;
        $isOutcomeStatus = in_array($validated['status'], $outcomeStatuses, true);

        $application->update([
            'status' => $validated['status'],
            'decision_reason' => $validated['decision_reason'] ?? $application->decision_reason,
            'review_notes' => $validated['review_notes'] ?? $application->review_notes,
            'awarded_amount' => array_key_exists('awarded_amount', $validated) ? $validated['awarded_amount'] : $application->awarded_amount,
            'outcome_notes' => $validated['outcome_notes'] ?? $application->outcome_notes,
            'outcome_at' => $validated['outcome_at'] ?? ($isOutcomeStatus ? ($application->outcome_at ?? now()) : $application->outcome_at),
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        app(DecisionSupportService::class)->syncApplication($application);

        ApplicationStatusHistory::create([
            'scholarship_application_id' => $application->id,
            'changed_by' => $request->user()->id,
            'from_status' => $previousStatus,
            'to_status' => $validated['status'],
            'decision_reason' => $validated['decision_reason'] ?? null,
            'review_notes' => $validated['review_notes'] ?? null,
            'changed_at' => now(),
        ]);

        ActivityLog::record(
            $request->user(),
            'application_status_updated',
            "{$request->user()->name} updated application #{$application->id} to {$validated['status']}.",
            $request,
            [
                'application_id' => $application->id,
                'status' => $validated['status'],
                'decision_reason' => $validated['decision_reason'] ?? null,
            ],
        );

        PortalNotification::create([
            'user_id' => $application->applicant_id,
            'type' => $isOutcomeStatus ? 'application_outcome' : 'application_status',
            'title' => $isOutcomeStatus ? 'Application outcome recorded' : 'Application status updated',
            'message' => "Your application for {$application->scholarship?->title} is now {$this->statusLabel($validated['status'])}.",
            'action_url' => '/dashboard/applications',
        ]);

        $freshApplication = $application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($freshApplication);

        return response()->json([
            'message' => 'Application status updated.',
            'application' => $this->applicationPayload($freshApplication),
        ]);
    }

    public function updateDocumentStatus(Request $request, ApplicationDocument $document): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $document->load('application.scholarship');
        abort_unless($document->application?->scholarship?->provider_id === $request->user()->id, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'accepted', 'rejected', 'needs_replacement'])],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $document->update([
            'status' => $validated['status'],
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);
        app(DecisionSupportService::class)->syncApplication($document->application);

        ActivityLog::record(
            $request->user(),
            'document_status_updated',
            "{$request->user()->name} marked {$document->document_name} as {$validated['status']} for application #{$document->application?->id}.",
            $request,
            [
                'application_id' => $document->application?->id,
                'document_id' => $document->id,
                'document_status' => $validated['status'],
            ],
        );

        PortalNotification::create([
            'user_id' => $document->application->applicant_id,
            'type' => 'document_review',
            'title' => 'Document review updated',
            'message' => "{$document->document_name} was marked {$this->statusLabel($validated['status'])}.",
            'action_url' => '/dashboard/applications',
        ]);

        $freshApplication = $document->application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($freshApplication);

        return response()->json([
            'message' => 'Document status updated.',
            'application' => $this->applicationPayload($freshApplication),
        ]);
    }

    public function exportApplications(Request $request)
    {
        abort_unless($request->user()?->isProvider(), 403);

        $provider = $request->user();

        return response()->streamDownload(function () use ($provider) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Scholarship', 'Applicant', 'Email', 'Contact Number', 'Status', 'DSS Score', 'DSS Recommendation', 'Eligibility Score', 'Decision Reason', 'Awarded Amount', 'Outcome Date', 'Outcome Notes', 'Readiness %', 'Submitted At', 'Documents Confirmed', 'Uploaded Documents', 'Applicant Notes', 'Review Notes']);

            ScholarshipApplication::query()
                ->with(['applicant.studentProfile', 'documents', 'scholarship'])
                ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $provider->id))
                ->orderBy('id')
                ->chunk(200, function ($applications) use ($handle) {
                    foreach ($applications as $application) {
                        app(DecisionSupportService::class)->syncApplication($application);
                        $readiness = $this->documentReadiness($application);

                        fputcsv($handle, [
                            $application->id,
                            $application->scholarship?->title,
                            $application->applicant?->name,
                            $application->applicant?->email,
                            $application->applicant?->contact_number,
                            $application->status,
                            $application->dss_score,
                            $application->dss_recommendation,
                            $application->eligibility_score,
                            $application->decision_reason,
                            $application->awarded_amount,
                            $application->outcome_at?->format('Y-m-d'),
                            $application->outcome_notes,
                            $readiness['percent'],
                            $application->submitted_at?->format('Y-m-d H:i:s'),
                            implode('; ', $application->document_checklist ?? []),
                            $application->documents->count().' uploaded',
                            $application->notes,
                            $application->review_notes,
                        ]);
                    }
                });

            fclose($handle);
        }, 'provider-applications.csv', ['Content-Type' => 'text/csv']);
    }

    public function scholarships(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->withCount('bookmarks')
            ->latest()
            ->get();

        return response()->json([
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
        ]);
    }

    public function showScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($scholarship->provider_id === $request->user()->id, 403);

        return response()->json([
            'scholarship' => $this->scholarshipPayload($scholarship->loadCount('bookmarks')),
        ]);
    }

    public function storeScholarship(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);
        $imagePath = $this->storeScholarshipImage($request);

        unset($validated['image_file']);

        $scholarship = Scholarship::create([
            ...$validated,
            'image_path' => $imagePath,
            'provider_id' => $request->user()->id,
        ]);

        ActivityLog::record(
            $request->user(),
            'scholarship_created',
            "{$request->user()->name} created scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => 'Scholarship created successfully.',
            'scholarship' => $this->scholarshipPayload($scholarship),
        ], 201);
    }

    public function updateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($scholarship->provider_id === $request->user()->id, 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);
        $imagePath = $this->storeScholarshipImage($request, $scholarship);

        unset($validated['image_file']);

        if ($imagePath) {
            $validated['image_path'] = $imagePath;
        }

        $scholarship->update($validated);

        ActivityLog::record(
            $request->user(),
            'scholarship_updated',
            "{$request->user()->name} updated scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => 'Scholarship updated successfully.',
            'scholarship' => $this->scholarshipPayload($scholarship->fresh()),
        ]);
    }

    private function validateScholarship(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:5000'],
            'eligibility' => ['nullable', 'string', 'max:5000'],
            'eligible_education_levels' => ['nullable', 'string', 'max:2000'],
            'eligible_courses' => ['nullable', 'string', 'max:3000'],
            'eligible_school_types' => ['nullable', 'string', 'max:2000'],
            'eligible_year_levels' => ['nullable', 'string', 'max:2000'],
            'eligible_locations' => ['nullable', 'string', 'max:3000'],
            'income_requirement' => ['nullable', 'string', 'max:100'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'minimum_gwa' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'slots_available' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'application_mode' => ['nullable', Rule::in(['online', 'onsite', 'hybrid', 'provider_review'])],
            'renewal_policy' => ['nullable', 'string', 'max:2000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{7,30}$/'],
            'deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'published', 'closed'])],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
    }

    private function storeScholarshipImage(Request $request, ?Scholarship $scholarship = null): ?string
    {
        if (! $request->hasFile('image_file')) {
            return null;
        }

        $file = $request->file('image_file');
        $directory = public_path('uploads/scholarships');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = $file->hashName();
        $file->move($directory, $filename);

        if ($scholarship?->image_path) {
            $oldPath = public_path($scholarship->image_path);

            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        return "uploads/scholarships/{$filename}";
    }

    private function applicationPayload(ScholarshipApplication $application): array
    {
        $readiness = $this->documentReadiness($application);
        $decisionSupport = app(DecisionSupportService::class);
        $dss = $decisionSupport->scoreApplication($application);

        return [
            'id' => $application->id,
            'status' => $application->status,
            'document_checklist' => $application->document_checklist ?? [],
            'document_readiness' => $readiness,
            'documents' => $application->documents->map(fn (ApplicationDocument $document) => $this->documentPayload($document))->values(),
            'eligibility_score' => $application->eligibility_score,
            'eligibility_breakdown' => $application->eligibility_breakdown,
            'dss_score' => $dss['score'],
            'dss_recommendation' => $dss['recommendation'],
            'dss_breakdown' => $dss,
            'dss_explanation' => $decisionSupport->explainApplication($application, $dss),
            'status_progress' => $decisionSupport->statusProgress($application),
            'notes' => $application->notes,
            'review_notes' => $application->review_notes,
            'decision_reason' => $application->decision_reason,
            'awarded_amount' => $application->awarded_amount,
            'outcome_notes' => $application->outcome_notes,
            'outcome_at' => $application->outcome_at?->format('Y-m-d'),
            'reviewed_at' => $application->reviewed_at?->format('M d, Y h:i A'),
            'timeline' => $this->timelinePayload($application),
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            'applicant' => [
                'name' => $application->applicant?->name,
                'email' => $application->applicant?->email,
                'username' => $application->applicant?->username,
                'contact_number' => $application->applicant?->contact_number,
                'education_level' => $application->applicant?->studentProfile?->education_level,
                'school' => $application->applicant?->studentProfile?->school,
                'school_type' => $application->applicant?->studentProfile?->school_type,
                'learner_reference_number' => $application->applicant?->studentProfile?->learner_reference_number,
                'course_or_strand' => $application->applicant?->studentProfile?->course_or_strand,
                'year_level' => $application->applicant?->studentProfile?->year_level,
                'gwa' => $application->applicant?->studentProfile?->gwa,
                'income_bracket' => $application->applicant?->studentProfile?->income_bracket,
                'household_size' => $application->applicant?->studentProfile?->household_size,
                'preferred_categories' => $application->applicant?->studentProfile?->preferred_categories,
                'preferred_locations' => $application->applicant?->studentProfile?->preferred_locations,
                'willing_to_relocate' => $application->applicant?->studentProfile?->willing_to_relocate,
                'support_needs' => $application->applicant?->studentProfile?->support_needs,
                'scholarship_goal' => $application->applicant?->studentProfile?->scholarship_goal,
                'location' => collect([
                    $application->applicant?->studentProfile?->barangay,
                    $application->applicant?->studentProfile?->city,
                    $application->applicant?->studentProfile?->province,
                    $application->applicant?->studentProfile?->region,
                ])->filter()->implode(', '),
                'latitude' => $application->applicant?->studentProfile?->latitude,
                'longitude' => $application->applicant?->studentProfile?->longitude,
            ],
            'scholarship' => $application->scholarship
                ? $this->scholarshipPayload($application->scholarship)
                : null,
        ];
    }

    private function documentReadiness(ScholarshipApplication $application): array
    {
        $requiredDocuments = $this->documentRequirements($application->scholarship);
        $confirmedDocuments = collect($application->document_checklist ?? [])
            ->map(fn (string $document) => trim($document))
            ->filter()
            ->values();
        $requiredCount = count($requiredDocuments);
        $confirmedRequiredCount = collect($requiredDocuments)
            ->filter(fn (string $document) => $confirmedDocuments->contains($document))
            ->count();
        $uploadedDocuments = $application->documents
            ->map(fn (ApplicationDocument $document) => $document->document_name)
            ->values();
        $uploadedRequiredCount = collect($requiredDocuments)
            ->filter(fn (string $document) => $uploadedDocuments->contains($document))
            ->count();
        $acceptedRequiredCount = $application->documents
            ->filter(fn (ApplicationDocument $document) => $document->status === 'accepted' && collect($requiredDocuments)->contains($document->document_name))
            ->count();

        return [
            'required' => $requiredCount,
            'confirmed' => $confirmedRequiredCount,
            'percent' => $requiredCount === 0 ? 100 : (int) round(($confirmedRequiredCount / $requiredCount) * 100),
            'uploaded' => $uploadedRequiredCount,
            'uploaded_percent' => $requiredCount === 0 ? 100 : (int) round(($uploadedRequiredCount / $requiredCount) * 100),
            'accepted' => $acceptedRequiredCount,
            'accepted_percent' => $requiredCount === 0 ? 100 : (int) round(($acceptedRequiredCount / $requiredCount) * 100),
            'missing' => collect($requiredDocuments)
                ->reject(fn (string $document) => $confirmedDocuments->contains($document))
                ->values()
                ->all(),
        ];
    }

    private function documentRequirements(?Scholarship $scholarship): array
    {
        if (! $scholarship?->requirements) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n|,/', $scholarship->requirements))
            ->map(fn (string $requirement) => trim($requirement))
            ->filter()
            ->values()
            ->all();
    }

    private function scholarshipPayload(Scholarship $scholarship): array
    {
        return [
            'id' => $scholarship->id,
            'image_path' => $scholarship->image_path,
            'image_url' => $this->scholarshipImageUrl($scholarship),
            'title' => $scholarship->title,
            'category' => $scholarship->category,
            'description' => $scholarship->description,
            'eligibility' => $scholarship->eligibility,
            'eligible_education_levels' => $scholarship->eligible_education_levels,
            'eligible_courses' => $scholarship->eligible_courses,
            'eligible_school_types' => $scholarship->eligible_school_types,
            'eligible_year_levels' => $scholarship->eligible_year_levels,
            'eligible_locations' => $scholarship->eligible_locations,
            'income_requirement' => $scholarship->income_requirement,
            'location_name' => $scholarship->location_name,
            'location_address' => $scholarship->location_address,
            'latitude' => $scholarship->latitude,
            'longitude' => $scholarship->longitude,
            'map_url' => $this->mapUrl($scholarship),
            'embed_map_url' => $this->embedMapUrl($scholarship),
            'requirements' => $scholarship->requirements,
            'award_amount' => $scholarship->award_amount,
            'minimum_gwa' => $scholarship->minimum_gwa,
            'slots_available' => $scholarship->slots_available,
            'application_mode' => $scholarship->application_mode,
            'renewal_policy' => $scholarship->renewal_policy,
            'contact_email' => $scholarship->contact_email,
            'contact_number' => $scholarship->contact_number,
            'deadline' => $scholarship->deadline?->format('Y-m-d'),
            'status' => $scholarship->status,
            'bookmarks_count' => $scholarship->bookmarks_count ?? $scholarship->bookmarks()->count(),
            'views_count' => $scholarship->views_count,
            'created_at' => $scholarship->created_at?->format('M d, Y'),
            'updated_at' => $scholarship->updated_at?->format('M d, Y'),
        ];
    }

    private function scholarshipImageUrl(Scholarship $scholarship): string
    {
        if (filled($scholarship->image_path)) {
            return asset(ltrim($scholarship->image_path, '/'));
        }

        return asset('uploads/scholarship-default.jpg');
    }

    private function ensureProviderCanPost(Request $request): void
    {
        if ($request->user()->providerProfile?->isVerified()) {
            return;
        }

        abort(403, 'Your provider account must be approved by an admin before posting scholarships.');
    }

    private function mapUrl(Scholarship $scholarship): ?string
    {
        if ($scholarship->latitude !== null && $scholarship->longitude !== null) {
            return "https://www.openstreetmap.org/?mlat={$scholarship->latitude}&mlon={$scholarship->longitude}#map=15/{$scholarship->latitude}/{$scholarship->longitude}";
        }

        $query = $scholarship->location_address ?: $scholarship->location_name;

        return filled($query)
            ? 'https://www.openstreetmap.org/search?query='.rawurlencode($query)
            : null;
    }

    private function embedMapUrl(Scholarship $scholarship): ?string
    {
        if ($scholarship->latitude !== null && $scholarship->longitude !== null) {
            return "https://www.openstreetmap.org/export/embed.html?marker={$scholarship->latitude},{$scholarship->longitude}&layer=mapnik";
        }

        return null;
    }

    private function documentPayload(ApplicationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_name' => $document->document_name,
            'original_name' => $document->original_name,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'reviewed_by' => $document->reviewer?->name,
            'reviewed_at' => $document->reviewed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'download_url' => route('documents.download', $document),
        ];
    }

    private function verificationDocumentPayload(ProviderVerificationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'download_url' => route('provider.verification-documents.download', $document),
        ];
    }

    private function notificationsPayload(Request $request): array
    {
        return PortalNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (PortalNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'action_url' => $notification->action_url,
                'is_read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();
    }

    private function timelinePayload(ScholarshipApplication $application): array
    {
        if ($application->statusHistories->isEmpty()) {
            return [[
                'id' => "submitted-{$application->id}",
                'from_status' => null,
                'to_status' => $application->status,
                'decision_reason' => $application->decision_reason,
                'review_notes' => 'Application record created.',
                'actor' => $application->applicant?->name ?? 'Applicant',
                'changed_at' => $application->submitted_at?->format('M d, Y h:i A'),
            ]];
        }

        return $application->statusHistories
            ->sortBy('changed_at')
            ->map(fn (ApplicationStatusHistory $history) => [
                'id' => $history->id,
                'from_status' => $history->from_status,
                'to_status' => $history->to_status,
                'decision_reason' => $history->decision_reason,
                'review_notes' => $history->review_notes,
                'actor' => $history->actor?->name ?? 'System',
                'changed_at' => $history->changed_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();
    }

    private function statusLabel(string $status): string
    {
        return str($status)->replace('_', ' ')->title()->toString();
    }
}
