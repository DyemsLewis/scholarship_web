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

    public function applications(Request $request): View|RedirectResponse
    {
        if (! $request->user()) {
            return redirect()->route('login');
        }

        abort_unless($request->user()->isProvider(), 403);

        return view('provider-applications');
    }

    public function profile(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
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
        ]);
    }

    public function applicationsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = Scholarship::query()
            ->where('provider_id', $request->user()->id)
            ->latest()
            ->get();
        $applications = ScholarshipApplication::query()
            ->with(['applicant.studentProfile', 'documents', 'scholarship'])
            ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $request->user()->id))
            ->latest('submitted_at')
            ->get();
        $statusCounts = $applications
            ->groupBy('status')
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

        $validated = $request->validate([
            'status' => ['required', Rule::in(['submitted', 'under_review', 'qualified', 'approved', 'rejected'])],
            'review_notes' => ['nullable', 'string', 'max:1500'],
        ]);

        $application->update([
            'status' => $validated['status'],
            'review_notes' => $validated['review_notes'] ?? $application->review_notes,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        ActivityLog::record(
            $request->user(),
            'application_status_updated',
            "{$request->user()->name} updated application #{$application->id} to {$validated['status']}.",
            $request,
            ['application_id' => $application->id, 'status' => $validated['status']],
        );

        PortalNotification::create([
            'user_id' => $application->applicant_id,
            'type' => 'application_status',
            'title' => 'Application status updated',
            'message' => "Your application for {$application->scholarship?->title} is now {$this->statusLabel($validated['status'])}.",
            'action_url' => '/dashboard/applications',
        ]);

        return response()->json([
            'message' => 'Application status updated.',
            'application' => $this->applicationPayload($application->fresh()->load(['applicant.studentProfile', 'documents', 'scholarship'])),
        ]);
    }

    public function exportApplications(Request $request)
    {
        abort_unless($request->user()?->isProvider(), 403);

        $provider = $request->user();

        return response()->streamDownload(function () use ($provider) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Scholarship', 'Applicant', 'Email', 'Contact Number', 'Status', 'Readiness %', 'Submitted At', 'Documents Confirmed', 'Uploaded Documents', 'Applicant Notes', 'Review Notes']);

            ScholarshipApplication::query()
                ->with(['applicant.studentProfile', 'documents', 'scholarship'])
                ->whereHas('scholarship', fn ($query) => $query->where('provider_id', $provider->id))
                ->orderBy('id')
                ->chunk(200, function ($applications) use ($handle) {
                    foreach ($applications as $application) {
                        $readiness = $this->documentReadiness($application);

                        fputcsv($handle, [
                            $application->id,
                            $application->scholarship?->title,
                            $application->applicant?->name,
                            $application->applicant?->email,
                            $application->applicant?->contact_number,
                            $application->status,
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
            ->latest()
            ->get();

        return response()->json([
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
        ]);
    }

    public function storeScholarship(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);

        $scholarship = Scholarship::create([
            ...$validated,
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
            'description' => ['required', 'string', 'max:5000'],
            'eligibility' => ['nullable', 'string', 'max:5000'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'minimum_gwa' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'published', 'closed'])],
        ]);
    }

    private function applicationPayload(ScholarshipApplication $application): array
    {
        $readiness = $this->documentReadiness($application);

        return [
            'id' => $application->id,
            'status' => $application->status,
            'document_checklist' => $application->document_checklist ?? [],
            'document_readiness' => $readiness,
            'documents' => $application->documents->map(fn (ApplicationDocument $document) => $this->documentPayload($document))->values(),
            'notes' => $application->notes,
            'review_notes' => $application->review_notes,
            'reviewed_at' => $application->reviewed_at?->format('M d, Y h:i A'),
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
            'applicant' => [
                'name' => $application->applicant?->name,
                'email' => $application->applicant?->email,
                'username' => $application->applicant?->username,
                'contact_number' => $application->applicant?->contact_number,
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
            'deadline' => $scholarship->deadline?->format('Y-m-d'),
            'status' => $scholarship->status,
            'created_at' => $scholarship->created_at?->format('M d, Y'),
            'updated_at' => $scholarship->updated_at?->format('M d, Y'),
        ];
    }

    private function ensureProviderCanPost(Request $request): void
    {
        if ($request->user()->providerProfile?->isVerified()) {
            return;
        }

        abort(403, 'Your provider account must be approved by an admin before posting scholarships.');
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

    private function statusLabel(string $status): string
    {
        return str($status)->replace('_', ' ')->title()->toString();
    }
}
