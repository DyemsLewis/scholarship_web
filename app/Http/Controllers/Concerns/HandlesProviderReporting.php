<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ApplicationDocument;
use App\Models\ProviderVerificationDocument;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Services\DecisionSupportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait HandlesProviderReporting
{
    public function dashboardData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $provider = $request->user()
            ->loadMissing(['studentProfile', 'providerProfile', 'adminProfile']);
        $providerOwner = $provider->providerOrganizationOwner()->loadMissing('providerProfile');
        $providerId = $providerOwner->id;
        $verificationDocumentsCount = ProviderVerificationDocument::query()
            ->where('provider_id', $providerId)
            ->count();

        $canViewPrograms = $provider->hasPortalPermission('manage_programs')
            || $provider->hasAnyPortalPermission(self::PROVIDER_PROGRAM_WORKFLOW_PERMISSIONS);
        $scholarships = $canViewPrograms
            ? $this->providerScholarshipsQuery($provider)
                ->withCount($this->providerProgramCountRelations())
                ->latest()
                ->get()
            : collect();
        $canReviewApplications = $provider->hasAnyPortalPermission([
            'verify_applications',
            'manage_selection_activities',
            'record_final_decisions',
        ])
            && $providerOwner->hasVerifiedEmail()
            && $providerOwner->providerProfile?->isVerified();
        $applicationsBase = $this->providerApplicationsQuery($provider);
        $applicationWorkflowCounts = $canReviewApplications
            ? $this->providerApplicationFilterCounts($applicationsBase)
            : [
                'all' => 0,
                'needs_review' => 0,
                'waiting_activity' => 0,
                'ready_result' => 0,
                'final_decision' => 0,
            ];

        return response()->json([
            'user' => [
                ...$provider->publicPayload(),
                'verification_documents_count' => $verificationDocumentsCount,
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'application_workflow_counts' => [
                'needs_review' => $provider->hasPortalPermission('verify_applications')
                    ? (int) ($applicationWorkflowCounts['needs_review'] ?? 0)
                    : 0,
                'waiting_activity' => $provider->hasPortalPermission('manage_selection_activities')
                    ? (int) ($applicationWorkflowCounts['waiting_activity'] ?? 0)
                    : 0,
                'ready_result' => $provider->hasPortalPermission('manage_selection_activities')
                    ? (int) ($applicationWorkflowCounts['ready_result'] ?? 0)
                    : 0,
                'final_decision' => $provider->hasPortalPermission('record_final_decisions')
                    ? (int) ($applicationWorkflowCounts['final_decision'] ?? 0)
                    : 0,
                'all' => (int) ($applicationWorkflowCounts['all'] ?? 0),
            ],
        ]);
    }

    public function insightsData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $providerId = $request->user()->providerOrganizationId();
        $scholarships = $this->providerScholarshipsQuery($request->user())
            ->withCount($this->providerProgramCountRelations())
            ->latest()
            ->get();
        $applications = $this->providerApplicationsQuery($request->user())
            ->with(['applicant.studentProfile', 'documents.reviewer', 'scholarship'])
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
        $documentStatusPriority = [
            'pending' => 0,
            'needs_replacement' => 1,
            'rejected' => 2,
            'accepted' => 3,
        ];
        $documentReviewPackets = $applications
            ->filter(fn (ScholarshipApplication $application) => $application->documents->isNotEmpty())
            ->map(function (ScholarshipApplication $application) use ($documentStatusPriority): array {
                $documents = $application->documents
                    ->sortBy(fn (ApplicationDocument $document) => $documentStatusPriority[$document->status ?? 'pending'] ?? 4)
                    ->values();
                $statusCounts = $documents->countBy(fn (ApplicationDocument $document) => $document->status ?? 'pending');
                $needsReview = (int) ($statusCounts['pending'] ?? 0)
                    + (int) ($statusCounts['needs_replacement'] ?? 0)
                    + (int) ($statusCounts['rejected'] ?? 0);

                return [
                    'application_id' => $application->id,
                    'application_status' => $application->status,
                    'applicant' => $application->applicant?->name,
                    'applicant_email' => $application->applicant?->email,
                    'scholarship' => $application->scholarship?->title,
                    'scholarship_image_url' => $application->scholarship
                        ? $this->scholarshipImageUrl($application->scholarship)
                        : asset('uploads/scholarship-default.jpg'),
                    'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
                    'files_count' => $documents->count(),
                    'needs_review_count' => $needsReview,
                    'accepted_count' => (int) ($statusCounts['accepted'] ?? 0),
                    'replacement_count' => (int) ($statusCounts['needs_replacement'] ?? 0),
                    'rejected_count' => (int) ($statusCounts['rejected'] ?? 0),
                    'documents' => $documents
                        ->take(4)
                        ->map(fn (ApplicationDocument $document) => $this->documentPayload($document))
                        ->values(),
                    'review_url' => route('provider.applications.show', [
                        'application' => $application,
                        'section' => 'documents',
                    ]),
                ];
            })
            ->sort(function (array $first, array $second): int {
                return ($second['needs_review_count'] <=> $first['needs_review_count'])
                    ?: ($second['application_id'] <=> $first['application_id']);
            })
            ->values();
        $documentReviewPerPage = 8;
        $documentReviewTotal = $documentReviewPackets->count();
        $documentReviewLastPage = max(1, (int) ceil($documentReviewTotal / $documentReviewPerPage));
        $documentReviewPage = min(
            max(1, $request->integer('document_page', 1)),
            $documentReviewLastPage,
        );
        $documentReviewQueue = [
            'data' => $documentReviewPackets->forPage($documentReviewPage, $documentReviewPerPage)->values(),
            'current_page' => $documentReviewPage,
            'last_page' => $documentReviewLastPage,
            'per_page' => $documentReviewPerPage,
            'total' => $documentReviewTotal,
        ];

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

}
