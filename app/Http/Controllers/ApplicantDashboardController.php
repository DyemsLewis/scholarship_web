<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ApplicationDocument;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function applications(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->ensureApplicant($request)) {
            return $redirect;
        }

        return view('dashboard-applications');
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

        return response()->json([
            'user' => $this->userPayload($request),
            'stats' => $this->statsPayload($request),
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
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
            ->with(['documents', 'scholarship.provider.providerProfile'])
            ->where('applicant_id', $request->user()->id)
            ->latest('submitted_at')
            ->get();

        return response()->json([
            'user' => $this->userPayload($request),
            'stats' => $this->statsPayload($request),
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'applications' => $applications->map(fn (ScholarshipApplication $application) => $this->applicationPayload($application))->values(),
            'notifications' => $this->notificationsPayload($request),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_initial' => ['required', 'string', 'size:1', 'regex:/^[A-Za-z]$/'],
            'contact_number' => ['required', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
            'school' => ['nullable', 'string', 'max:255'],
            'course_or_strand' => ['nullable', 'string', 'max:255'],
            'year_level' => ['nullable', 'string', 'max:100'],
            'gwa' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'birthdate' => ['nullable', 'date', 'before:today'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_contact' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s().-]{10,30}$/'],
        ]);

        $request->user()->studentProfile()->updateOrCreate([
            'user_id' => $request->user()->id,
        ], [
            ...$validated,
            'middle_initial' => strtoupper($validated['middle_initial']),
        ]);

        ActivityLog::record(
            $request->user(),
            'profile_updated',
            "{$request->user()->name} updated their applicant profile.",
            $request,
        );

        return response()->json([
            'message' => 'Applicant profile updated.',
            'user' => $this->userPayload($request),
        ]);
    }

    public function storeApplication(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isApplicant(), 403);

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

        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $request->user()->id,
            'status' => 'submitted',
            'document_checklist' => $validated['document_checklist'] ?? [],
            'notes' => $validated['notes'] ?? null,
            'submitted_at' => now(),
        ]);

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

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application' => $this->applicationPayload($application->load(['documents', 'scholarship.provider.providerProfile'])),
        ], 201);
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
            'uploaded_at' => now(),
        ]);

        ActivityLog::record(
            $request->user(),
            'document_uploaded',
            "{$request->user()->name} uploaded {$document->document_name} for application #{$application->id}.",
            $request,
            ['application_id' => $application->id, 'document_id' => $document->id],
        );

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'application' => $this->applicationPayload($application->fresh()->load(['documents', 'scholarship.provider.providerProfile'])),
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

        return response()->json([
            'message' => 'Document removed.',
            'application' => $this->applicationPayload($application->fresh()->load(['documents', 'scholarship.provider.providerProfile'])),
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
            'saved' => 0,
        ];
    }

    private function scholarshipPayload(Scholarship $scholarship): array
    {
        return [
            'id' => $scholarship->id,
            'title' => $scholarship->title,
            'description' => $scholarship->description,
            'eligibility' => $scholarship->eligibility,
            'requirements' => $scholarship->requirements,
            'award_amount' => $scholarship->award_amount,
            'minimum_gwa' => $scholarship->minimum_gwa,
            'deadline' => $scholarship->deadline?->format('M d, Y'),
            'eligibility_guide' => [
                'requires_gwa' => filled($scholarship->minimum_gwa),
                'minimum_gwa' => $scholarship->minimum_gwa,
                'required_documents' => count($this->documentRequirements($scholarship)),
                'note' => filled($scholarship->minimum_gwa)
                    ? "Check that your GWA or average meets {$scholarship->minimum_gwa} before applying."
                    : 'No minimum GWA or average is listed for this scholarship.',
            ],
            'provider' => [
                'name' => $scholarship->provider?->provider_name ?? $scholarship->provider?->name,
                'type' => $scholarship->provider?->provider_type,
            ],
        ];
    }

    private function applicationPayload(ScholarshipApplication $application): array
    {
        return [
            'id' => $application->id,
            'status' => $application->status,
            'document_checklist' => $application->document_checklist ?? [],
            'document_readiness' => $this->documentReadiness($application),
            'documents' => $application->documents->map(fn (ApplicationDocument $document) => $this->documentPayload($document))->values(),
            'notes' => $application->notes,
            'review_notes' => $application->review_notes,
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
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

        return [
            'required' => $requiredCount,
            'confirmed' => $confirmedRequiredCount,
            'percent' => $requiredCount === 0 ? 100 : (int) round(($confirmedRequiredCount / $requiredCount) * 100),
            'uploaded' => $uploadedRequiredCount,
            'uploaded_percent' => $requiredCount === 0 ? 100 : (int) round(($uploadedRequiredCount / $requiredCount) * 100),
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

    private function documentPayload(ApplicationDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_name' => $document->document_name,
            'original_name' => $document->original_name,
            'size' => $document->size,
            'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
            'download_url' => route('documents.download', $document),
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
                'read_at' => $notification->read_at?->format('M d, Y h:i A'),
                'created_at' => $notification->created_at?->format('M d, Y h:i A'),
            ])
            ->values()
            ->all();
    }
}
