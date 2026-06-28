<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBookmark;
use App\Models\StudentDocument;
use App\Models\User;
use App\Services\DecisionSupportService;
use App\Support\AcademicRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ApplicantDashboardController extends Controller
{
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

        abort_unless($scholarship->status === 'published', 404);

        return view('dashboard-scholarship-detail', [
            'scholarship' => $scholarship,
        ]);
    }

    public function scholarshipDetailData(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);
        abort_unless($scholarship->status === 'published', 404);

        $scholarship->load('provider.providerProfile')->loadCount('bookmarks');

        return response()->json([
            'user' => $this->userPayload($request),
            'profile_readiness' => $request->user()->applicantProfileReadiness(),
            'stats' => $this->statsPayload($request),
            'scholarship' => $this->scholarshipPayload($scholarship, $request->user()),
        ]);
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

    public function profile(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        return view('dashboard-profile');
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
            'notifications' => $this->notificationsPayload($request),
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
            'notifications' => $this->notificationsPayload($request),
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
        ]);

        ActivityLog::record(
            $request->user(),
            'prepared_document_uploaded',
            "{$request->user()->name} uploaded prepared document {$document->document_name}.",
            $request,
            ['student_document_id' => $document->id],
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

    public function updateProfile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

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
            'gwa' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'grading_scale' => ['nullable', Rule::in(['percentage', 'grade_point'])],
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
            ->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));

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
        ]);
    }

    public function storeApplication(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

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

        $scholarship = Scholarship::query()->findOrFail($validated['scholarship_id']);
        $eligibilityMatch = $this->eligibilityMatch($scholarship, $request->user());

        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $request->user()->id,
            'status' => 'submitted',
            'document_checklist' => $validated['document_checklist'] ?? [],
            'eligibility_score' => $eligibilityMatch['score'],
            'eligibility_breakdown' => $eligibilityMatch,
            'notes' => $validated['notes'] ?? null,
            'submitted_at' => now(),
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
        app(DecisionSupportService::class)->syncApplication($application);

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

        ScholarshipBookmark::query()->firstOrCreate([
            'scholarship_id' => $scholarship->id,
            'user_id' => $request->user()->id,
        ]);

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

        ScholarshipBookmark::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('user_id', $request->user()->id)
            ->delete();

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
        ]);

        ActivityLog::record(
            $request->user(),
            'document_uploaded',
            "{$request->user()->name} uploaded {$document->document_name} for application #{$application->id}.",
            $request,
            ['application_id' => $application->id, 'document_id' => $document->id],
        );

        $freshApplication = $application->fresh()->load(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile']);
        app(DecisionSupportService::class)->syncApplication($freshApplication);

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

        $freshApplication = $application->fresh()->load(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile']);
        app(DecisionSupportService::class)->syncApplication($freshApplication);

        return response()->json([
            'message' => 'Document removed.',
            'application' => $this->applicationPayload($freshApplication),
        ]);
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
            ->where('status', 'published')
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
            'available_scholarships' => Scholarship::query()->where('status', 'published')->count(),
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
            'contact_email' => $scholarship->contact_email,
            'contact_number' => $scholarship->contact_number,
            'deadline' => $scholarship->deadline?->format('M d, Y'),
            'bookmarks_count' => $scholarship->bookmarks_count ?? $scholarship->bookmarks()->count(),
            'is_saved' => $saved,
            'has_applied' => $hasApplied,
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
        $decisionSupport = app(DecisionSupportService::class);
        $dss = $decisionSupport->scoreApplication($application);

        return [
            'id' => $application->id,
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
        return $this->splitDocumentRequirements($scholarship?->requirements);
    }

    private function splitDocumentRequirements(?string $requirements): array
    {
        if (! $requirements) {
            return [];
        }

        $requirements = collect(preg_split('/\r\n|\r|\n|,/', $requirements))
            ->map(fn (string $requirement) => trim($requirement))
            ->filter()
            ->values()
            ->all();

        return $this->hasOpenOption($requirements) ? [] : $requirements;
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
            ->where('status', 'published')
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
        $requiredDocuments = $this->documentRequirements($scholarship);

        if (! $user) {
            return [
                'required' => count($requiredDocuments),
                'uploaded' => 0,
                'percent' => $requiredDocuments === [] ? 100 : 0,
                'required_documents' => $requiredDocuments,
                'matched' => [],
                'missing' => $requiredDocuments,
            ];
        }

        $user->loadMissing('studentDocuments');
        $preparedNames = $user->studentDocuments
            ->map(fn (StudentDocument $document) => $document->document_name)
            ->values();
        $matched = collect($requiredDocuments)
            ->filter(fn (string $requirement) => $preparedNames->contains($requirement))
            ->values()
            ->all();
        $missing = collect($requiredDocuments)
            ->reject(fn (string $requirement) => in_array($requirement, $matched, true))
            ->values()
            ->all();
        $requiredCount = count($requiredDocuments);
        $uploadedCount = count($matched);

        return [
            'required' => $requiredCount,
            'uploaded' => $uploadedCount,
            'percent' => $requiredCount === 0 ? 100 : (int) round(($uploadedCount / $requiredCount) * 100),
            'required_documents' => $requiredDocuments,
            'matched' => $matched,
            'missing' => $missing,
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

    private function eligibilityMatch(Scholarship $scholarship, ?User $user): array
    {
        $profile = $user?->studentProfile;
        $criteria = [];
        $passed = 0;
        $applicable = 0;

        $addCriterion = function (string $key, string $label, string $status, ?string $studentValue, ?string $requirement, string $note, bool $counts = true) use (&$criteria, &$passed, &$applicable): void {
            $criteria[] = [
                'key' => $key,
                'label' => $label,
                'status' => $status,
                'student_value' => $studentValue,
                'requirement' => $requirement,
                'note' => $note,
            ];

            if (! $counts) {
                return;
            }

            $applicable++;

            if ($status === 'pass') {
                $passed++;
            }
        };

        $academicMatch = AcademicRequirement::match($profile?->gwa, $profile?->grading_scale, $scholarship->minimum_gwa, $scholarship->minimum_grade_scale);
        $addCriterion(
            'academic',
            $academicMatch['label'],
            $academicMatch['status'],
            $academicMatch['student_value'],
            $academicMatch['requirement'],
            $academicMatch['note'],
            $academicMatch['counts'],
        );

        $educationLevelOptions = $this->splitOptions($scholarship->eligible_education_levels);
        if ($this->hasOpenOption($educationLevelOptions)) {
            $addCriterion('education_level', 'Education level', 'info', $profile?->education_level, implode(', ', $educationLevelOptions), 'This program is open to all education levels.', false);
        } elseif ($educationLevelOptions !== []) {
            $studentEducationLevel = $profile?->education_level;
            $matchesEducationLevel = filled($studentEducationLevel) && $this->matchesAnyOption($studentEducationLevel, $educationLevelOptions);
            $addCriterion(
                'education_level',
                'Education level',
                filled($studentEducationLevel) ? ($matchesEducationLevel ? 'pass' : 'fail') : 'missing',
                $studentEducationLevel,
                implode(', ', $educationLevelOptions),
                $matchesEducationLevel ? 'Your education level matches this program.' : 'Confirm if your education level is eligible.',
            );
        } else {
            $addCriterion('education_level', 'Education level', 'info', $profile?->education_level, null, 'No education level restriction listed.', false);
        }

        $courseOptions = $this->splitOptions($scholarship->eligible_courses);
        if ($this->hasOpenOption($courseOptions)) {
            $addCriterion('course', 'Track / strand / course', 'info', $profile?->course_or_strand, implode(', ', $courseOptions), 'This program accepts any track, strand, or course.', false);
        } elseif ($courseOptions !== []) {
            $studentCourse = $profile?->course_or_strand;
            $matchesCourse = filled($studentCourse) && $this->matchesAnyOption($studentCourse, $courseOptions);
            $addCriterion(
                'course',
                'Track / strand / course',
                filled($studentCourse) ? ($matchesCourse ? 'pass' : 'fail') : 'missing',
                $studentCourse,
                implode(', ', $courseOptions),
                $matchesCourse ? 'Your track, strand, or course matches the listed target.' : 'Check if your grade-level track, strand, or course is accepted by the provider.',
            );
        } else {
            $addCriterion('course', 'Track / strand / course', 'info', $profile?->course_or_strand, null, 'No track, strand, or course restriction listed.', false);
        }

        $schoolTypeOptions = $this->splitOptions($scholarship->eligible_school_types);
        if ($this->hasOpenOption($schoolTypeOptions)) {
            $addCriterion('school_type', 'School type', 'info', $profile?->school_type, implode(', ', $schoolTypeOptions), 'This program accepts any school type.', false);
        } elseif ($schoolTypeOptions !== []) {
            $studentSchoolType = $profile?->school_type;
            $matchesSchoolType = filled($studentSchoolType) && $this->matchesAnyOption($studentSchoolType, $schoolTypeOptions);
            $addCriterion(
                'school_type',
                'School type',
                filled($studentSchoolType) ? ($matchesSchoolType ? 'pass' : 'fail') : 'missing',
                $studentSchoolType,
                implode(', ', $schoolTypeOptions),
                $matchesSchoolType ? 'Your school type matches this program.' : 'Confirm if your school type is eligible.',
            );
        } else {
            $addCriterion('school_type', 'School type', 'info', $profile?->school_type, null, 'No school type restriction listed.', false);
        }

        $yearOptions = $this->splitOptions($scholarship->eligible_year_levels);
        if ($this->hasOpenOption($yearOptions)) {
            $addCriterion('year_level', 'Grade / year level', 'info', $profile?->year_level, implode(', ', $yearOptions), 'This program accepts any grade or year level.', false);
        } elseif ($yearOptions !== []) {
            $studentYear = $profile?->year_level;
            $matchesYear = filled($studentYear) && $this->matchesAnyOption($studentYear, $yearOptions);
            $addCriterion(
                'year_level',
                'Grade / year level',
                filled($studentYear) ? ($matchesYear ? 'pass' : 'fail') : 'missing',
                $studentYear,
                implode(', ', $yearOptions),
                $matchesYear ? 'Your grade or year level matches this program.' : 'Confirm if your grade or year level is eligible.',
            );
        } else {
            $addCriterion('year_level', 'Grade / year level', 'info', $profile?->year_level, null, 'No grade or year level restriction listed.', false);
        }

        $locationOptions = $this->splitOptions($scholarship->eligible_locations);
        if ($this->hasOpenOption($locationOptions)) {
            $addCriterion('location', 'Location', 'info', $profile?->region ?? $profile?->province ?? $profile?->city, implode(', ', $locationOptions), 'This program is open to all listed locations.', false);
        } elseif ($locationOptions !== []) {
            $studentLocation = collect([$profile?->barangay, $profile?->city, $profile?->province, $profile?->region, $profile?->address])->filter()->implode(', ');
            $matchesLocation = filled($studentLocation) && $this->matchesAnyOption($studentLocation, $locationOptions);
            $addCriterion(
                'location',
                'Location',
                filled($studentLocation) ? ($matchesLocation ? 'pass' : 'fail') : 'missing',
                $studentLocation ?: null,
                implode(', ', $locationOptions),
                $matchesLocation ? 'Your location matches the scholarship coverage.' : 'Your location may be outside the listed coverage.',
            );
        } else {
            $addCriterion('location', 'Location', 'info', $profile?->region ?? $profile?->province ?? $profile?->city, null, 'No location restriction listed.', false);
        }

        if (filled($scholarship->income_requirement) && ! $this->isOpenOption($scholarship->income_requirement)) {
            $income = $profile?->income_bracket;
            $matchesIncome = filled($income) && $this->matchesAnyOption($income, [$scholarship->income_requirement]);
            $addCriterion(
                'income',
                'Income bracket',
                filled($income) ? ($matchesIncome ? 'pass' : 'fail') : 'missing',
                $income,
                $scholarship->income_requirement,
                $matchesIncome ? 'Your income bracket matches the listed preference.' : 'Review the income requirement before applying.',
            );
        } else {
            $addCriterion('income', 'Income bracket', 'info', $profile?->income_bracket, $scholarship->income_requirement, 'No income restriction listed.', false);
        }

        $documentReadiness = $this->preparedDocumentReadiness($scholarship, $user);
        if ($documentReadiness['required'] > 0) {
            $documentsReady = $documentReadiness['uploaded'] >= $documentReadiness['required'];
            $addCriterion(
                'documents',
                'Prepared documents',
                $documentsReady ? 'pass' : 'missing',
                "{$documentReadiness['uploaded']} of {$documentReadiness['required']} uploaded",
                implode(', ', $documentReadiness['required_documents']),
                $documentsReady
                    ? 'Your document library already covers this program requirement.'
                    : 'Upload matching documents in Documents to improve readiness before applying.',
            );
        } else {
            $addCriterion('documents', 'Prepared documents', 'info', null, null, 'No document requirements listed.', false);
        }

        $score = $applicable === 0 ? 100 : (int) round(($passed / $applicable) * 100);

        return [
            'score' => $score,
            'passed' => $passed,
            'applicable' => $applicable,
            'label' => $score >= 80 ? 'Strong match' : ($score >= 50 ? 'Needs review' : 'Low match'),
            'summary' => $applicable === 0
                ? 'This scholarship has no structured matching rules yet.'
                : "{$passed} of {$applicable} structured criteria match your profile.",
            'criteria' => $criteria,
        ];
    }

    private function splitOptions(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n|,/', $value))
            ->map(fn (string $option) => trim($option))
            ->filter()
            ->values()
            ->all();
    }

    private function matchesAnyOption(string $value, array $options): bool
    {
        $normalizedValue = str($value)->lower()->squish()->toString();

        if ($this->hasOpenOption($options)) {
            return true;
        }

        return collect($options)->contains(function (string $option) use ($normalizedValue) {
            $normalizedOption = str($option)->lower()->squish()->toString();

            return str_contains($normalizedValue, $normalizedOption) || str_contains($normalizedOption, $normalizedValue);
        });
    }

    private function hasOpenOption(array $options): bool
    {
        return collect($options)->contains(fn (string $option) => $this->isOpenOption($option));
    }

    private function isOpenOption(?string $option): bool
    {
        if (! filled($option)) {
            return false;
        }

        $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim(str_replace(['.', ';', ':'], '', $option))));

        return in_array($normalized, [
            'any',
            'all',
            'none',
            'n/a',
            'na',
            'not applicable',
            'no preference',
            'no restriction',
            'no restrictions',
            'open to all',
            'all students',
            'any student',
            'all applicants',
            'any applicant',
            'all education levels',
            'any education level',
            'all levels',
            'any level',
            'all courses',
            'any course',
            'all strands',
            'any strand',
            'all tracks',
            'any track',
            'all grades',
            'any grade',
            'all years',
            'any year',
            'all school types',
            'any school type',
            'all locations',
            'any location',
            'all regions',
            'any region',
            'nationwide',
            'no income requirement',
        ], true)
            || str_starts_with($normalized, 'any ')
            || str_starts_with($normalized, 'all ')
            || str_contains($normalized, 'n/a')
            || str_contains($normalized, 'open to all')
            || str_contains($normalized, 'no restriction')
            || str_contains($normalized, 'no preference')
            || str_contains($normalized, 'not applicable');
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
                'read_at' => $notification->read_at?->format('M d, Y h:i A'),
                'created_at' => $notification->created_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();
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
