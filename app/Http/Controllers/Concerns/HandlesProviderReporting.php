<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ApplicationDocument;
use App\Models\ProviderServicePurchase;
use App\Models\ProviderVerificationDocument;
use App\Models\RecipientBenefitReceiptResponse;
use App\Models\RecipientMonitoringSubmission;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
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
        $managerOverview = $this->providerManagerOverview(
            $provider,
            $providerOwner,
            $scholarships,
            $applicationWorkflowCounts,
        );

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
            'manager_overview' => $managerOverview,
        ]);
    }

    private function providerManagerOverview(
        User $actor,
        User $owner,
        $scholarships,
        array $applicationWorkflowCounts,
    ): array {
        $providerId = $owner->id;
        $programIds = $scholarships->pluck('id');
        $programAttention = $scholarships->whereIn('status', ['draft', 'rejected'])->count();
        $pendingAgreements = $actor->hasPortalPermission('manage_recipients')
            ? (clone $this->providerApplicationsQuery($actor))
                ->where('final_outcome', 'selected')
                ->whereNull('student_response_status')
                ->count()
            : 0;
        $monitoringReviews = $actor->hasPortalPermission('manage_monitoring') && $programIds->isNotEmpty()
            ? RecipientMonitoringSubmission::query()
                ->where('review_status', 'pending')
                ->whereHas('cycle', fn ($query) => $query->whereIn('scholarship_id', $programIds))
                ->count()
            : 0;
        $releaseIssues = $actor->hasPortalPermission('manage_benefit_releases') && $programIds->isNotEmpty()
            ? RecipientBenefitReceiptResponse::query()
                ->where('status', 'open')
                ->whereHas('releaseRecord.release', fn ($query) => $query->whereIn('scholarship_id', $programIds))
                ->count()
            : 0;
        $supportCases = $actor->hasPortalPermission('manage_reports')
            ? SupportReport::query()
                ->where('provider_id', $providerId)
                ->where('assigned_role', 'provider')
                ->where('provider_status', 'open')
                ->count()
            : 0;
        $billingActions = $actor->hasPortalPermission('manage_billing')
            ? ProviderServicePurchase::query()
                ->where('provider_id', $providerId)
                ->where(fn ($query) => $query
                    ->whereIn('status', ['pending', 'failed'])
                    ->orWhere(fn ($paid) => $paid
                        ->where('status', 'paid')
                        ->whereIn('fulfillment_status', ['needs_information', 'provider_review'])))
                ->count()
            : 0;
        $activeTeam = $actor->hasPortalPermission('manage_team')
            ? User::query()
                ->where('parent_account_id', $providerId)
                ->where('account_status', 'active')
                ->count()
            : 0;
        $verificationAttention = $owner->providerProfile?->verification_status === 'approved' ? 0 : 1;
        $workspaceItems = collect([
            ['key' => 'programs', 'label' => 'Programs', 'detail' => 'Drafts, requested changes, and publishing', 'count' => $programAttention, 'href' => '/provider/programs/drafts', 'permission' => 'manage_programs'],
            ['key' => 'reviews', 'label' => 'Application review', 'detail' => 'Profiles and evidence awaiting verification', 'count' => (int) ($applicationWorkflowCounts['needs_review'] ?? 0), 'href' => '/provider/workspaces/reviews', 'permission' => 'verify_applications'],
            ['key' => 'selection', 'label' => 'Selection activities', 'detail' => 'Activities to schedule or results to record', 'count' => (int) ($applicationWorkflowCounts['waiting_activity'] ?? 0) + (int) ($applicationWorkflowCounts['ready_result'] ?? 0), 'href' => '/provider/workspaces/selection', 'permission' => 'manage_selection_activities'],
            ['key' => 'decisions', 'label' => 'Final decisions', 'detail' => 'Applications ready for a final outcome', 'count' => (int) ($applicationWorkflowCounts['final_decision'] ?? 0), 'href' => '/provider/workspaces/decisions', 'permission' => 'record_final_decisions'],
            ['key' => 'recipients', 'label' => 'Recipient onboarding', 'detail' => 'Selection agreements awaiting response', 'count' => $pendingAgreements, 'href' => '/provider/workspaces/recipients', 'permission' => 'manage_recipients'],
            ['key' => 'monitoring', 'label' => 'Monitoring', 'detail' => 'Submitted check-in items awaiting review', 'count' => $monitoringReviews, 'href' => '/provider/workspaces/monitoring', 'permission' => 'manage_monitoring'],
            ['key' => 'releases', 'label' => 'Benefit releases', 'detail' => 'Recipient distribution issues awaiting resolution', 'count' => $releaseIssues, 'href' => '/provider/workspaces/releases', 'permission' => 'manage_benefit_releases'],
            ['key' => 'support', 'label' => 'Support cases', 'detail' => 'Applicant concerns awaiting a provider response', 'count' => $supportCases, 'href' => '/provider/workspaces/support', 'permission' => 'manage_reports'],
            ['key' => 'billing', 'label' => 'Service requests', 'detail' => 'Payments, responses, or deliveries needing action', 'count' => $billingActions, 'href' => '/provider/workspaces/billing', 'permission' => 'manage_billing'],
            ['key' => 'profile', 'label' => 'Organization profile', 'detail' => 'Verification or organization details need attention', 'count' => $verificationAttention, 'href' => '/provider/workspaces/organization-profile', 'permission' => 'manage_profile'],
        ])->filter(fn (array $item): bool => $actor->hasPortalPermission($item['permission']))
            ->map(fn (array $item): array => collect($item)->except('permission')->all())
            ->values();
        $priority = $workspaceItems
            ->filter(fn (array $item): bool => $item['count'] > 0)
            ->sortBy(fn (array $item): int => array_search($item['key'], [
                'profile', 'programs', 'reviews', 'selection', 'decisions',
                'recipients', 'monitoring', 'releases', 'support', 'billing',
            ], true))
            ->first();

        return [
            'organization_name' => $owner->provider_name ?: $owner->name,
            'summary' => [
                'programs' => $scholarships->count(),
                'published_programs' => $scholarships->where('status', 'published')->count(),
                'applications' => (int) ($applicationWorkflowCounts['all'] ?? 0),
                'attention' => $workspaceItems->sum('count'),
                'active_team' => $activeTeam,
            ],
            'priority_action' => $priority
                ? [
                    'eyebrow' => 'Next organization action',
                    'title' => $priority['label'],
                    'description' => $priority['detail'],
                    'count' => $priority['count'],
                    'label' => 'Open workspace',
                    'href' => $priority['href'],
                ]
                : [
                    'eyebrow' => 'Organization status',
                    'title' => 'No workflow needs immediate attention',
                    'description' => 'Use the sidebar to review a specific operating area.',
                    'count' => 0,
                    'label' => 'Review programs',
                    'href' => '/provider/programs',
                ],
            'attention_workspaces' => $workspaceItems
                ->filter(fn (array $item): bool => $item['count'] > 0)
                ->sortByDesc('count')
                ->take(6)
                ->values(),
        ];
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
