<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\MobileApiToken;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBookmark;
use App\Models\User;
use App\Services\DecisionSupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MobileAuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
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
            ->with(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile'])
            ->where('applicant_id', $user->id)
            ->latest('submitted_at')
            ->get();
        $applications->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));

        return response()->json([
            'user' => $this->userPayload($user),
            'stats' => $this->statsPayload($user),
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship, $user))->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
            'next_steps' => [
                'Complete your applicant profile for better match scores.',
                'Save scholarship programs you want to revisit.',
                'Use the application wizard on web or mobile to submit requirements.',
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

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'school' => ['nullable', 'string', 'max:255'],
            'course_or_strand' => ['nullable', 'string', 'max:255'],
            'year_level' => ['nullable', 'string', 'max:100'],
            'enrollment_status' => ['nullable', 'string', 'max:100'],
            'gwa' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'grading_scale' => ['nullable', Rule::in(['percentage', 'grade_point'])],
            'income_bracket' => ['nullable', 'string', 'max:100'],
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

        $user->studentProfile()->updateOrCreate([
            'user_id' => $user->id,
        ], [
            ...$validated,
            'middle_initial' => strtoupper($validated['middle_initial']),
        ]);

        ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents', 'scholarship'])
            ->where('applicant_id', $user->id)
            ->get()
            ->each(fn (ScholarshipApplication $application) => app(DecisionSupportService::class)->syncApplication($application));

        ActivityLog::record(
            $user,
            'mobile_profile_updated',
            "{$user->name} updated their profile from the mobile app.",
            $request,
        );

        return response()->json([
            'message' => 'Applicant profile updated.',
            'user' => $this->userPayload($user->fresh()),
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
        $eligibilityMatch = $this->eligibilityMatch($scholarship, $user);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $user->id,
            'status' => 'submitted',
            'document_checklist' => $validated['document_checklist'] ?? [],
            'eligibility_score' => $eligibilityMatch['score'],
            'eligibility_breakdown' => $eligibilityMatch,
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

        app(DecisionSupportService::class)->syncApplication($application);

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
            'application' => $this->applicationPayload($application->fresh()->load(['documents', 'statusHistories.actor', 'scholarship.provider.providerProfile'])),
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

        if ($scholarship->status !== 'published') {
            return response()->json([
                'message' => 'Scholarship not found.',
            ], 404);
        }

        ScholarshipBookmark::query()->firstOrCreate([
            'scholarship_id' => $scholarship->id,
            'user_id' => $user->id,
        ]);

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

        ScholarshipBookmark::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('user_id', $user->id)
            ->delete();

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

    private function createToken(User $user): string
    {
        $plainToken = Str::random(80);

        MobileApiToken::create([
            'user_id' => $user->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now(),
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

        if ($token->user?->role !== 'applicant') {
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
            ->where('status', 'published')
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->latest();
    }

    private function statsPayload(User $user): array
    {
        return [
            'available_scholarships' => Scholarship::query()->where('status', 'published')->count(),
            'applications' => ScholarshipApplication::query()->where('applicant_id', $user->id)->count(),
            'saved' => ScholarshipBookmark::query()->where('user_id', $user->id)->count(),
        ];
    }

    private function scholarshipPayload(Scholarship $scholarship, User $user): array
    {
        $distanceKm = $this->distanceKm($user->studentProfile, $scholarship);
        $hasApplied = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('applicant_id', $user->id)
            ->exists();

        return [
            'id' => $scholarship->id,
            'title' => $scholarship->title,
            'category' => $scholarship->category,
            'description' => $scholarship->description,
            'eligibility' => $scholarship->eligibility,
            'eligible_courses' => $scholarship->eligible_courses,
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
            'deadline' => $scholarship->deadline?->format('M d, Y'),
            'bookmarks_count' => $scholarship->bookmarks_count ?? $scholarship->bookmarks()->count(),
            'is_saved' => ScholarshipBookmark::query()
                ->where('scholarship_id', $scholarship->id)
                ->where('user_id', $user->id)
                ->exists(),
            'has_applied' => $hasApplied,
            'eligibility_match' => $this->eligibilityMatch($scholarship, $user),
            'provider' => [
                'name' => $scholarship->provider?->provider_name ?? $scholarship->provider?->name,
                'type' => $scholarship->provider?->provider_type,
            ],
        ];
    }

    private function mapUrl(Scholarship $scholarship): ?string
    {
        if ($scholarship->latitude !== null && $scholarship->longitude !== null) {
            return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode("{$scholarship->latitude},{$scholarship->longitude}");
        }

        $query = $scholarship->location_address ?: $scholarship->location_name;

        return filled($query)
            ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query)
            : null;
    }

    private function embedMapUrl(Scholarship $scholarship): ?string
    {
        if ($scholarship->latitude !== null && $scholarship->longitude !== null) {
            return 'https://maps.google.com/maps?q='.rawurlencode("{$scholarship->latitude},{$scholarship->longitude}").'&z=15&output=embed';
        }

        $query = $scholarship->location_address ?: $scholarship->location_name;

        return filled($query)
            ? 'https://maps.google.com/maps?q='.rawurlencode($query).'&z=15&output=embed'
            : null;
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
        $confirmedDocuments = collect($application->document_checklist ?? [])->map(fn (string $document) => trim($document))->filter();
        $uploadedDocuments = $application->documents->map(fn (ApplicationDocument $document) => $document->document_name);
        $acceptedDocuments = $application->documents
            ->filter(fn (ApplicationDocument $document) => $document->status === 'accepted')
            ->map(fn (ApplicationDocument $document) => $document->document_name);
        $requiredCount = count($requiredDocuments);

        return [
            'required' => $requiredCount,
            'confirmed' => $this->requirementCount($requiredDocuments, $confirmedDocuments->all()),
            'percent' => $requiredCount === 0 ? 100 : (int) round(($this->requirementCount($requiredDocuments, $confirmedDocuments->all()) / $requiredCount) * 100),
            'uploaded' => $this->requirementCount($requiredDocuments, $uploadedDocuments->all()),
            'uploaded_percent' => $requiredCount === 0 ? 100 : (int) round(($this->requirementCount($requiredDocuments, $uploadedDocuments->all()) / $requiredCount) * 100),
            'accepted' => $this->requirementCount($requiredDocuments, $acceptedDocuments->all()),
            'accepted_percent' => $requiredCount === 0 ? 100 : (int) round(($this->requirementCount($requiredDocuments, $acceptedDocuments->all()) / $requiredCount) * 100),
        ];
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
        $profile = $user->studentProfile;
        $criteria = [];
        $passed = 0;
        $applicable = 0;

        $addCriterion = function (string $key, string $label, string $status, ?string $studentValue, ?string $requirement, string $note, bool $counts = true) use (&$criteria, &$passed, &$applicable): void {
            $criteria[] = compact('key', 'label', 'status', 'studentValue', 'requirement', 'note');

            if (! $counts) {
                return;
            }

            $applicable++;

            if ($status === 'pass') {
                $passed++;
            }
        };

        if (filled($scholarship->minimum_gwa)) {
            $studentGwa = $profile?->gwa === null ? null : (float) $profile->gwa;
            $minimumGwa = (float) $scholarship->minimum_gwa;

            if ($studentGwa === null) {
                $addCriterion('gwa', 'GWA / average', 'missing', null, (string) $scholarship->minimum_gwa, 'Add your GWA in your profile.');
            } else {
                $usesGradePointScale = $profile?->grading_scale === 'grade_point'
                    || ($profile?->grading_scale !== 'percentage' && $studentGwa <= 5 && $minimumGwa <= 5);
                $isPassing = $usesGradePointScale ? $studentGwa <= $minimumGwa : $studentGwa >= $minimumGwa;
                $addCriterion('gwa', 'GWA / average', $isPassing ? 'pass' : 'fail', (string) $profile->gwa, (string) $scholarship->minimum_gwa, $isPassing ? 'Your average meets this requirement.' : 'Your average may need review.');
            }
        }

        $this->addOptionCriterion($addCriterion, 'course', 'Course / strand', $profile?->course_or_strand, $scholarship->eligible_courses);
        $this->addOptionCriterion($addCriterion, 'year_level', 'Year level', $profile?->year_level, $scholarship->eligible_year_levels);
        $studentLocation = collect([$profile?->barangay, $profile?->city, $profile?->province, $profile?->region, $profile?->address])->filter()->implode(', ');
        $this->addOptionCriterion($addCriterion, 'location', 'Location', $studentLocation ?: null, $scholarship->eligible_locations);

        if (filled($scholarship->income_requirement) && ! in_array(strtolower($scholarship->income_requirement), ['any', 'none', 'no preference'], true)) {
            $income = $profile?->income_bracket;
            $matchesIncome = filled($income) && $this->matchesAnyOption($income, [$scholarship->income_requirement]);
            $addCriterion('income', 'Income bracket', filled($income) ? ($matchesIncome ? 'pass' : 'fail') : 'missing', $income, $scholarship->income_requirement, $matchesIncome ? 'Income bracket matches.' : 'Review income requirement.');
        }

        $score = $applicable === 0 ? 100 : (int) round(($passed / $applicable) * 100);

        return [
            'score' => $score,
            'passed' => $passed,
            'applicable' => $applicable,
            'label' => $score >= 80 ? 'Strong match' : ($score >= 50 ? 'Needs review' : 'Low match'),
            'summary' => $applicable === 0 ? 'No structured matching rules yet.' : "{$passed} of {$applicable} structured criteria match your profile.",
            'criteria' => $criteria,
        ];
    }

    private function addOptionCriterion(callable $addCriterion, string $key, string $label, ?string $studentValue, ?string $requirements): void
    {
        $options = $this->splitOptions($requirements);

        if ($options === []) {
            return;
        }

        $matches = filled($studentValue) && $this->matchesAnyOption($studentValue, $options);
        $addCriterion($key, $label, filled($studentValue) ? ($matches ? 'pass' : 'fail') : 'missing', $studentValue, implode(', ', $options), $matches ? "{$label} matches." : "{$label} may need review.");
    }

    private function splitOptions(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n|,/', $value))->map(fn (string $option) => trim($option))->filter()->values()->all();
    }

    private function matchesAnyOption(string $value, array $options): bool
    {
        $normalizedValue = str($value)->lower()->squish()->toString();

        return collect($options)->contains(function (string $option) use ($normalizedValue) {
            $normalizedOption = str($option)->lower()->squish()->toString();

            return str_contains($normalizedValue, $normalizedOption) || str_contains($normalizedOption, $normalizedValue);
        });
    }

    private function documentRequirements(?Scholarship $scholarship): array
    {
        if (! $scholarship?->requirements) {
            return [];
        }

        return $this->splitOptions($scholarship->requirements);
    }

    private function requirementCount(array $requirements, array $documents): int
    {
        $documents = collect($documents)->map(fn (string $document) => trim($document))->filter();

        return collect($requirements)->filter(fn (string $requirement) => $documents->contains($requirement))->count();
    }
}
