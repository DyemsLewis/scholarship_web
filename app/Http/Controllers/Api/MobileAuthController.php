<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ApplicationDocument;
use App\Models\ApplicationSchedule;
use App\Models\ApplicationStatusHistory;
use App\Models\MobileApiToken;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBookmark;
use App\Models\ScholarshipFunnelEvent;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Services\ScholarshipEligibilityService;
use App\Support\AcademicRequirement;
use App\Support\ApplicationSchedulePayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MobileAuthController extends Controller
{
    public function __construct(private readonly ScholarshipEligibilityService $eligibilityService)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['nullable', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'min:4', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:users,username'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $middleInitial = strtoupper($validated['middle_initial']);

        $user = User::create([
            'email' => $validated['email'],
            'username' => $validated['username'],
            'role' => 'applicant',
            'password' => $validated['password'],
        ]);

        $user->studentProfile()->create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_initial' => $middleInitial,
            'contact_number' => $validated['contact_number'],
        ]);

        ActivityLog::record(
            $user,
            'mobile_registered',
            "{$user->name} registered from the mobile app.",
            $request,
        );

        return response()->json([
            'message' => 'Registration complete.',
            'token' => $this->createToken($user),
            'user' => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            ActivityLog::record(
                null,
                'mobile_login_failed',
                "Failed mobile login attempt for {$validated['email']}.",
                $request,
                ['email' => $validated['email']],
            );

            return response()->json([
                'message' => 'The email or password is incorrect.',
            ], 422);
        }

        if ($user->role !== 'applicant') {
            return response()->json([
                'message' => 'Only applicant accounts can use the mobile app.',
            ], 403);
        }

        if ($user->isSuspended()) {
            ActivityLog::record(
                $user,
                'mobile_login_blocked_suspended',
                "{$user->name} attempted mobile login while suspended.",
                $request,
            );

            return response()->json([
                'message' => 'Your account is suspended. Contact an administrator for help.',
            ], 403);
        }

        if ($user->must_reset_password) {
            ActivityLog::record(
                $user,
                'mobile_login_blocked_password_reset_required',
                "{$user->name} attempted mobile login with a required password reset.",
                $request,
            );

            return response()->json([
                'message' => 'A password reset is required before you can use the mobile app.',
            ], 423);
        }

        ActivityLog::record(
            $user,
            'mobile_login',
            "{$user->name} logged in from the mobile app.",
            $request,
        );

        return response()->json([
            'message' => 'Login successful.',
            'token' => $this->createToken($user),
            'user' => $this->userPayload($user),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $scholarships = $this->publishedScholarships()->get();
        $applications = ScholarshipApplication::query()
            ->with(['documents', 'schedules', 'statusHistories.actor', 'scholarship.provider.providerProfile'])
            ->where('applicant_id', $user->id)
            ->latest('submitted_at')
            ->get();
        $applications->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));
        $this->syncApplicantReminders($user);

        return response()->json([
            'user' => $this->userPayload($user),
            'stats' => $this->statsPayload($user),
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship, $user))->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
            'notifications' => $this->notificationsPayload($user),
            'next_steps' => [
                'Complete your applicant profile for better match scores.',
                'Upload prepared documents before applying to faster-moving programs.',
                'Save scholarship programs you want to revisit.',
                'Use the application wizard to confirm requirements and submit applications.',
            ],
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $gradeMaximum = $request->input('grading_scale') === AcademicRequirement::SCALE_GRADE_POINT ? 5 : 100;

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'suffix' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'non_binary', 'prefer_not_to_say'])],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
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
            'guardian_contact' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
        ]);

        if (! AcademicRequirement::requiresNumeric($validated['grading_scale'] ?? null)) {
            $validated['gwa'] = null;
        }

        $user->studentProfile()->updateOrCreate([
            'user_id' => $user->id,
        ], [
            ...$validated,
            'middle_initial' => filled($validated['middle_initial'] ?? null) ? strtoupper($validated['middle_initial']) : null,
        ]);

        $user->unsetRelation('studentProfile');

        if ($user->hasCompleteApplicantProfile()) {
            ScholarshipFunnelEvent::record(
                $user,
                'profile_completed',
                source: 'mobile',
                metadata: ['education_level' => $user->studentProfile?->education_level],
                deduplicationKey: "profile_completed:{$user->id}",
            );
        }

        ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents', 'scholarship'])
            ->where('applicant_id', $user->id)
            ->get()
            ->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application, 'mobile_profile_updated'));

        ActivityLog::record(
            $user,
            'mobile_profile_updated',
            "{$user->name} updated their profile from the mobile app.",
            $request,
        );

        $freshUser = $user->fresh();

        return response()->json([
            'message' => 'Applicant profile updated.',
            'user' => $this->userPayload($freshUser),
            'profile_readiness' => $freshUser->applicantProfileReadiness(),
        ]);
    }

    public function documents(Request $request): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $applications = ScholarshipApplication::query()
            ->with(['documents', 'schedules', 'statusHistories.actor', 'scholarship.provider.providerProfile'])
            ->where('applicant_id', $user->id)
            ->latest('submitted_at')
            ->get();
        $documents = $applications->flatMap(fn (ScholarshipApplication $application) => $application->documents);
        $studentDocuments = StudentDocument::query()
            ->where('user_id', $user->id)
            ->latest('uploaded_at')
            ->get();
        $this->syncApplicantReminders($user);

        return response()->json([
            'user' => $this->userPayload($user),
            'stats' => [
                'applications' => $applications->count(),
                'prepared' => $studentDocuments->count(),
                'uploaded' => $documents->count(),
                'accepted' => $documents->where('status', 'accepted')->count(),
                'pending' => $documents->where('status', 'pending')->count(),
                'needs_attention' => $documents->whereIn('status', ['rejected', 'needs_replacement'])->count(),
            ],
            'document_options' => $this->documentLibraryOptions($user),
            'prepared_documents' => $studentDocuments->map(fn (StudentDocument $document) => $this->studentDocumentPayload($document))->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
        ]);
    }

    public function uploadPreparedDocument(Request $request): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate([
            'document_name' => ['required', 'string', 'max:255'],
            'document_file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ]);

        $documentName = trim($validated['document_name']);
        $file = $validated['document_file'];
        $existing = StudentDocument::query()
            ->where('user_id', $user->id)
            ->where('document_name', $documentName)
            ->first();

        if ($existing) {
            Storage::disk('local')->delete($existing->path);
        }

        $path = $file->store("student-documents/{$user->id}");
        $document = StudentDocument::query()->updateOrCreate([
            'user_id' => $user->id,
            'document_name' => $documentName,
        ], [
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_at' => now(),
        ]);

        ActivityLog::record(
            $user,
            'mobile_prepared_document_uploaded',
            "{$user->name} uploaded prepared document {$document->document_name} from mobile.",
            $request,
            ['student_document_id' => $document->id],
        );

        ScholarshipFunnelEvent::record(
            $user,
            'prepared_document_uploaded',
            source: 'mobile',
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
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($document->user_id !== $user->id) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        Storage::disk('local')->delete($document->path);
        $documentName = $document->document_name;
        $document->delete();

        ActivityLog::record(
            $user,
            'mobile_prepared_document_deleted',
            "{$user->name} removed prepared document {$documentName} from mobile.",
            $request,
        );

        ScholarshipFunnelEvent::record(
            $user,
            'prepared_document_deleted',
            source: 'mobile',
            metadata: ['document_name' => $documentName],
        );

        return response()->json([
            'message' => 'Prepared document removed.',
        ]);
    }

    public function storeApplication(Request $request): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verify your email address before submitting an application.',
                'verification_required' => true,
            ], 403);
        }

        $profileReadiness = $user->applicantProfileReadiness();

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
        ]);

        $alreadyApplied = ScholarshipApplication::query()
            ->where('scholarship_id', $validated['scholarship_id'])
            ->where('applicant_id', $user->id)
            ->exists();

        if ($alreadyApplied) {
            return response()->json([
                'message' => 'You already submitted an application for this scholarship.',
            ], 422);
        }

        $scholarship = Scholarship::query()->findOrFail($validated['scholarship_id']);

        if (! $scholarship->isAcceptingApplications()) {
            return response()->json([
                'message' => 'This scholarship is no longer accepting applications.',
            ], 422);
        }

        ScholarshipFunnelEvent::record(
            $user,
            'application_started',
            $scholarship,
            source: 'mobile',
            metadata: ['profile_complete' => true],
            deduplicationKey: "application_started:mobile:{$user->id}:{$scholarship->id}",
        );

        $eligibilityMatch = $this->eligibilityMatch($scholarship, $user);
        $eligibilityBlockers = $this->applicationEligibilityBlockers($eligibilityMatch);

        if ($eligibilityBlockers !== []) {
            return response()->json([
                'message' => 'You are not eligible to apply for this scholarship based on your current student profile.',
                'eligibility_match' => $eligibilityMatch,
                'blocking_criteria' => $eligibilityBlockers,
            ], 422);
        }

        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $user->id,
            'status' => 'submitted',
            'document_checklist' => $validated['document_checklist'] ?? [],
            'eligibility_score' => $eligibilityMatch['score'],
            'eligibility_breakdown' => $eligibilityMatch,
            'review_rubric_snapshot' => $scholarship->review_rubric ?? [],
            'notes' => $validated['notes'] ?? null,
            'submitted_at' => now(),
        ]);

        ApplicationStatusHistory::create([
            'scholarship_application_id' => $application->id,
            'changed_by' => $user->id,
            'from_status' => null,
            'to_status' => 'submitted',
            'review_notes' => 'Application submitted from the mobile app.',
            'changed_at' => now(),
        ]);

        $this->attachPreparedDocumentsToApplication($user, $application, $validated['document_checklist'] ?? []);
        app(DecisionSupportService::class)->syncApplication($application, 'mobile_application_submitted');

        $application->refresh();
        ScholarshipFunnelEvent::record(
            $user,
            'application_submitted',
            $scholarship,
            $application,
            'mobile',
            [
                'eligibility_score' => $application->eligibility_score,
                'dss_score' => $application->dss_score,
            ],
            "application_submitted:{$application->id}",
        );

        ActivityLog::record(
            $user,
            'mobile_application_submitted',
            "{$user->name} submitted an application for {$scholarship->title} from mobile.",
            $request,
            ['application_id' => $application->id, 'scholarship_id' => $scholarship->id],
        );

        PortalNotification::create([
            'user_id' => $scholarship->provider_id,
            'type' => 'application',
            'title' => 'New scholarship application',
            'message' => "{$user->name} submitted an application for {$scholarship->title}.",
            'action_url' => '/provider/applications',
        ]);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application' => $this->applicationPayload($application->fresh()->load(['documents', 'schedules', 'statusHistories.actor', 'scholarship.provider.providerProfile'])),
            'stats' => $this->statsPayload($user),
        ], 201);
    }

    public function saveScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (! $scholarship->isAcceptingApplications()) {
            return response()->json([
                'message' => 'Scholarship not found.',
            ], 404);
        }

        $bookmark = ScholarshipBookmark::query()->firstOrCreate([
            'scholarship_id' => $scholarship->id,
            'user_id' => $user->id,
        ]);

        if ($bookmark->wasRecentlyCreated) {
            ScholarshipFunnelEvent::record(
                $user,
                'scholarship_saved',
                $scholarship,
                source: 'mobile',
            );
        }

        return response()->json([
            'message' => 'Scholarship saved.',
            'scholarship' => $this->scholarshipPayload($scholarship->fresh()->load('provider.providerProfile'), $user),
            'stats' => $this->statsPayload($user),
        ]);
    }

    public function unsaveScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $deleted = ScholarshipBookmark::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted > 0) {
            ScholarshipFunnelEvent::record(
                $user,
                'scholarship_unsaved',
                $scholarship,
                source: 'mobile',
            );
        }

        return response()->json([
            'message' => 'Scholarship removed from saved list.',
            'scholarship' => $this->scholarshipPayload($scholarship->fresh()->load('provider.providerProfile'), $user),
            'stats' => $this->statsPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        ActivityLog::record(
            $user,
            'mobile_logout',
            "{$user->name} logged out from the mobile app.",
            $request,
        );

        $token->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function markNotificationRead(Request $request, PortalNotification $notification): JsonResponse
    {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($notification->user_id !== $user->id) {
            return response()->json([
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->markRead();
        $token->forceFill(['last_used_at' => now()])->save();

        return response()->json([
            'message' => 'Notification marked as read.',
            'notifications' => $this->notificationsPayload($user),
        ]);
    }

    public function acknowledgeApplicationSchedule(
        Request $request,
        ScholarshipApplication $application,
        ApplicationSchedule $schedule,
    ): JsonResponse {
        [$token, $user] = $this->resolveToken($request);

        if (! $token || ! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($application->applicant_id !== $user->id
            || $schedule->scholarship_application_id !== $application->id) {
            return response()->json(['message' => 'Schedule not found.'], 404);
        }

        if ($schedule->status !== 'scheduled') {
            return response()->json([
                'message' => 'Only an active schedule can be acknowledged.',
            ], 422);
        }

        if ($schedule->applicant_acknowledged_at === null) {
            $schedule->update(['applicant_acknowledged_at' => now()]);

            ActivityLog::record(
                $user,
                'application_schedule_acknowledged',
                "{$user->name} acknowledged the {$schedule->type} schedule for application #{$application->id} from the mobile app.",
                $request,
                ['application_id' => $application->id, 'schedule_id' => $schedule->id, 'source' => 'mobile'],
            );

            if ($application->scholarship?->provider_id) {
                PortalNotification::create([
                    'user_id' => $application->scholarship->provider_id,
                    'type' => 'schedule_acknowledged',
                    'title' => ucfirst($schedule->type).' schedule acknowledged',
                    'message' => "{$user->name} confirmed through the mobile app that they saw the {$schedule->type} schedule for {$application->scholarship->title}.",
                    'action_url' => route('provider.applications.show', $application, false),
                ]);
            }
        }

        $token->forceFill(['last_used_at' => now()])->save();
        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'documents',
            'schedules',
            'statusHistories.actor',
            'scholarship.provider.providerProfile',
        ]);

        return response()->json([
            'message' => 'Schedule acknowledged. The provider can now see your confirmation.',
            'schedule' => ApplicationSchedulePayload::make($schedule->fresh()),
            'application' => $this->applicationPayload($freshApplication),
        ]);
    }

    private function createToken(User $user): string
    {
        $plainToken = Str::random(80);

        MobileApiToken::create([
            'user_id' => $user->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(config('auth.mobile_token_lifetime_days', 30)),
        ]);

        return $plainToken;
    }

    private function resolveToken(Request $request): array
    {
        $plainToken = $request->bearerToken();

        if (! $plainToken) {
            return [null, null];
        }

        $token = MobileApiToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return [null, null];
        }

        if ($token->user?->role !== 'applicant' || $token->user?->isSuspended() || $token->user?->must_reset_password) {
            return [null, null];
        }

        return [$token, $token->user];
    }

    private function userPayload(User $user): array
    {
        return $user->loadMissing(['studentProfile', 'providerProfile', 'adminProfile'])->publicPayload();
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

    private function statsPayload(User $user): array
    {
        return [
            'available_scholarships' => Scholarship::query()->acceptingApplications()->count(),
            'applications' => ScholarshipApplication::query()->where('applicant_id', $user->id)->count(),
            'saved' => ScholarshipBookmark::query()->where('user_id', $user->id)->count(),
        ];
    }

    private function notificationsPayload(User $user): array
    {
        return PortalNotification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (PortalNotification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'action_url' => $notification->action_url,
                'read_at' => $notification->read_at?->format('M d, Y h:i A'),
                'created_at' => $notification->created_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();
    }

    private function scholarshipPayload(Scholarship $scholarship, User $user): array
    {
        $match = $this->eligibilityMatch($scholarship, $user);
        $preparedDocuments = $this->preparedDocumentReadiness($scholarship, $user);
        $distanceKm = $this->distanceKm($user->studentProfile, $scholarship);
        $hasApplied = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('applicant_id', $user->id)
            ->exists();
        $profileComplete = (bool) $user->applicantProfileReadiness()['complete'];
        $eligibilityBlockers = $this->applicationEligibilityBlockers($match);

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
            'is_saved' => ScholarshipBookmark::query()
                ->where('scholarship_id', $scholarship->id)
                ->where('user_id', $user->id)
                ->exists(),
            'has_applied' => $hasApplied,
            'is_accepting_applications' => $scholarship->isAcceptingApplications(),
            'can_start_application' => $scholarship->isAcceptingApplications()
                && $profileComplete
                && $eligibilityBlockers === []
                && ! $hasApplied,
            'eligibility_match' => $match,
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
        $dss = app(DecisionSupportService::class)->scoreApplication($application);
        $application->loadMissing('schedules');

        return [
            'id' => $application->id,
            'status' => $application->status,
            'document_checklist' => $application->document_checklist ?? [],
            'document_readiness' => $this->documentReadiness($application),
            'eligibility_score' => $application->eligibility_score,
            'eligibility_breakdown' => $application->eligibility_breakdown,
            'dss_score' => $dss['score'],
            'dss_recommendation' => $dss['recommendation'],
            'dss_breakdown' => $dss,
            'documents' => $application->documents->map(fn (ApplicationDocument $document) => [
                'id' => $document->id,
                'document_name' => $document->document_name,
                'original_name' => $document->original_name,
                'size' => $document->size,
                'status' => $document->status,
                'review_notes' => $document->review_notes,
                'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            ])->values(),
            'notes' => $application->notes,
            'review_notes' => $application->review_notes,
            'decision_reason' => $application->decision_reason,
            'awarded_amount' => $application->awarded_amount,
            'outcome_notes' => $application->outcome_notes,
            'outcome_at' => $application->outcome_at?->format('M d, Y'),
            'distribution_scheduled_for' => $application->distribution_scheduled_for?->format('M d, Y'),
            'distribution_instructions' => $application->distribution_instructions,
            'schedules' => $application->schedules
                ->sortBy('scheduled_at')
                ->map(fn (ApplicationSchedule $schedule) => ApplicationSchedulePayload::make($schedule))
                ->values(),
            'timeline' => $this->timelinePayload($application),
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            'scholarship' => $application->scholarship
                ? $this->scholarshipPayload($application->scholarship, $application->applicant)
                : null,
        ];
    }

    private function documentReadiness(ScholarshipApplication $application): array
    {
        return $this->eligibilityService->applicationDocumentReadiness($application);
    }

    private function timelinePayload(ScholarshipApplication $application): array
    {
        if ($application->statusHistories->isEmpty()) {
            return [[
                'id' => "submitted-{$application->id}",
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
                'to_status' => $history->to_status,
                'decision_reason' => $history->decision_reason,
                'review_notes' => $history->review_notes,
                'actor' => $history->actor?->name ?? 'System',
                'changed_at' => $history->changed_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();
    }

    private function eligibilityMatch(Scholarship $scholarship, User $user): array
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

    private function documentRequirements(?Scholarship $scholarship): array
    {
        return $this->eligibilityService->documentRequirements($scholarship);
    }

    private function preparedDocumentReadiness(Scholarship $scholarship, User $user): array
    {
        return $this->eligibilityService->preparedDocumentReadiness($scholarship, $user);
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
                'message' => "Your profile is {$profileReadiness['percent']}% complete. You can explore now, but complete it before submitting applications.",
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
            ->acceptingApplications()
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', now()->toDateString())
            ->whereDate('deadline', '<=', now()->addDays(7)->toDateString())
            ->whereHas('bookmarks', fn ($query) => $query->where('user_id', $user->id))
            ->whereDoesntHave('applications', fn ($query) => $query->where('applicant_id', $user->id))
            ->get()
            ->each(fn (Scholarship $scholarship) => PortalNotification::updateOrCreate([
                'user_id' => $user->id,
                'type' => 'deadline_reminder',
                'title' => "Deadline near: {$scholarship->title}",
            ], [
                'message' => "This saved scholarship is due on {$scholarship->deadline?->format('M d, Y')}.",
                'action_url' => "/dashboard/scholarships/{$scholarship->id}",
            ]));
    }

    private function documentLibraryOptions(User $user): array
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
            ->flatMap(fn (?string $requirements) => $this->splitOptions($requirements));
        $applicationRequirements = ScholarshipApplication::query()
            ->with('scholarship')
            ->where('applicant_id', $user->id)
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

    private function studentDocumentPayload(StudentDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_name' => $document->document_name,
            'original_name' => $document->original_name,
            'size' => $document->size,
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
        ];
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

        $preparedDocuments = StudentDocument::query()
            ->where('user_id', $user->id)
            ->whereIn('document_name', $requirements)
            ->get();

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
            ]);
        }
    }

}
