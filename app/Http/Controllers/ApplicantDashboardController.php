<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\ProviderAssessment;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBookmark;
use App\Models\ScholarshipFunnelEvent;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use App\Support\AcademicRequirement;
use App\Support\Terms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicantDashboardController extends Controller
{
    public function __construct(private readonly ScholarshipEligibilityService $eligibilityService)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        return view('dashboard');
    }

    public function scholarships(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        return view('dashboard-scholarships');
    }

    public function scholarshipDetail(Request $request, Scholarship $scholarship): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        abort_unless($scholarship->isAcceptingApplications(), 404);

        return view('dashboard-scholarship-detail', [
            'scholarship' => $scholarship,
        ]);
    }

    public function scholarshipDetailData(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($scholarship->status === 'published', 404);

        $viewEvent = ScholarshipFunnelEvent::record(
            $request->user(),
            'scholarship_viewed',
            $scholarship,
            source: 'web',
            deduplicationKey: "scholarship_viewed:web:{$request->user()->id}:{$scholarship->id}:".now()->toDateString(),
        );

        if ($viewEvent->wasRecentlyCreated) {
            $scholarship->increment('views_count');
        }

        $scholarship->load('provider.providerProfile')->loadCount('bookmarks');

        return response()->json([
            'user' => $this->userPayload($request),
            'profile_readiness' => $request->user()->applicantProfileReadiness(),
            'stats' => $this->statsPayload($request),
            'scholarship' => $this->scholarshipPayload($scholarship, $request->user()),
        ]);
    }

    public function trackApplicationStart(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($scholarship->isAcceptingApplications(), 404);

        if (! ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('applicant_id', $request->user()->id)
            ->exists()) {
            ScholarshipFunnelEvent::record(
                $request->user(),
                'application_started',
                $scholarship,
                source: 'web',
                metadata: [
                    'profile_complete' => $request->user()->hasCompleteApplicantProfile(),
                ],
                deduplicationKey: "application_started:web:{$request->user()->id}:{$scholarship->id}",
            );
        }

        return response()->json(['tracked' => true]);
    }

    public function applications(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        return view('dashboard-applications');
    }

    public function documents(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        return view('dashboard-documents');
    }

    public function applicationDetail(Request $request, ScholarshipApplication $application): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        abort_unless($application->applicant_id === $request->user()->id, 403);

        return view('dashboard-application-detail', [
            'application' => $application,
        ]);
    }

    public function profile(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        return view('dashboard-profile');
    }

    public function profileData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $user = $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile']);

        return response()->json([
            'user' => $user->publicPayload(),
            'profile_readiness' => $user->applicantProfileReadiness(),
            'match_summary' => $this->profileMatchSummary($user),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $scholarships = $this->publishedScholarships()->limit(8)->get();
        $applications = ScholarshipApplication::query()
            ->with(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile'])
            ->where('applicant_id', $request->user()->id)
            ->latest('submitted_at')
            ->limit(5)
            ->get();
        $applications->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));
        $this->syncApplicantReminders($request->user());

        return response()->json([
            'user' => $this->userPayload($request),
            'profile_readiness' => $request->user()->applicantProfileReadiness(),
            'stats' => $this->statsPayload($request),
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship, $request->user()))->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
            'next_steps' => [
                'Review available scholarship programs.',
                'Prepare documents listed in each scholarship requirement.',
                'Complete your applicant profile before submitting applications.',
            ],
        ]);
    }

    public function applicationsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $scholarships = $this->publishedScholarships()->get();
        $applications = ScholarshipApplication::query()
            ->with(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile'])
            ->where('applicant_id', $request->user()->id)
            ->latest('submitted_at')
            ->get();
        $this->syncApplicantReminders($request->user());

        return response()->json([
            'user' => $this->userPayload($request),
            'profile_readiness' => $request->user()->applicantProfileReadiness(),
            'stats' => $this->statsPayload($request),
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship, $request->user()))->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
        ]);
    }

    public function applicationDetailData(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($application->applicant_id === $request->user()->id, 403);

        $application->load(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile']);
        app(DecisionSupportService::class)->syncApplication($application);
        $application = $application->fresh()->load(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile']);

        return response()->json([
            'user' => $this->userPayload($request),
            'stats' => $this->statsPayload($request),
            'application' => $this->applicationPayload($application),
        ]);
    }

    public function documentsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $applications = ScholarshipApplication::query()
            ->with(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile'])
            ->where('applicant_id', $request->user()->id)
            ->latest('submitted_at')
            ->get();
        $documents = $applications->flatMap(fn (ScholarshipApplication $application) => $application->documents);
        $studentDocuments = StudentDocument::query()
            ->where('user_id', $request->user()->id)
            ->latest('uploaded_at')
            ->get();
        $this->syncApplicantReminders($request->user());

        return response()->json([
            'user' => $this->userPayload($request),
            'stats' => [
                'applications' => $applications->count(),
                'prepared' => $studentDocuments->count(),
                'uploaded' => $documents->count(),
                'accepted' => $documents->where('status', 'accepted')->count(),
                'pending' => $documents->where('status', 'pending')->count(),
                'needs_attention' => $documents->whereIn('status', ['rejected', 'needs_replacement'])->count(),
            ],
            'document_options' => $this->documentLibraryOptions($request),
            'prepared_documents' => $studentDocuments->map(fn (StudentDocument $document) => $this->studentDocumentPayload($document))->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
        ]);
    }

    public function uploadPreparedDocument(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $validated = $request->validate([
            'document_name' => ['required', 'string', 'max:255'],
            'document_file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'terms_accepted' => ['accepted'],
        ]);

        $documentName = trim($validated['document_name']);
        $file = $validated['document_file'];
        $existing = StudentDocument::query()
            ->where('user_id', $request->user()->id)
            ->where('document_name', $documentName)
            ->first();

        if ($existing) {
            Storage::disk('local')->delete($existing->path);
        }

        $path = $file->store("student-documents/{$request->user()->id}");
        $document = StudentDocument::query()->updateOrCreate([
            'user_id' => $request->user()->id,
            'document_name' => $documentName,
        ], [
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_at' => now(),
            'terms_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);

        ActivityLog::record(
            $request->user(),
            'prepared_document_uploaded',
            "{$request->user()->name} uploaded prepared document {$document->document_name}.",
            $request,
            ['student_document_id' => $document->id],
        );

        ScholarshipFunnelEvent::record(
            $request->user(),
            'prepared_document_uploaded',
            source: 'web',
            metadata: [
                'student_document_id' => $document->id,
                'document_name' => $document->document_name,
                'replaced_existing' => $existing !== null,
            ],
        );

        return response()->json([
            'message' => 'Prepared document saved.',
            'document' => $this->studentDocumentPayload($document),
        ]);
    }

    public function deletePreparedDocument(Request $request, StudentDocument $document): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($document->user_id === $request->user()->id, 403);

        Storage::disk('local')->delete($document->path);
        $documentName = $document->document_name;
        $document->delete();

        ActivityLog::record(
            $request->user(),
            'prepared_document_deleted',
            "{$request->user()->name} removed prepared document {$documentName}.",
            $request,
        );

        ScholarshipFunnelEvent::record(
            $request->user(),
            'prepared_document_deleted',
            source: 'web',
            metadata: ['document_name' => $documentName],
        );

        return response()->json([
            'message' => 'Prepared document removed.',
        ]);
    }

    public function downloadPreparedDocument(Request $request, StudentDocument $document)
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($document->user_id === $request->user()->id, 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->original_name);
    }

    public function viewPreparedDocument(Request $request, StudentDocument $document)
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($document->user_id === $request->user()->id, 403);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->response($document->path, $document->original_name);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $gradeMaximum = $request->input('grading_scale') === AcademicRequirement::SCALE_GRADE_POINT ? 5 : 100;

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'non_binary', 'prefer_not_to_say'])],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'account_managed_by' => ['nullable', Rule::in(['learner', 'parent_guardian', 'relative', 'school_representative', 'other'])],
            'education_level' => ['nullable', 'string', 'max:100'],
            'school' => ['nullable', 'string', 'max:255'],
            'school_type' => ['nullable', 'string', 'max:100'],
            'learner_reference_number' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.-]*$/'],
            'course_or_strand' => ['nullable', 'string', 'max:255'],
            'year_level' => ['nullable', 'string', 'max:100'],
            'enrollment_status' => ['nullable', 'string', 'max:100'],
            'gwa' => ['nullable', 'numeric', 'min:0', "max:{$gradeMaximum}"],
            'grading_scale' => ['nullable', Rule::in(AcademicRequirement::SCALES)],
            'income_bracket' => ['nullable', 'string', 'max:100'],
            'household_size' => ['nullable', 'integer', 'min:1', 'max:30'],
            'preferred_categories' => ['nullable', 'string', 'max:1000'],
            'preferred_locations' => ['nullable', 'string', 'max:1000'],
            'willing_to_relocate' => ['nullable', Rule::in(['yes', 'no', 'depends'])],
            'support_needs' => ['nullable', 'string', 'max:1500'],
            'scholarship_goal' => ['nullable', 'string', 'max:1500'],
            'address' => ['nullable', 'string', 'max:500'],
            'barangay' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'province' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'birthdate' => ['nullable', 'date', 'before:today'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_relationship' => ['nullable', 'string', 'max:100'],
            'guardian_contact' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'guardian_email' => ['nullable', 'email', 'max:255'],
            'guardian_is_account_owner' => ['nullable', 'boolean'],
        ]);

        if (! AcademicRequirement::requiresNumeric($validated['grading_scale'] ?? null)) {
            $validated['gwa'] = null;
        }

        $request->user()->studentProfile()->updateOrCreate([
            'user_id' => $request->user()->id,
        ], [
            ...$validated,
            'middle_initial' => filled($validated['middle_initial'] ?? null) ? strtoupper($validated['middle_initial']) : null,
            'guardian_is_account_owner' => (bool) ($validated['guardian_is_account_owner'] ?? false),
        ]);
        $request->user()->unsetRelation('studentProfile');

        ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents', 'scholarship'])
            ->where('applicant_id', $request->user()->id)
            ->get()
            ->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application, 'web_profile_updated'));

        $profileReadiness = $request->user()->applicantProfileReadiness();

        if ($profileReadiness['complete']) {
            ScholarshipFunnelEvent::record(
                $request->user(),
                'profile_completed',
                source: 'web',
                metadata: ['education_level' => $request->user()->studentProfile?->education_level],
                deduplicationKey: "profile_completed:{$request->user()->id}",
            );
        }

        ActivityLog::record(
            $request->user(),
            'profile_updated',
            "{$request->user()->name} updated their applicant profile.",
            $request,
        );

        return response()->json([
            'message' => 'Applicant profile updated.',
            'user' => $this->userPayload($request),
            'profile_readiness' => $request->user()->applicantProfileReadiness(),
            'match_summary' => $this->profileMatchSummary($request->user()),
        ]);
    }

    public function storeApplication(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        if (! $request->user()->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verify your email address before submitting an application.',
                'verification_required' => true,
            ], 403);
        }

        $profileReadiness = $request->user()->applicantProfileReadiness();

        if (! $profileReadiness['complete']) {
            return response()->json([
                'message' => 'Complete your student profile before applying.',
                'missing_fields' => $profileReadiness['missing'],
                'profile_readiness' => $profileReadiness,
            ], 422);
        }

        $validated = $request->validate([
            'scholarship_id' => [
                'required',
                Rule::exists('scholarships', 'id')->where(fn ($query) => $query->where('status', 'published')),
            ],
            'document_checklist' => ['sometimes', 'array'],
            'document_checklist.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'terms_accepted' => ['accepted'],
        ]);

        $alreadyApplied = ScholarshipApplication::query()
            ->where('scholarship_id', $validated['scholarship_id'])
            ->where('applicant_id', $request->user()->id)
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'message' => 'You already submitted an application for this scholarship.',
            ], 422);
        }

        $scholarship = Scholarship::query()
            ->with('provider.providerProfile')
            ->findOrFail($validated['scholarship_id']);

        if (! $scholarship->isAcceptingApplications()) {
            return response()->json([
                'message' => 'This scholarship is no longer accepting applications.',
            ], 422);
        }

        $eligibilityMatch = $this->eligibilityMatch($scholarship, $request->user());
        $eligibilityBlockers = $this->applicationEligibilityBlockers($eligibilityMatch);

        if ($eligibilityBlockers !== []) {
            return response()->json([
                'message' => 'You are not eligible to apply for this scholarship based on your current student profile.',
                'eligibility_match' => $eligibilityMatch,
                'blocking_criteria' => $eligibilityBlockers,
            ], 422);
        }

        $acceptedAt = now();
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $request->user()->id,
            'status' => 'submitted',
            'document_checklist' => $validated['document_checklist'] ?? [],
            'eligibility_score' => $eligibilityMatch['score'],
            'eligibility_breakdown' => $eligibilityMatch,
            'review_rubric_snapshot' => $scholarship->review_rubric ?? [],
            'notes' => $validated['notes'] ?? null,
            'submitted_at' => $acceptedAt,
            'terms_accepted_at' => $acceptedAt,
            'terms_version' => Terms::VERSION,
        ]);

        ApplicationStatusHistory::create([
            'scholarship_application_id' => $application->id,
            'changed_by' => $request->user()->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'review_notes' => 'Application submitted by applicant.',
            'changed_at' => now(),
        ]);

        $this->attachPreparedDocumentsToApplication($request->user(), $application, $validated['document_checklist'] ?? []);
        app(DecisionSupportService::class)->syncApplication($application, 'web_application_submitted');

        ScholarshipFunnelEvent::record(
            $request->user(),
            'application_submitted',
            $scholarship,
            $application,
            'web',
            [
                'eligibility_score' => $application->fresh()->eligibility_score,
                'dss_score' => $application->fresh()->dss_score,
            ],
            "application_submitted:{$application->id}",
        );

        ActivityLog::record(
            $request->user(),
            'application_submitted',
            "{$request->user()->name} submitted an application for {$scholarship->title}.",
            $request,
            ['application_id' => $application->id, 'scholarship_id' => $scholarship->id],
        );

        PortalNotification::create([
            'user_id' => $scholarship->provider_id,
            'type' => 'application',
            'title' => 'New scholarship application',
            'message' => "{$request->user()->name} submitted an application for {$scholarship->title}.",
            'action_url' => '/provider/applications',
        ]);
        PortalNotification::create([
            'user_id' => $request->user()->id,
            'type' => 'application_submitted',
            'title' => 'Application submitted',
            'message' => "Your application for {$scholarship->title} was submitted successfully.",
            'action_url' => '/dashboard/applications',
        ]);

        $freshApplication = $application->fresh()->load(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile']);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application' => $this->applicationPayload($freshApplication),
        ], 201);
    }

    public function saveScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($scholarship->status === 'published', 404);

        $bookmark = ScholarshipBookmark::query()->firstOrCreate([
            'scholarship_id' => $scholarship->id,
            'user_id' => $request->user()->id,
        ]);

        if ($bookmark->wasRecentlyCreated) {
            ScholarshipFunnelEvent::record(
                $request->user(),
                'scholarship_saved',
                $scholarship,
                source: 'web',
            );
        }

        ActivityLog::record(
            $request->user(),
            'scholarship_saved',
            "{$request->user()->name} saved scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id],
        );

        return response()->json([
            'message' => 'Scholarship saved.',
            'scholarship' => $this->scholarshipPayload($scholarship->fresh()->load('provider.providerProfile'), $request->user()),
            'stats' => $this->statsPayload($request),
        ]);
    }

    public function unsaveScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $deleted = ScholarshipBookmark::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        if ($deleted > 0) {
            ScholarshipFunnelEvent::record(
                $request->user(),
                'scholarship_unsaved',
                $scholarship,
                source: 'web',
            );
        }

        ActivityLog::record(
            $request->user(),
            'scholarship_unsaved',
            "{$request->user()->name} removed saved scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id],
        );

        return response()->json([
            'message' => 'Scholarship removed from saved list.',
            'scholarship' => $this->scholarshipPayload($scholarship->fresh()->load('provider.providerProfile'), $request->user()),
            'stats' => $this->statsPayload($request),
        ]);
    }

    public function uploadDocument(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($application->applicant_id === $request->user()->id, 403);

        $validated = $request->validate([
            'document_name' => ['required', 'string', 'max:255'],
            'document_file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
            'terms_accepted' => ['accepted'],
        ]);

        $file = $validated['document_file'];
        $existing = ApplicationDocument::query()
            ->where('scholarship_application_id', $application->id)
            ->where('document_name', $validated['document_name'])
            ->first();

        if ($existing) {
            Storage::disk('local')->delete($existing->path);
        }

        $path = $file->store("application-documents/{$application->id}");
        $document = ApplicationDocument::query()->updateOrCreate([
            'scholarship_application_id' => $application->id,
            'document_name' => $validated['document_name'],
        ], [
            'uploaded_by' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'status' => 'pending',
            'review_notes' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'uploaded_at' => now(),
            'terms_accepted_at' => now(),
            'terms_version' => Terms::VERSION,
        ]);

        ActivityLog::record(
            $request->user(),
            'document_uploaded',
            "{$request->user()->name} uploaded {$document->document_name} for application #{$application->id}.",
            $request,
            ['application_id' => $application->id, 'document_id' => $document->id],
        );

        ScholarshipFunnelEvent::record(
            $request->user(),
            'application_document_uploaded',
            application: $application,
            source: 'web',
            metadata: ['document_name' => $document->document_name],
        );

        $freshApplication = $application->fresh()->load(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile']);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'web_document_uploaded');

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'application' => $this->applicationPayload($freshApplication),
        ]);
    }

    public function deleteDocument(Request $request, ApplicationDocument $document): JsonResponse
    {
        $document->load('application');
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($document->application?->applicant_id === $request->user()->id, 403);

        Storage::disk('local')->delete($document->path);
        $application = $document->application;
        $documentName = $document->document_name;
        $document->delete();

        ActivityLog::record(
            $request->user(),
            'document_deleted',
            "{$request->user()->name} removed {$documentName} from application #{$application?->id}.",
            $request,
            ['application_id' => $application?->id],
        );

        ScholarshipFunnelEvent::record(
            $request->user(),
            'application_document_deleted',
            application: $application,
            source: 'web',
            metadata: ['document_name' => $documentName],
        );

        $freshApplication = $application->fresh()->load(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile']);
        app(DecisionSupportService::class)->syncApplication($freshApplication, 'web_document_deleted');

        return response()->json([
            'message' => 'Document removed.',
            'application' => $this->applicationPayload($freshApplication),
        ]);
    }

    public function respondToApplication(Request $request, ScholarshipApplication $application): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($application->applicant_id === $request->user()->id, 403);

        return response()->json([
            'message' => 'No in-platform acceptance is required. The scholarship provider manages confirmation and reward distribution directly.',
        ], 422);
    }

    private function ensureApplicant(Request $request): ?RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        if ($request->user()->isAdmin()) {
            return redirect()->route('admin.index');
        }

        if ($request->user()->isProvider()) {
            return redirect()->route('provider.index');
        }

        abort_unless($request->user()->isApplicant(), 403);

        return null;
    }

    private function publishedScholarships()
    {
        return Scholarship::query()
            ->with('provider.providerProfile')
            ->withCount('bookmarks')
            ->acceptingApplications()
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->latest();
    }

    private function userPayload(Request $request): array
    {
        return $request->user()->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload();
    }

    private function statsPayload(Request $request): array
    {
        return [
            'available_scholarships' => Scholarship::query()->acceptingApplications()->count(),
            'applications' => ScholarshipApplication::query()->where('applicant_id', $request->user()->id)->count(),
            'saved' => ScholarshipBookmark::query()->where('user_id', $request->user()->id)->count(),
        ];
    }

    private function scholarshipPayload(Scholarship $scholarship, ?User $user = null): array
    {
        $match = $this->eligibilityMatch($scholarship, $user);
        $preparedDocuments = $this->preparedDocumentReadiness($scholarship, $user);
        $distanceKm = $this->distanceKm($user?->studentProfile, $scholarship);
        $saved = $user
            ? ScholarshipBookmark::query()
                ->where('scholarship_id', $scholarship->id)
                ->where('user_id', $user->id)
                ->exists()
            : false;
        $hasApplied = $user
            ? ScholarshipApplication::query()
                ->where('scholarship_id', $scholarship->id)
                ->where('applicant_id', $user->id)
                ->exists()
            : false;
        $profileComplete = $user ? (bool) $user->applicantProfileReadiness()['complete'] : false;

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
            'distance_km' => $distanceKm,
            'distance_label' => $distanceKm === null ? null : number_format($distanceKm, 1).' km away',
            'requirements' => $scholarship->requirements,
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
            'deadline' => $scholarship->deadline?->format('M d, Y'),
            'bookmarks_count' => $scholarship->bookmarks_count ?? $scholarship->bookmarks()->count(),
            'is_saved' => $saved,
            'has_applied' => $hasApplied,
            'can_start_application' => $scholarship->isAcceptingApplications()
                && $profileComplete
                && (bool) ($match['is_eligible'] ?? false)
                && ! $hasApplied,
            'eligibility_match' => $match,
            'preference_match' => $this->preferenceMatch($scholarship, $user),
            'prepared_documents' => $preparedDocuments,
            'eligibility_guide' => [
                'requires_gwa' => filled($scholarship->minimum_gwa),
                'minimum_gwa' => $scholarship->minimum_gwa,
                'minimum_grade_scale' => AcademicRequirement::normalizeScale($scholarship->minimum_grade_scale, $scholarship->minimum_gwa),
                'minimum_grade_label' => AcademicRequirement::requirementLabel($scholarship->minimum_gwa, $scholarship->minimum_grade_scale),
                'required_documents' => count($this->documentRequirements($scholarship)),
                'prepared_documents' => $preparedDocuments['uploaded'],
                'note' => AcademicRequirement::requirementLabel($scholarship->minimum_gwa, $scholarship->minimum_grade_scale),
            ],
            'provider' => [
                'name' => $scholarship->provider?->provider_name ?? $scholarship->provider?->name,
                'type' => $scholarship->provider?->provider_type,
            ],
        ];
    }

    private function scholarshipImageUrl(Scholarship $scholarship): string
    {
        if (filled($scholarship->image_path)) {
            return asset(ltrim($scholarship->image_path, '/'));
        }

        return asset('uploads/scholarship-default.jpg');
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

    private function distanceKm($profile, Scholarship $scholarship): ?float
    {
        if (
            $profile?->latitude === null
            || $profile?->longitude === null
            || $scholarship->latitude === null
            || $scholarship->longitude === null
        ) {
            return null;
        }

        $studentLatitude = deg2rad((float) $profile->latitude);
        $studentLongitude = deg2rad((float) $profile->longitude);
        $scholarshipLatitude = deg2rad((float) $scholarship->latitude);
        $scholarshipLongitude = deg2rad((float) $scholarship->longitude);

        $latitudeDelta = $scholarshipLatitude - $studentLatitude;
        $longitudeDelta = $scholarshipLongitude - $studentLongitude;
        $angle = (sin($latitudeDelta / 2) ** 2)
            + cos($studentLatitude) * cos($scholarshipLatitude) * (sin($longitudeDelta / 2) ** 2);
        $angle = min(1, max(0, $angle));

        return round(6371 * 2 * atan2(sqrt($angle), sqrt(1 - $angle)), 1);
    }

    private function applicationPayload(ScholarshipApplication $application): array
    {
        $decisionSupport = app(DecisionSupportService::class);
        $dss = $decisionSupport->scoreApplication($application);
        $application->scholarship?->loadMissing('providerAssessment');
        $examStatuses = ['exam_qualified', 'exam_scheduled', 'exam_taken', 'exam_passed', 'exam_failed'];
        $assessment = in_array($application->status, $examStatuses, true)
            && $application->scholarship?->providerAssessment?->status === 'active'
                ? $application->scholarship->providerAssessment
                : null;

        return [
            'id' => $application->id,
            'detail_url' => route('dashboard.applications.show', $application),
            'status' => $application->status,
            'document_checklist' => $application->document_checklist ?? [],
            'document_readiness' => $this->documentReadiness($application),
            'eligibility_score' => $application->eligibility_score,
            'eligibility_breakdown' => $application->eligibility_breakdown,
            'documents' => $application->documents->map(fn (ApplicationDocument $document) => $this->documentPayload($document))->values(),
            'notes' => $application->notes,
            'review_notes' => $application->review_notes,
            'decision_reason' => $application->decision_reason,
            'awarded_amount' => $application->awarded_amount,
            'outcome_notes' => $application->outcome_notes,
            'outcome_at' => $application->outcome_at?->format('M d, Y'),
            'distribution_scheduled_for' => $application->distribution_scheduled_for?->format('M d, Y'),
            'distribution_instructions' => $application->distribution_instructions,
            'requires_student_response' => false,
            'can_respond' => false,
            'dss_score' => $dss['score'],
            'dss_recommendation' => $dss['recommendation'],
            'dss_breakdown' => $dss,
            'dss_explanation' => $decisionSupport->explainApplication($application, $dss),
            'status_progress' => $decisionSupport->statusProgress($application),
            'timeline' => $this->timelinePayload($application),
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            'scholarship' => $application->scholarship
                ? $this->scholarshipPayload($application->scholarship, $application->applicant)
                : null,
            'exam' => $assessment ? $this->assessmentPayload($assessment) : null,
        ];
    }

    private function assessmentPayload(ProviderAssessment $assessment): array
    {
        return [
            'title' => $assessment->title,
            'assessment_type' => $assessment->assessment_type,
            'image_url' => filled($assessment->image_path)
                ? asset(ltrim($assessment->image_path, '/'))
                : asset('uploads/scholarship-default.jpg'),
            'description' => $assessment->description,
            'duration_minutes' => $assessment->duration_minutes,
            'passing_score' => $assessment->passing_score,
            'delivery_mode' => $assessment->delivery_mode,
            'venue' => $assessment->venue,
            'instructions' => $assessment->instructions,
        ];
    }

    private function documentReadiness(ScholarshipApplication $application): array
    {
        return $this->eligibilityService->applicationDocumentReadiness($application);
    }

    private function documentRequirements(?Scholarship $scholarship): array
    {
        return $this->eligibilityService->documentRequirements($scholarship);
    }

    private function splitDocumentRequirements(?string $requirements): array
    {
        return $this->eligibilityService->splitDocumentRequirements($requirements);
    }

    private function documentLibraryOptions(Request $request): array
    {
        $commonDocuments = [
            'Completed application form',
            'Certificate of enrollment',
            'Latest report card or grades',
            'School ID',
            'Proof of income',
            'Certificate of indigency',
            'Parent or guardian valid ID',
            'Recommendation letter',
        ];
        $publishedRequirements = Scholarship::query()
            ->acceptingApplications()
            ->whereNotNull('requirements')
            ->pluck('requirements')
            ->flatMap(fn (?string $requirements) => $this->splitDocumentRequirements($requirements));
        $applicationRequirements = ScholarshipApplication::query()
            ->with('scholarship')
            ->where('applicant_id', $request->user()->id)
            ->get()
            ->flatMap(fn (ScholarshipApplication $application) => $this->documentRequirements($application->scholarship));

        return collect($commonDocuments)
            ->merge($publishedRequirements)
            ->merge($applicationRequirements)
            ->map(fn (string $document) => trim($document))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function preparedDocumentReadiness(Scholarship $scholarship, ?User $user): array
    {
        return $this->eligibilityService->preparedDocumentReadiness($scholarship, $user);
    }

    private function attachPreparedDocumentsToApplication(User $user, ScholarshipApplication $application, array $confirmedDocuments): void
    {
        $application->loadMissing('scholarship');
        $requirements = $confirmedDocuments !== []
            ? collect($confirmedDocuments)->map(fn (string $document) => trim($document))->filter()->values()->all()
            : $this->documentRequirements($application->scholarship);

        if ($requirements === []) {
            return;
        }

        $user->loadMissing('studentDocuments');
        $preparedDocuments = $user->studentDocuments
            ->filter(fn (StudentDocument $document) => in_array($document->document_name, $requirements, true));

        foreach ($preparedDocuments as $studentDocument) {
            if (! Storage::disk('local')->exists($studentDocument->path)) {
                continue;
            }

            $extension = pathinfo($studentDocument->original_name, PATHINFO_EXTENSION);
            Storage::disk('local')->makeDirectory("application-documents/{$application->id}");
            $targetPath = 'application-documents/'.$application->id.'/'.(string) Str::uuid().($extension ? ".{$extension}" : '');

            Storage::disk('local')->copy($studentDocument->path, $targetPath);
            ApplicationDocument::query()->updateOrCreate([
                'scholarship_application_id' => $application->id,
                'document_name' => $studentDocument->document_name,
            ], [
                'uploaded_by' => $user->id,
                'original_name' => $studentDocument->original_name,
                'path' => $targetPath,
                'mime_type' => $studentDocument->mime_type,
                'size' => $studentDocument->size,
                'status' => 'pending',
                'review_notes' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'uploaded_at' => now(),
                'terms_accepted_at' => $application->terms_accepted_at ?? now(),
                'terms_version' => $application->terms_version ?? Terms::VERSION,
            ]);
        }
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
            'reviewed_at' => $document->reviewed_at?->format('M d, Y h:i A'),
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('documents.view', $document),
            'download_url' => route('documents.download', $document),
        ];
    }

    private function studentDocumentPayload(StudentDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_name' => $document->document_name,
            'original_name' => $document->original_name,
            'size' => $document->size,
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'view_url' => route('dashboard.student-documents.view', $document),
            'download_url' => route('dashboard.student-documents.download', $document),
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

    private function profileMatchSummary(User $user): array
    {
        $evaluations = $this->publishedScholarships()
            ->get()
            ->map(function (Scholarship $scholarship) use ($user): array {
                $match = $this->eligibilityMatch($scholarship, $user);
                $profileCriteria = collect($match['criteria'] ?? [])
                    ->reject(fn (array $criterion): bool => ($criterion['key'] ?? null) === 'documents')
                    ->filter(fn (array $criterion): bool => in_array($criterion['status'] ?? null, ['pass', 'fail', 'missing'], true))
                    ->values();
                $passed = $profileCriteria->where('status', 'pass')->count();
                $score = $profileCriteria->isEmpty()
                    ? 100
                    : (int) round(($passed / $profileCriteria->count()) * 100);
                $eligible = ! $profileCriteria->contains(fn (array $criterion): bool => ($criterion['status'] ?? null) === 'fail');

                return [
                    'eligible' => $eligible,
                    'score' => $score,
                    'criteria' => $profileCriteria->all(),
                    'preference' => $this->preferenceMatch($scholarship, $user),
                ];
            });
        $topGaps = $evaluations
            ->flatMap(fn (array $evaluation) => $evaluation['criteria'])
            ->filter(fn (array $criterion): bool => in_array($criterion['status'] ?? null, ['fail', 'missing'], true))
            ->groupBy(fn (array $criterion): string => (string) ($criterion['key'] ?? $criterion['label'] ?? 'other'))
            ->map(fn ($criteria): array => [
                'key' => $criteria->first()['key'] ?? null,
                'label' => $criteria->first()['label'] ?? 'Profile detail',
                'count' => $criteria->count(),
                'has_blocker' => $criteria->contains(fn (array $criterion): bool => ($criterion['status'] ?? null) === 'fail'),
            ])
            ->sortByDesc(fn (array $gap): int => ($gap['has_blocker'] ? 1000 : 0) + $gap['count'])
            ->take(4)
            ->values();

        return [
            'available_programs' => $evaluations->count(),
            'eligible_programs' => $evaluations->where('eligible', true)->count(),
            'strong_matches' => $evaluations->filter(fn (array $evaluation): bool => $evaluation['eligible'] && $evaluation['score'] >= 80)->count(),
            'needs_review' => $evaluations->filter(fn (array $evaluation): bool => $evaluation['eligible'] && $evaluation['score'] < 80)->count(),
            'blocked_programs' => $evaluations->where('eligible', false)->count(),
            'preference_matches' => $evaluations->filter(fn (array $evaluation): bool => ($evaluation['preference']['configured'] ?? false)
                && (int) ($evaluation['preference']['score'] ?? 0) >= 80)->count(),
            'top_gaps' => $topGaps,
            'calculated_at' => now()->toISOString(),
        ];
    }

    private function preferenceMatch(Scholarship $scholarship, ?User $user): array
    {
        $profile = $user?->studentProfile;

        if (! $profile) {
            return [
                'configured' => false,
                'score' => null,
                'label' => 'No preferences set',
                'criteria' => [],
            ];
        }

        $criteria = [];
        $categoryPreferences = $this->splitOptions($profile->preferred_categories);

        if ($categoryPreferences !== []) {
            $matchesCategory = filled($scholarship->category)
                && $this->matchesAnyOption($scholarship->category, $categoryPreferences);
            $criteria[] = [
                'key' => 'category',
                'label' => 'Scholarship type',
                'status' => $matchesCategory ? 'match' : 'miss',
                'note' => $matchesCategory ? 'Matches a preferred scholarship type.' : 'Outside the selected scholarship types.',
            ];
        }

        $locationPreferences = $this->splitOptions($profile->preferred_locations);

        if ($locationPreferences !== []) {
            $locationText = collect([
                $scholarship->eligible_locations,
                $scholarship->location_name,
                $scholarship->location_address,
            ])->filter()->implode(', ');
            $studentLocations = collect([$profile->city, $profile->province, $profile->region])->filter()->values()->all();
            $hasOpenLocation = collect($locationPreferences)->contains(fn (string $option): bool => $this->isOpenOption($option));
            $wantsOnline = collect($locationPreferences)->contains(fn (string $option): bool => str_contains(strtolower($option), 'online'));
            $wantsNearby = collect($locationPreferences)->contains(fn (string $option): bool => str_contains(strtolower($option), 'near my home'));
            $explicitLocations = collect($locationPreferences)
                ->reject(fn (string $option): bool => $this->isOpenOption($option)
                    || str_contains(strtolower($option), 'online')
                    || str_contains(strtolower($option), 'near my home'))
                ->values()
                ->all();
            $distanceKm = $this->distanceKm($profile, $scholarship);
            $matchesLocation = $hasOpenLocation
                || ($wantsOnline && in_array($scholarship->application_mode, ['online', 'hybrid'], true))
                || ($wantsNearby && (($distanceKm !== null && $distanceKm <= 50)
                    || (filled($locationText) && $studentLocations !== [] && $this->matchesAnyOption($locationText, $studentLocations))))
                || ($explicitLocations !== [] && filled($locationText) && $this->matchesAnyOption($locationText, $explicitLocations));

            $criteria[] = [
                'key' => 'location',
                'label' => 'Preferred location',
                'status' => $matchesLocation ? 'match' : 'miss',
                'note' => $matchesLocation ? 'Matches a selected location preference.' : 'Outside the selected location preferences.',
            ];
        }

        if ($profile->willing_to_relocate === 'no') {
            $distanceKm = $this->distanceKm($profile, $scholarship);

            if ($distanceKm !== null) {
                $fitsTravelPreference = $distanceKm <= 50 || in_array($scholarship->application_mode, ['online', 'hybrid'], true);
                $criteria[] = [
                    'key' => 'travel',
                    'label' => 'Travel preference',
                    'status' => $fitsTravelPreference ? 'match' : 'miss',
                    'note' => $fitsTravelPreference ? 'Fits the local-only preference.' : 'May require travel or relocation.',
                ];
            }
        }

        $matched = collect($criteria)->where('status', 'match')->count();
        $score = $criteria === [] ? null : (int) round(($matched / count($criteria)) * 100);

        return [
            'configured' => $criteria !== [],
            'score' => $score,
            'label' => match (true) {
                $score === null => 'No preferences set',
                $score >= 80 => 'Fits your preferences',
                $score >= 50 => 'Some preferences match',
                default => 'Outside your preferences',
            },
            'criteria' => $criteria,
        ];
    }

    private function eligibilityMatch(Scholarship $scholarship, ?User $user): array
    {
        return $this->eligibilityService->evaluate($scholarship, $user);
    }

    private function applicationEligibilityBlockers(array $eligibilityMatch): array
    {
        return $this->eligibilityService->blockers($eligibilityMatch);
    }

    private function splitOptions(?string $value): array
    {
        return $this->eligibilityService->splitOptions($value);
    }

    private function matchesAnyOption(string $value, array $options): bool
    {
        return $this->eligibilityService->matchesAnyOption($value, $options);
    }

    private function hasOpenOption(array $options): bool
    {
        return $this->eligibilityService->hasOpenOption($options);
    }

    private function isOpenOption(?string $option): bool
    {
        return $this->eligibilityService->isOpenOption($option);
    }

    private function syncApplicantReminders(User $user): void
    {
        $profileReadiness = $user->applicantProfileReadiness();

        if (! $profileReadiness['complete']) {
            PortalNotification::updateOrCreate([
                'user_id' => $user->id,
                'type' => 'profile_reminder',
                'title' => 'Complete your student profile',
            ], [
                'message' => "Your profile is {$profileReadiness['percent']}% complete. Complete it before submitting applications.",
                'action_url' => '/dashboard/profile',
            ]);
        }

        $applications = ScholarshipApplication::query()
            ->with(['documents', 'scholarship'])
            ->where('applicant_id', $user->id)
            ->whereIn('status', ['submitted', 'under_review', 'qualified', 'shortlisted', 'interview'])
            ->get();

        foreach ($applications as $application) {
            $documentReadiness = $this->documentReadiness($application);

            if (($documentReadiness['uploaded_percent'] ?? 100) >= 100) {
                continue;
            }

            $missing = collect($documentReadiness['missing'] ?? [])->take(3)->implode(', ');

            PortalNotification::updateOrCreate([
                'user_id' => $user->id,
                'type' => 'document_reminder',
                'title' => "Documents needed for {$application->scholarship?->title}",
            ], [
                'message' => $missing
                    ? "Upload or replace these requirements: {$missing}."
                    : 'Upload the remaining requirements for this application.',
                'action_url' => '/dashboard/applications',
            ]);
        }

        Scholarship::query()
            ->where('status', 'published')
            ->whereDate('deadline', '>=', now()->toDateString())
            ->whereDate('deadline', '<=', now()->addDays(7)->toDateString())
            ->whereHas('bookmarks', fn ($query) => $query->where('user_id', $user->id))
            ->whereDoesntHave('applications', fn ($query) => $query->where('applicant_id', $user->id))
            ->get()
            ->each(fn (Scholarship $scholarship) => PortalNotification::updateOrCreate([
                'user_id' => $user->id,
                'type' => 'deadline_reminder',
                'title' => "Deadline approaching: {$scholarship->title}",
            ], [
                'message' => "This saved scholarship is due on {$scholarship->deadline?->format('M d, Y')}.",
                'action_url' => "/dashboard/scholarships/{$scholarship->id}",
            ]));
    }
}
