<?php

namespace App\Http\Controllers;

use App\Models\ApplicationSchedule;
use App\Models\ActivityLog;
use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\ProviderAssessment;
use App\Models\ProviderVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipFunnelEvent;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Support\AcademicRequirement;
use App\Support\ApplicationDecisionReason;
use App\Support\ApplicationSchedulePayload;
use App\Support\ReviewRubric;
use App\Support\Terms;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function exams(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-exams');
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

    public function programApplications(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($scholarship->provider_id === $request->user()->id, 403);

        return view('provider-applications', [
            'scholarship' => $scholarship,
        ]);
    }

    public function applicationDetail(Request $request, ScholarshipApplication $application): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->id, 403);

        return view('provider-application-detail', [
            'application' => $application,
        ]);
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

    public function dashboardData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->withCount('bookmarks')
            ->latest()
            ->get();
        $reviewQueue = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents', 'scholarship'])
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->id))
            ->whereIn('status', ['submitted', 'under_review', 'qualified', 'shortlisted', 'interview'])
            ->latest('submitted_at')
            ->limit(3)
            ->get();

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'review_queue' => $reviewQueue->map(fn (ScholarshipApplication $application) => [
                'id' => $application->id,
                'detail_url' => route('provider.applications.show', $application, false),
                'applicant' => $application->applicant?->name,
                'scholarship' => $application->scholarship?->title,
                'status' => $application->status,
                'pending_documents' => $application->documents->where('status', 'pending')->count(),
                'submitted_at' => $application->submitted_at?->format('M d, Y'),
            ])->values(),
        ]);
    }

    public function profileData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        return response()->json([
            'user' => $request->user()->loadMissing(['providerProfile'])->publicPayload(),
            'verification_documents' => $request->user()
                ->providerVerificationDocuments()
                ->latest()
                ->get()
                ->map(fn (ProviderVerificationDocument $document) => $this->verificationDocumentPayload($document))
                ->values(),
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
            'terms_accepted' => ['accepted'],
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
            'terms_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
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

        $selectedScholarship = $this->requestedProviderScholarship($request);
        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->withCount('bookmarks')
            ->latest()
            ->get();
        $applicationsQuery = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship'])
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->id));

        if ($selectedScholarship) {
            $applicationsQuery->where('scholarship_id', $selectedScholarship->id);
        }

        $applications = $applicationsQuery
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
            'selected_scholarship' => $selectedScholarship
                ? $this->scholarshipPayload($selectedScholarship)
                : null,
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
            'status_counts' => [
                'submitted' => $statusCounts['submitted'] ?? 0,
                'under_review' => $statusCounts['under_review'] ?? 0,
                'qualified' => $statusCounts['qualified'] ?? 0,
                'exam_qualified' => $statusCounts['exam_qualified'] ?? 0,
                'exam_scheduled' => $statusCounts['exam_scheduled'] ?? 0,
                'exam_taken' => $statusCounts['exam_taken'] ?? 0,
                'exam_passed' => $statusCounts['exam_passed'] ?? 0,
                'exam_failed' => $statusCounts['exam_failed'] ?? 0,
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

    public function examsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $assessments = ProviderAssessment::query()
            ->where('provider_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'user' => $request->user()->loadMissing(['providerProfile'])->publicPayload(),
            'assessments' => $assessments->map(fn (ProviderAssessment $assessment) => $this->assessmentPayload($assessment))->values(),
        ]);
    }

    public function updateExam(Request $request, ProviderAssessment $assessment): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($assessment->provider_id === $request->user()->id, 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'assessment_type' => ['required', Rule::in(['qualifying_exam', 'screening_assessment'])],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['nullable', 'integer', 'between:15,480'],
            'passing_score' => ['nullable', 'numeric', 'between:0,100'],
            'delivery_mode' => ['required', Rule::in(['provider_managed', 'onsite', 'online', 'hybrid'])],
            'venue' => ['nullable', 'string', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $assessment->update($validated);

        ActivityLog::record(
            $request->user(),
            'provider_assessment_updated',
            "{$request->user()->name} updated {$assessment->title}.",
            $request,
            ['assessment_id' => $assessment->id],
        );

        return response()->json([
            'message' => 'Assessment details updated.',
            'assessment' => $this->assessmentPayload($assessment->fresh()),
        ]);
    }

    public function applicationDetailData(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->id, 403);

        $application->load(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($application);
        $application = $application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship']);

        return response()->json([
            'user' => $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload(),
            'application' => $this->applicationPayload($application, true),
        ]);
    }

    public function upsertApplicationSchedule(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->id, 403);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['exam', 'interview', 'distribution'])],
            'title' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date', 'after_or_equal:now'],
            'mode' => ['required', Rule::in(['onsite', 'online', 'hybrid', 'provider_managed'])],
            'venue' => [
                Rule::requiredIf(in_array($request->input('mode'), ['onsite', 'hybrid'], true)),
                'nullable',
                'string',
                'max:500',
            ],
            'location_address' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'online_url' => [
                Rule::requiredIf(in_array($request->input('mode'), ['online', 'hybrid'], true)),
                'nullable',
                'url:http,https',
                'max:2000',
            ],
            'instructions' => ['required', 'string', 'max:3000'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
        ]);

        $application->loadMissing(['scholarship.providerAssessment', 'applicant']);
        $this->ensureScheduleCanBePublished($application, $validated['type']);

        $eventLabel = $this->scheduleTypeLabel($validated['type']);
        $scheduledAt = CarbonImmutable::parse($validated['scheduled_at']);
        $scheduleData = [
            'title' => filled($validated['title'] ?? null) ? trim($validated['title']) : "{$eventLabel} schedule",
            'scheduled_at' => $scheduledAt,
            'mode' => $validated['mode'],
            'venue' => $validated['venue'] ?? null,
            'location_address' => $validated['location_address'] ?? null,
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'online_url' => $validated['online_url'] ?? null,
            'instructions' => $validated['instructions'],
            'status' => 'scheduled',
            'attendance_status' => 'pending',
            'attendance_notes' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'updated_by' => $request->user()->id,
        ];

        [$schedule, $announcementChanged, $previousStatus, $nextStatus] = DB::transaction(function () use (
            $application,
            $request,
            $validated,
            $scheduleData,
            $scheduledAt,
            $eventLabel,
        ): array {
            $schedule = $application->schedules()->where('type', $validated['type'])->first();

            if ($schedule) {
                $schedule->update($scheduleData);
            } else {
                $schedule = $application->schedules()->create([
                    ...$scheduleData,
                    'type' => $validated['type'],
                    'created_by' => $request->user()->id,
                ]);
            }

            $announcementChanged = $schedule->wasRecentlyCreated || $schedule->wasChanged([
                'title',
                'scheduled_at',
                'mode',
                'venue',
                'location_address',
                'latitude',
                'longitude',
                'online_url',
                'instructions',
                'status',
            ]);

            if ($validated['type'] === 'distribution'
                && array_key_exists('awarded_amount', $validated)
                && $this->comparableScholarshipValue($application->awarded_amount)
                    !== $this->comparableScholarshipValue($validated['awarded_amount'])) {
                $announcementChanged = true;
            }

            if ($announcementChanged && $schedule->applicant_acknowledged_at !== null) {
                $schedule->forceFill(['applicant_acknowledged_at' => null])->saveQuietly();
            }

            $previousStatus = $application->status;
            $nextStatus = $this->scheduleApplicationStatus($validated['type']);
            $applicationUpdates = [
                'status' => $nextStatus,
                'decision_reason' => $this->scheduleDecisionReason($validated['type']),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ];

            if ($validated['type'] === 'distribution') {
                $applicationUpdates += [
                    'distribution_scheduled_for' => $scheduledAt->toDateString(),
                    'distribution_instructions' => $validated['instructions'],
                ];

                if (array_key_exists('awarded_amount', $validated)) {
                    $applicationUpdates['awarded_amount'] = $validated['awarded_amount'];
                }
            }

            $application->update($applicationUpdates);

            if ($previousStatus !== $nextStatus) {
                ApplicationStatusHistory::create([
                    'scholarship_application_id' => $application->id,
                    'changed_by' => $request->user()->id,
                    'from_status' => $previousStatus,
                    'to_status' => $nextStatus,
                    'decision_reason' => $this->scheduleDecisionReason($validated['type']),
                    'review_notes' => "{$eventLabel} announced for {$scheduledAt->format('M d, Y h:i A')}.",
                    'changed_at' => now(),
                ]);
            }

            return [$schedule, $announcementChanged, $previousStatus, $nextStatus];
        });

        if ($previousStatus !== $nextStatus) {
            ScholarshipFunnelEvent::record(
                $application->applicant,
                "application_status_{$nextStatus}",
                $application->scholarship,
                $application,
                'provider',
                ['schedule_id' => $schedule->id, 'schedule_type' => $schedule->type],
            );
        }

        ActivityLog::record(
            $request->user(),
            'application_schedule_published',
            "{$request->user()->name} published the {$eventLabel} schedule for application #{$application->id}.",
            $request,
            [
                'application_id' => $application->id,
                'schedule_id' => $schedule->id,
                'schedule_type' => $schedule->type,
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ],
        );

        if ($announcementChanged) {
            $destination = $schedule->mode === 'online'
                ? ' online'
                : ' at '.($schedule->venue ?: $schedule->location_address ?: 'the provider location');

            PortalNotification::create([
                'user_id' => $application->applicant_id,
                'type' => 'application_schedule',
                'title' => "{$eventLabel} schedule posted",
                'message' => "Your {$eventLabel} for {$application->scholarship?->title} is scheduled for {$scheduledAt->format('M d, Y h:i A')}{$destination}. Open the application and acknowledge the schedule.",
                'action_url' => route('dashboard.applications.show', $application, false),
            ]);
        }

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship',
        ]);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_schedule_published');

        return response()->json([
            'message' => "{$eventLabel} schedule published and the applicant was notified.",
            'schedule' => ApplicationSchedulePayload::make($schedule->fresh()),
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function updateApplicationScheduleTracking(
        Request $request,
        ScholarshipApplication $application,
        ApplicationSchedule $schedule,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->id, 403);
        abort_unless($schedule->scholarship_application_id === $application->id, 404);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['scheduled', 'completed', 'cancelled'])],
            'attendance_status' => ['required', Rule::in(['pending', 'attended', 'absent', 'excused', 'received', 'not_required'])],
            'attendance_notes' => ['nullable', 'string', 'max:1500'],
        ]);

        if ($validated['status'] === 'completed' && $schedule->scheduled_at?->isFuture()) {
            throw ValidationException::withMessages([
                'status' => 'This activity cannot be marked complete before its scheduled date and time.',
            ]);
        }

        if ($validated['status'] === 'scheduled' && $validated['attendance_status'] !== 'pending') {
            throw ValidationException::withMessages([
                'attendance_status' => 'Attendance stays pending until the activity is completed or cancelled.',
            ]);
        }

        if ($validated['status'] === 'completed'
            && $schedule->type !== 'distribution'
            && ! in_array($validated['attendance_status'], ['attended', 'absent', 'excused'], true)) {
            throw ValidationException::withMessages([
                'attendance_status' => 'Choose attended, absent, or excused when completing this activity.',
            ]);
        }

        if ($validated['status'] === 'cancelled'
            && ! in_array($validated['attendance_status'], ['pending', 'excused', 'not_required'], true)) {
            throw ValidationException::withMessages([
                'attendance_status' => 'A cancelled activity cannot be marked attended or received.',
            ]);
        }

        if ($schedule->type === 'distribution'
            && $validated['status'] === 'completed'
            && $validated['attendance_status'] !== 'received') {
            throw ValidationException::withMessages([
                'attendance_status' => 'Mark the reward as received before completing distribution.',
            ]);
        }

        $previousApplicationStatus = $application->status;
        $previousScheduleStatus = $schedule->status;
        $previousAttendance = $schedule->attendance_status;

        DB::transaction(function () use ($application, $request, $schedule, $validated): void {
            $schedule->update([
                'status' => $validated['status'],
                'attendance_status' => $validated['attendance_status'],
                'attendance_notes' => $validated['attendance_notes'] ?? null,
                'completed_at' => $validated['status'] === 'completed' ? now() : null,
                'cancelled_at' => $validated['status'] === 'cancelled' ? now() : null,
                'updated_by' => $request->user()->id,
            ]);

            $nextStatus = null;

            if ($validated['status'] === 'completed'
                && $schedule->type === 'exam'
                && $validated['attendance_status'] === 'attended') {
                $nextStatus = 'exam_taken';
            }

            if ($validated['status'] === 'completed' && $schedule->type === 'distribution') {
                $nextStatus = 'disbursed';
            }

            if ($nextStatus && $application->status !== $nextStatus) {
                $fromStatus = $application->status;
                $application->update([
                    'status' => $nextStatus,
                    'decision_reason' => $nextStatus === 'disbursed' ? 'award_released' : 'exam_completed',
                    'outcome_at' => $nextStatus === 'disbursed' ? now() : $application->outcome_at,
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);

                ApplicationStatusHistory::create([
                    'scholarship_application_id' => $application->id,
                    'changed_by' => $request->user()->id,
                    'from_status' => $fromStatus,
                    'to_status' => $nextStatus,
                    'decision_reason' => $nextStatus === 'disbursed' ? 'award_released' : 'exam_completed',
                    'review_notes' => "{$this->scheduleTypeLabel($schedule->type)} attendance and completion recorded.",
                    'changed_at' => now(),
                ]);
            }
        });

        $trackingChanged = $previousScheduleStatus !== $schedule->status
            || $previousAttendance !== $schedule->attendance_status
            || $schedule->wasChanged('attendance_notes');

        ActivityLog::record(
            $request->user(),
            'application_schedule_tracking_updated',
            "{$request->user()->name} updated {$schedule->type} tracking for application #{$application->id}.",
            $request,
            [
                'application_id' => $application->id,
                'schedule_id' => $schedule->id,
                'status' => $schedule->status,
                'attendance_status' => $schedule->attendance_status,
            ],
        );

        if ($trackingChanged) {
            PortalNotification::create([
                'user_id' => $application->applicant_id,
                'type' => 'application_schedule',
                'title' => $this->scheduleTypeLabel($schedule->type).' record updated',
                'message' => "The provider updated your {$this->scheduleTypeLabel($schedule->type)} record to {$schedule->status} with participation marked {$schedule->attendance_status}.",
                'action_url' => route('dashboard.applications.show', $application, false),
            ]);
        }

        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents.reviewer',
            'schedules',
            'statusHistories.actor',
            'scholarship',
        ]);

        if ($previousApplicationStatus !== $freshApplication->status) {
            ScholarshipFunnelEvent::record(
                $freshApplication->applicant,
                "application_status_{$freshApplication->status}",
                $freshApplication->scholarship,
                $freshApplication,
                'provider',
                ['schedule_id' => $schedule->id, 'schedule_type' => $schedule->type],
            );
        }

        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_schedule_tracking_updated');

        return response()->json([
            'message' => 'Schedule tracking updated.',
            'schedule' => ApplicationSchedulePayload::make($schedule->fresh()),
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function viewApplicantProfileProof(
        Request $request,
        ScholarshipApplication $application,
        ApplicantVerificationDocument $document,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($application->scholarship?->provider_id === $request->user()->id, 403);
        abort_unless($document->applicant_id === $application->applicant_id, 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
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
                'exam_qualified',
                'exam_scheduled',
                'exam_taken',
                'exam_passed',
                'exam_failed',
                'approved',
                'awarded',
                'distribution_scheduled',
                'not_awarded',
                'disbursed',
                'renewed',
                'rejected',
            ])],
            'decision_reason' => [
                Rule::requiredIf(ApplicationDecisionReason::requiredForStatus($request->input('status'))),
                'nullable',
                'string',
                Rule::in(ApplicationDecisionReason::acceptedValues()),
            ],
            'review_notes' => ['nullable', 'string', 'max:1500'],
            'awarded_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'outcome_notes' => ['nullable', 'string', 'max:2000'],
            'outcome_at' => ['nullable', 'date'],
            'distribution_scheduled_for' => $request->input('status') === 'distribution_scheduled'
                ? ['required', 'date', 'after_or_equal:today']
                : ['nullable', 'date'],
            'distribution_instructions' => ['nullable', 'string', 'max:2000'],
            'rubric_scores' => ['sometimes', 'array'],
            'rubric_scores.*' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $previousStatus = $application->status;
        $isOutcomeStatus = in_array($validated['status'], $outcomeStatuses, true);
        $decisionReason = array_key_exists('decision_reason', $validated)
            ? $validated['decision_reason']
            : $application->decision_reason;
        $reviewNotes = array_key_exists('review_notes', $validated)
            ? $validated['review_notes']
            : $application->review_notes;
        $outcomeNotes = array_key_exists('outcome_notes', $validated)
            ? $validated['outcome_notes']
            : $application->outcome_notes;
        $distributionScheduledFor = array_key_exists('distribution_scheduled_for', $validated)
            ? $validated['distribution_scheduled_for']
            : $application->distribution_scheduled_for?->toDateString();
        $distributionInstructions = array_key_exists('distribution_instructions', $validated)
            ? $validated['distribution_instructions']
            : $application->distribution_instructions;

        if ($validated['status'] === 'distribution_scheduled'
            && ! in_array($previousStatus, ['approved', 'awarded', 'distribution_scheduled'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Approve or award the application before scheduling reward distribution.',
            ]);
        }

        if ($validated['status'] === 'disbursed') {
            if ($previousStatus !== 'distribution_scheduled' || blank($distributionScheduledFor)) {
                throw ValidationException::withMessages([
                    'status' => 'Schedule reward distribution before marking it as distributed.',
                ]);
            }

            if ($distributionScheduledFor > now()->toDateString()) {
                throw ValidationException::withMessages([
                    'status' => 'Reward distribution cannot be marked complete before its scheduled date.',
                ]);
            }
        }

        $outcomeAt = array_key_exists('outcome_at', $validated)
            ? $validated['outcome_at']
            : ($isOutcomeStatus && $previousStatus !== $validated['status'] ? now() : $application->outcome_at);
        $rubric = $application->review_rubric_snapshot
            ?: ($application->scholarship?->review_rubric ?? []);
        $rubricResult = array_key_exists('rubric_scores', $validated)
            ? ReviewRubric::result($rubric, $validated['rubric_scores'])
            : null;
        $applicantFacingChanged = $previousStatus !== $validated['status']
            || $this->comparableScholarshipValue($application->decision_reason) !== $this->comparableScholarshipValue($decisionReason)
            || $this->comparableScholarshipValue($application->awarded_amount) !== $this->comparableScholarshipValue($validated['awarded_amount'] ?? $application->awarded_amount)
            || $this->comparableScholarshipValue($application->outcome_notes) !== $this->comparableScholarshipValue($outcomeNotes)
            || $this->comparableScholarshipValue($application->outcome_at) !== $this->comparableScholarshipValue($outcomeAt)
            || $this->comparableScholarshipValue($application->distribution_scheduled_for) !== $this->comparableScholarshipValue($distributionScheduledFor)
            || $this->comparableScholarshipValue($application->distribution_instructions) !== $this->comparableScholarshipValue($distributionInstructions);
        $reviewNoteChanged = $this->comparableScholarshipValue($application->review_notes)
            !== $this->comparableScholarshipValue($reviewNotes);

        $application->update([
            'status' => $validated['status'],
            'decision_reason' => $decisionReason,
            'review_notes' => $reviewNotes,
            'awarded_amount' => array_key_exists('awarded_amount', $validated) ? $validated['awarded_amount'] : $application->awarded_amount,
            'outcome_notes' => $outcomeNotes,
            'outcome_at' => $outcomeAt,
            'distribution_scheduled_for' => $distributionScheduledFor,
            'distribution_instructions' => $distributionInstructions,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rubric_scores' => $rubricResult ? $rubricResult['scores'] : $application->rubric_scores,
            'rubric_total_score' => $rubricResult ? $rubricResult['total_score'] : $application->rubric_total_score,
            'rubric_scored_by' => $rubricResult ? $request->user()->id : $application->rubric_scored_by,
            'rubric_scored_at' => $rubricResult && $rubricResult['completed'] > 0 ? now() : $application->rubric_scored_at,
        ]);

        if ($applicantFacingChanged || $reviewNoteChanged) {
            ApplicationStatusHistory::create([
                'scholarship_application_id' => $application->id,
                'changed_by' => $request->user()->id,
                'from_status' => $previousStatus,
                'to_status' => $validated['status'],
                'decision_reason' => $validated['decision_reason'] ?? null,
                'review_notes' => $validated['review_notes'] ?? null,
                'changed_at' => now(),
            ]);
        }

        if ($previousStatus !== $validated['status']) {
            ScholarshipFunnelEvent::record(
                $application->applicant,
                "application_status_{$validated['status']}",
                $application->scholarship,
                $application,
                'provider',
                [
                    'previous_status' => $previousStatus,
                    'status' => $validated['status'],
                    'decision_reason' => $decisionReason,
                    'canonical_decision_reason' => ApplicationDecisionReason::canonical($decisionReason),
                    'awarded_amount' => $application->awarded_amount,
                    'reviewed_by' => $request->user()->id,
                    'rubric_total_score' => $application->rubric_total_score,
                ],
            );
        }

        ActivityLog::record(
            $request->user(),
            'application_status_updated',
            "{$request->user()->name} updated application #{$application->id} to {$validated['status']}.",
            $request,
            [
                'application_id' => $application->id,
                'status' => $validated['status'],
                'decision_reason' => $validated['decision_reason'] ?? null,
                'distribution_scheduled_for' => $distributionScheduledFor,
                'rubric_total_score' => $rubricResult['total_score'] ?? null,
            ],
        );

        if ($applicantFacingChanged) {
            PortalNotification::create(array_merge(
                ['user_id' => $application->applicant_id],
                $this->applicationStatusNotificationPayload($application, $validated['status'], $decisionReason, $isOutcomeStatus),
            ));
        }

        $freshApplication = $application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_status_updated');

        return response()->json([
            'message' => $applicantFacingChanged ? 'Application status updated.' : 'Provider review saved.',
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function updateDocumentStatus(Request $request, ApplicationDocument $document): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $document->load('application.scholarship');
        abort_unless($document->application?->scholarship?->provider_id === $request->user()->id, 403);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'accepted', 'rejected', 'needs_replacement'])],
            'review_notes' => [Rule::requiredIf(in_array($request->input('status'), ['rejected', 'needs_replacement'], true)), 'nullable', 'string', 'max:1000'],
        ]);

        $previousStatus = $document->status;
        $document->update([
            'status' => $validated['status'],
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ($previousStatus !== $validated['status']) {
            ScholarshipFunnelEvent::record(
                $document->application->applicant,
                "application_document_{$validated['status']}",
                $document->application->scholarship,
                $document->application,
                'provider',
                [
                    'document_id' => $document->id,
                    'document_name' => $document->document_name,
                    'previous_status' => $previousStatus,
                    'status' => $validated['status'],
                    'reviewed_by' => $request->user()->id,
                ],
            );
        }

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

        $documentMessage = "{$document->document_name} was marked {$this->statusLabel($validated['status'])}.";

        if (in_array($validated['status'], ['rejected', 'needs_replacement'], true)) {
            $documentMessage .= " Reason: {$validated['review_notes']}";
        }

        PortalNotification::create([
            'user_id' => $document->application->applicant_id,
            'type' => 'document_review',
            'title' => 'Document review updated',
            'message' => $documentMessage,
            'action_url' => '/dashboard/applications',
        ]);

        $freshApplication = $document->application->fresh()->load(['applicant.studentProfile', 'documents.reviewer', 'statusHistories.actor', 'scholarship']);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'provider_document_reviewed');

        return response()->json([
            'message' => 'Document status updated.',
            'application' => $this->applicationPayload($freshApplication, true),
        ]);
    }

    public function exportApplications(Request $request)
    {
        abort_unless($request->user()?->isProvider(), 403);

        $provider = $request->user();
        $selectedScholarship = $this->requestedProviderScholarship($request);
        $filename = $selectedScholarship
            ? "provider-applications-program-{$selectedScholarship->id}.csv"
            : 'provider-applications.csv';

        return response()->streamDownload(function () use ($provider, $selectedScholarship) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Scholarship', 'Applicant', 'Email', 'Contact Number', 'Status', 'DSS Score', 'DSS Recommendation', 'Eligibility Score', 'Decision Reason', 'Awarded Amount', 'Distribution Date', 'Distribution Instructions', 'Outcome Date', 'Outcome Notes', 'Readiness %', 'Submitted At', 'Documents Confirmed', 'Uploaded Documents', 'Applicant Notes', 'Review Notes']);

            $query = ScholarshipApplication::query()
                ->with(['applicant.studentProfile', 'documents', 'scholarship'])
                ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $provider->id));

            if ($selectedScholarship) {
                $query->where('scholarship_id', $selectedScholarship->id);
            }

            $query->orderBy('id')
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
                            $application->distribution_scheduled_for?->format('Y-m-d'),
                            $application->distribution_instructions,
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
        }, $filename, ['Content-Type' => 'text/csv']);
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

    public function duplicateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($scholarship->provider_id === $request->user()->id, 403);
        $this->ensureProviderCanPost($request);

        $duplicate = $scholarship->replicate([
            'created_at',
            'updated_at',
        ]);
        $duplicate->title = $this->duplicateScholarshipTitle($request->user()->id, $scholarship->title);
        $duplicate->status = 'draft';
        $duplicate->views_count = 0;
        $duplicate->provider_terms_accepted_at = now();
        $duplicate->provider_terms_version = Terms::VERSION;
        $duplicate->save();
        $duplicate->loadCount('bookmarks');

        ActivityLog::record(
            $request->user(),
            'scholarship_duplicated',
            "{$request->user()->name} duplicated scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'duplicate_id' => $duplicate->id],
        );

        return response()->json([
            'message' => 'Program duplicated as a draft.',
            'scholarship' => $this->scholarshipPayload($duplicate),
        ], 201);
    }

    public function storeScholarship(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);
        $imagePath = $this->storeScholarshipImage($request);

        unset($validated['image_file'], $validated['terms_accepted']);
        $validated = $this->normalizeScholarshipAcademicRequirement($validated);
        $validated = $this->normalizeScholarshipReviewRubric($validated, $request);
        $validated['status'] = $validated['status'] === 'draft' ? 'draft' : 'pending_review';
        $validated['provider_terms_accepted_at'] = now();
        $validated['provider_terms_version'] = Terms::VERSION;

        $scholarship = Scholarship::create([
            ...$validated,
            'image_path' => $imagePath,
            'provider_id' => $request->user()->id,
        ]);

        if ($scholarship->status === 'pending_review') {
            $this->notifyAdminsScholarshipSubmitted($request, $scholarship);
        }

        ActivityLog::record(
            $request->user(),
            'scholarship_created',
            "{$request->user()->name} created scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => $scholarship->status === 'pending_review'
                ? 'Scholarship submitted for admin review.'
                : 'Scholarship draft saved.',
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

        unset($validated['image_file'], $validated['terms_accepted']);
        $validated = $this->normalizeScholarshipAcademicRequirement($validated);
        $validated = $this->normalizeScholarshipReviewRubric($validated, $request, $scholarship);
        $validated['provider_terms_accepted_at'] = now();
        $validated['provider_terms_version'] = Terms::VERSION;

        if ($imagePath) {
            $validated['image_path'] = $imagePath;
        }

        $validated['status'] = $this->providerScholarshipStatus($scholarship, $validated['status'], $validated);
        $scholarship->update($validated);

        if ($scholarship->status === 'pending_review') {
            $this->notifyAdminsScholarshipSubmitted($request, $scholarship);
        }

        ActivityLog::record(
            $request->user(),
            'scholarship_updated',
            "{$request->user()->name} updated scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => match ($scholarship->status) {
                'pending_review' => 'Scholarship submitted for admin review.',
                'closed' => 'Scholarship closed.',
                'published' => 'Published scholarship updated.',
                default => 'Scholarship draft saved.',
            },
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
            'minimum_grade_scale' => ['nullable', Rule::in(AcademicRequirement::SCALES)],
            'slots_available' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'application_mode' => ['nullable', Rule::in(['online', 'onsite', 'hybrid', 'provider_review'])],
            'renewal_policy' => ['nullable', 'string', 'max:2000'],
            'return_service_contract' => ['nullable', 'string', 'max:3000'],
            'other_contract_terms' => ['nullable', 'string', 'max:3000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{7,30}$/'],
            'deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'pending_review', 'published', 'closed', 'rejected'])],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'terms_accepted' => ['accepted'],
            'review_rubric' => ['nullable', 'string', 'max:8000', 'json'],
        ]);
    }

    private function normalizeScholarshipAcademicRequirement(array $validated): array
    {
        $scale = $validated['minimum_grade_scale'] ?? null;

        if ($scale === '') {
            $scale = null;
        }

        if (! AcademicRequirement::requiresNumeric($scale)) {
            $validated['minimum_gwa'] = null;
        }

        if (blank($validated['minimum_gwa'] ?? null) && in_array($scale, ['percentage', 'grade_point'], true)) {
            $scale = null;
        }

        $validated['minimum_grade_scale'] = $scale;

        return $validated;
    }

    private function normalizeScholarshipReviewRubric(array $validated, Request $request, ?Scholarship $scholarship = null): array
    {
        if (! $request->has('review_rubric')) {
            $validated['review_rubric'] = $scholarship?->review_rubric ?? ReviewRubric::DEFAULT;

            return $validated;
        }

        $validated['review_rubric'] = ReviewRubric::fromJson($validated['review_rubric'] ?? null);

        return $validated;
    }

    private function providerScholarshipStatus(Scholarship $scholarship, string $requestedStatus, array $validated): string
    {
        if ($requestedStatus === 'published' && $scholarship->status === 'published') {
            return $this->scholarshipHasReviewableChanges($scholarship, $validated)
                ? 'pending_review'
                : 'published';
        }

        if ($requestedStatus === 'closed' && in_array($scholarship->status, ['published', 'closed'], true)) {
            return 'closed';
        }

        return $requestedStatus === 'draft' ? 'draft' : 'pending_review';
    }

    private function scholarshipHasReviewableChanges(Scholarship $scholarship, array $validated): bool
    {
        $reviewableFields = [
            'image_path',
            'title',
            'category',
            'description',
            'eligibility',
            'eligible_education_levels',
            'eligible_courses',
            'eligible_school_types',
            'eligible_year_levels',
            'eligible_locations',
            'income_requirement',
            'location_name',
            'location_address',
            'latitude',
            'longitude',
            'requirements',
            'review_rubric',
            'award_amount',
            'minimum_gwa',
            'minimum_grade_scale',
            'slots_available',
            'application_mode',
            'renewal_policy',
            'return_service_contract',
            'other_contract_terms',
            'contact_email',
            'contact_number',
            'deadline',
        ];

        foreach ($reviewableFields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            if ($this->comparableScholarshipValue($scholarship->getAttribute($field))
                !== $this->comparableScholarshipValue($validated[$field])) {
                return true;
            }
        }

        return false;
    }

    private function comparableScholarshipValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (string) (float) $value;
        }

        return trim((string) $value);
    }

    private function notifyAdminsScholarshipSubmitted(Request $request, Scholarship $scholarship): void
    {
        User::query()
            ->where('role', 'admin')
            ->get()
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'scholarship_review',
                'title' => 'Scholarship ready for review',
                'message' => "{$request->user()->name} submitted {$scholarship->title} for admin review.",
                'action_url' => '/admin/reviews',
            ]));
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

    private function applicationPayload(ScholarshipApplication $application, bool $includeApplicantProfile = false): array
    {
        $readiness = $this->documentReadiness($application);
        $decisionSupport = app(DecisionSupportService::class);
        $dss = $decisionSupport->scoreApplication($application);
        $application->loadMissing('schedules');
        $application->scholarship?->loadMissing('providerAssessment');

        if ($includeApplicantProfile) {
            $application->loadMissing('applicant.applicantVerificationDocuments');
        }

        return [
            'id' => $application->id,
            'detail_url' => route('provider.applications.show', $application),
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
            'rubric_review' => ReviewRubric::result(
                $application->review_rubric_snapshot ?: ($application->scholarship?->review_rubric ?? []),
                $application->rubric_scores ?? [],
            ),
            'rubric_scored_at' => $application->rubric_scored_at?->format('M d, Y h:i A'),
            'status_progress' => $decisionSupport->statusProgress($application),
            'notes' => $application->notes,
            'review_notes' => $application->review_notes,
            'decision_reason' => $application->decision_reason,
            'awarded_amount' => $application->awarded_amount,
            'outcome_notes' => $application->outcome_notes,
            'outcome_at' => $application->outcome_at?->format('Y-m-d'),
            'distribution_scheduled_for' => $application->distribution_scheduled_for?->format('Y-m-d'),
            'distribution_scheduled_label' => $application->distribution_scheduled_for?->format('M d, Y'),
            'distribution_instructions' => $application->distribution_instructions,
            'reviewed_at' => $application->reviewed_at?->format('M d, Y h:i A'),
            'requires_student_response' => false,
            'can_receive_student_response' => false,
            'schedules' => $application->schedules
                ->sortBy('scheduled_at')
                ->map(fn (ApplicationSchedule $schedule) => ApplicationSchedulePayload::make($schedule))
                ->values(),
            'timeline' => $this->timelinePayload($application),
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            'applicant' => $this->applicantPayload($application, $includeApplicantProfile),
            'scholarship' => $application->scholarship
                ? $this->scholarshipPayload($application->scholarship)
                : null,
            'exam' => $application->scholarship?->providerAssessment
                ? $this->assessmentPayload($application->scholarship->providerAssessment)
                : null,
        ];
    }

    private function applicantPayload(ScholarshipApplication $application, bool $includeProfileDetails): array
    {
        $applicant = $application->applicant;
        $profile = $applicant?->studentProfile;
        $payload = [
            'name' => $applicant?->name,
            'email' => $applicant?->email,
            'username' => $applicant?->username,
            'contact_number' => $applicant?->contact_number,
            'education_level' => $profile?->education_level,
            'school' => $profile?->school,
            'school_type' => $profile?->school_type,
            'learner_reference_number' => $profile?->learner_reference_number,
            'course_or_strand' => $profile?->course_or_strand,
            'year_level' => $profile?->year_level,
            'gwa' => $profile?->gwa,
            'grading_scale' => $profile?->grading_scale,
            'income_bracket' => $profile?->income_bracket,
            'household_size' => $profile?->household_size,
            'preferred_categories' => $profile?->preferred_categories,
            'preferred_locations' => $profile?->preferred_locations,
            'willing_to_relocate' => $profile?->willing_to_relocate,
            'support_needs' => $profile?->support_needs,
            'scholarship_goal' => $profile?->scholarship_goal,
            'location' => collect([
                $profile?->barangay,
                $profile?->city,
                $profile?->province,
                $profile?->region,
            ])->filter()->implode(', '),
            'latitude' => $profile?->latitude,
            'longitude' => $profile?->longitude,
            'profile_verification_status' => $profile?->verification_status ?? 'unsubmitted',
            'profile_verified_at' => $profile?->verified_at?->format('M d, Y'),
        ];

        if (! $includeProfileDetails) {
            return $payload;
        }

        return array_merge($payload, [
            'first_name' => $profile?->first_name,
            'middle_initial' => $profile?->middle_initial,
            'last_name' => $profile?->last_name,
            'suffix' => $profile?->suffix,
            'gender' => $profile?->gender,
            'birthdate' => $profile?->birthdate?->format('M d, Y'),
            'age' => $profile?->birthdate?->age,
            'account_managed_by' => $profile?->account_managed_by,
            'enrollment_status' => $profile?->enrollment_status,
            'address' => $profile?->address,
            'profile_updated_at' => $profile?->updated_at?->format('M d, Y h:i A'),
            'profile_verification_notes' => $profile?->verification_notes,
            'guardian_name' => $profile?->guardian_name,
            'guardian_relationship' => $profile?->guardian_relationship,
            'guardian_contact' => $profile?->guardian_contact,
            'guardian_email' => $profile?->guardian_email,
            'guardian_is_account_owner' => (bool) $profile?->guardian_is_account_owner,
            'profile_proofs' => ($applicant?->applicantVerificationDocuments ?? collect())
                ->sortByDesc('uploaded_at')
                ->map(fn (ApplicantVerificationDocument $document) => $this->applicantProfileProofPayload($application, $document))
                ->values(),
        ]);
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
            'review_rubric' => $scholarship->review_rubric ?? [],
            'award_amount' => $scholarship->award_amount,
            'minimum_gwa' => $scholarship->minimum_gwa,
            'minimum_grade_scale' => AcademicRequirement::normalizeScale($scholarship->minimum_grade_scale, $scholarship->minimum_gwa),
            'minimum_grade_label' => AcademicRequirement::requirementLabel($scholarship->minimum_gwa, $scholarship->minimum_grade_scale),
            'slots_available' => $scholarship->slots_available,
            'application_mode' => $scholarship->application_mode,
            'renewal_policy' => $scholarship->renewal_policy,
            'return_service_contract' => $scholarship->return_service_contract,
            'other_contract_terms' => $scholarship->other_contract_terms,
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

    private function assessmentPayload(ProviderAssessment $assessment): array
    {
        return [
            'id' => $assessment->id,
            'title' => $assessment->title,
            'assessment_type' => $assessment->assessment_type,
            'image_path' => $assessment->image_path,
            'image_url' => filled($assessment->image_path)
                ? asset(ltrim($assessment->image_path, '/'))
                : asset('uploads/scholarship-default.jpg'),
            'description' => $assessment->description,
            'duration_minutes' => $assessment->duration_minutes,
            'passing_score' => $assessment->passing_score,
            'delivery_mode' => $assessment->delivery_mode,
            'venue' => $assessment->venue,
            'instructions' => $assessment->instructions,
            'status' => $assessment->status,
            'updated_at' => $assessment->updated_at?->format('M d, Y h:i A'),
        ];
    }

    private function duplicateScholarshipTitle(int $providerId, string $title): string
    {
        $baseTitle = preg_replace('/\s+\(Copy(?:\s+\d+)?\)$/', '', $title) ?: $title;
        $candidate = "{$baseTitle} (Copy)";
        $counter = 2;

        while (
            Scholarship::query()
                ->where('provider_id', $providerId)
                ->where('title', $candidate)
                ->exists()
        ) {
            $candidate = "{$baseTitle} (Copy {$counter})";
            $counter++;
        }

        return $candidate;
    }

    private function requestedProviderScholarship(Request $request): ?Scholarship
    {
        $scholarshipId = $request->integer('scholarship_id');

        if (! $scholarshipId) {
            return null;
        }

        return Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->withCount('bookmarks')
            ->findOrFail($scholarshipId);
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
        abort_unless(
            $request->user()->hasVerifiedEmail(),
            403,
            'Verify your email address before submitting a scholarship.'
        );

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
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'reviewed_by' => $document->reviewer?->name,
            'reviewed_at' => $document->reviewed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('documents.view', $document),
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

    private function applicantProfileProofPayload(
        ScholarshipApplication $application,
        ApplicantVerificationDocument $document,
    ): array {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'status' => $document->status,
            'review_notes' => $document->review_notes,
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('provider.applications.profile-proofs.view', [$application, $document]),
        ];
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

    private function ensureScheduleCanBePublished(ScholarshipApplication $application, string $type): void
    {
        $allowedStatuses = match ($type) {
            'exam' => ['qualified', 'shortlisted', 'interview', 'exam_qualified', 'exam_scheduled'],
            'interview' => ['under_review', 'qualified', 'shortlisted', 'interview'],
            'distribution' => ['approved', 'awarded', 'distribution_scheduled'],
            default => [],
        };

        if (! in_array($application->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'type' => match ($type) {
                    'distribution' => 'Approve or award the application before announcing distribution.',
                    'exam' => 'Qualify or shortlist the applicant before announcing an exam.',
                    default => 'Start or qualify the application review before announcing an interview.',
                },
            ]);
        }

        if ($type === 'exam' && $application->scholarship?->providerAssessment?->status !== 'active') {
            throw ValidationException::withMessages([
                'type' => 'Configure an active provider assessment before announcing an exam.',
            ]);
        }
    }

    private function scheduleTypeLabel(string $type): string
    {
        return match ($type) {
            'exam' => 'exam',
            'interview' => 'interview',
            'distribution' => 'reward distribution',
            default => 'activity',
        };
    }

    private function scheduleApplicationStatus(string $type): string
    {
        return match ($type) {
            'exam' => 'exam_scheduled',
            'interview' => 'interview',
            'distribution' => 'distribution_scheduled',
            default => 'under_review',
        };
    }

    private function scheduleDecisionReason(string $type): string
    {
        return match ($type) {
            'exam' => 'exam_scheduled',
            'interview' => 'for_interview',
            'distribution' => 'distribution_scheduled',
            default => 'other',
        };
    }

    private function applicationStatusNotificationPayload(
        ScholarshipApplication $application,
        string $status,
        ?string $decisionReason,
        bool $isOutcomeStatus
    ): array {
        $programTitle = $application->scholarship?->title ?: 'this scholarship';
        $actionUrl = route('dashboard.applications.show', $application, false);
        $distributionDate = $application->distribution_scheduled_for?->format('M d, Y');

        if ($status === 'under_review' && $decisionReason === 'missing_documents') {
            return [
                'type' => 'application_status',
                'title' => 'Documents needed',
                'message' => "Your application for {$programTitle} needs updated documents. Please review the provider note.",
                'action_url' => $actionUrl,
            ];
        }

        $payload = $isOutcomeStatus
            ? match ($status) {
                'awarded' => [
                    'type' => 'application_outcome',
                    'title' => 'Award recorded',
                    'message' => "Your application for {$programTitle} has been awarded. The provider will publish the reward distribution schedule.",
                ],
                'not_awarded' => [
                    'type' => 'application_outcome',
                    'title' => 'Award not recorded',
                    'message' => "Your application for {$programTitle} was not selected for an award. Review the provider note for details.",
                ],
                'disbursed' => [
                    'type' => 'application_outcome',
                    'title' => 'Scholarship reward distributed',
                    'message' => "The scholarship reward for {$programTitle} has been marked as distributed.",
                ],
                'renewed' => [
                    'type' => 'application_outcome',
                    'title' => 'Scholarship renewed',
                    'message' => "Your scholarship support for {$programTitle} has been renewed.",
                ],
                default => [
                    'type' => 'application_outcome',
                    'title' => 'Application outcome recorded',
                    'message' => "Your application for {$programTitle} is now {$this->statusLabel($status)}.",
                ],
            }
        : match ($status) {
            'submitted' => [
                'type' => 'application_status',
                'title' => 'Application returned to submitted',
                'message' => "Your application for {$programTitle} was returned to submitted status.",
            ],
            'under_review' => [
                'type' => 'application_status',
                'title' => 'Application review started',
                'message' => "Your application for {$programTitle} is now under provider review.",
            ],
            'qualified' => [
                'type' => 'application_status',
                'title' => 'Application qualified',
                'message' => "Your application for {$programTitle} has been marked qualified for provider review.",
            ],
            'shortlisted' => [
                'type' => 'application_status',
                'title' => 'Application shortlisted',
                'message' => "Your application for {$programTitle} has been shortlisted for the next review step.",
            ],
            'interview' => [
                'type' => 'application_status',
                'title' => 'Interview or follow-up needed',
                'message' => "Your application for {$programTitle} was moved to interview or follow-up screening.",
            ],
            'exam_qualified' => [
                'type' => 'application_status',
                'title' => 'Qualified for exam',
                'message' => "Your application for {$programTitle} passed initial screening and is qualified for the scholarship exam.",
            ],
            'exam_scheduled' => [
                'type' => 'application_status',
                'title' => 'Scholarship exam scheduled',
                'message' => "Your scholarship exam for {$programTitle} has been scheduled. Check provider notes for instructions.",
            ],
            'exam_taken' => [
                'type' => 'application_status',
                'title' => 'Exam marked taken',
                'message' => "Your scholarship exam for {$programTitle} was marked as taken.",
            ],
            'exam_passed' => [
                'type' => 'application_status',
                'title' => 'Exam passed',
                'message' => "You passed the scholarship exam for {$programTitle}. Your application will proceed to final review.",
            ],
            'exam_failed' => [
                'type' => 'application_status',
                'title' => 'Exam not passed',
                'message' => "Your application for {$programTitle} did not pass the scholarship exam. Review the provider note for details.",
            ],
            'approved' => [
                'type' => 'application_status',
                'title' => 'Application approved',
                'message' => "Your application for {$programTitle} has been approved. The provider will post reward distribution details when they are ready.",
            ],
            'distribution_scheduled' => [
                'type' => 'application_outcome',
                'title' => 'Reward distribution scheduled',
                'message' => "Your scholarship reward for {$programTitle} is scheduled for {$distributionDate}. Open the application to review provider instructions.",
            ],
            'rejected' => [
                'type' => 'application_status',
                'title' => 'Application not selected',
                'message' => "Your application for {$programTitle} was not selected. Review the provider note for details.",
            ],
            default => [
                'type' => 'application_status',
                'title' => 'Application status updated',
                'message' => "Your application for {$programTitle} is now {$this->statusLabel($status)}.",
            ],
        };

        if (in_array($status, ['rejected', 'not_awarded', 'exam_failed'], true) && filled($decisionReason)) {
            $payload['message'] .= " Reason: {$this->statusLabel($decisionReason)}.";
        }

        return array_merge($payload, ['action_url' => $actionUrl]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'exam_qualified' => 'Qualified for exam',
            'exam_scheduled' => 'Exam scheduled',
            'exam_taken' => 'Exam taken',
            'exam_passed' => 'Passed exam',
            'exam_failed' => 'Failed exam',
            'for_exam' => 'Meets exam eligibility',
            'exam_completed' => 'Exam completed',
            'passed_exam' => 'Passed exam',
            'failed_exam' => 'Failed exam',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }
}
