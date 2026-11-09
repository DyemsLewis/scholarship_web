<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ApplicationDocument;
use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceRequest;
use App\Models\ProviderVerificationDocument;
use App\Models\RecipientBenefitRelease;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\SupportReport;
use App\Models\User;
use App\Queries\Provider\BenefitReleaseWorkspaceQueues;
use App\Queries\Provider\SelectionWorkspaceQueues;
use App\Services\DecisionSupportService;
use Illuminate\Database\Eloquent\Builder;
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
        $organizationApproved = $providerOwner->isActive()
            && $providerOwner->hasVerifiedEmail()
            && $providerOwner->providerProfile?->isVerified();
        $canReviewApplications = $organizationApproved && $provider->hasAnyPortalPermission([
            'verify_applications',
            'manage_selection_activities',
            'record_final_decisions',
        ]);
        $applicationsBase = $this->providerApplicationsQuery($provider);
        $applicationWorkflowCounts = [
            'all' => $canReviewApplications ? (clone $applicationsBase)->count() : 0,
            'needs_review' => 0,
            'waiting_activity' => 0,
            'ready_result' => 0,
            'final_decision' => 0,
        ];
        if ($canReviewApplications && $provider->hasPortalPermission('verify_applications')) {
            $screening = clone $applicationsBase;
            $this->applyProviderApplicationFilter($screening, 'needs_review');
            $applicationWorkflowCounts['needs_review'] = $screening->count();
        }
        if ($canReviewApplications && $provider->hasPortalPermission('manage_selection_activities')) {
            $queues = app(SelectionWorkspaceQueues::class);
            $selection = $queues->forProgramIds($queues->programs($provider)->pluck('id'));
            $applicationWorkflowCounts['waiting_activity'] = $selection['unassigned']->count();
            $applicationWorkflowCounts['ready_result'] = $selection['results']->count();
        }
        if ($canReviewApplications && $provider->hasPortalPermission('record_final_decisions')) {
            $pendingDecisions = clone $applicationsBase;
            $this->applyDecisionOfficerQueueFilter($pendingDecisions, 'pending');
            $applicationWorkflowCounts['final_decision'] = $pendingDecisions->count();
        }
        $managerOverview = $this->providerManagerOverview(
            $provider,
            $providerOwner,
            $scholarships,
            $applicationWorkflowCounts,
            $organizationApproved,
        );

        return response()->json([
            'user' => [
                ...$provider->publicPayload(),
                'verification_documents_count' => $verificationDocumentsCount,
            ],
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
            'application_workflow_counts' => $applicationWorkflowCounts,
            'manager_overview' => $managerOverview,
        ]);
    }

    private function providerManagerOverview(
        User $actor,
        User $owner,
        $scholarships,
        array $applicationWorkflowCounts,
        bool $organizationApproved,
    ): array {
        $providerId = $owner->id;
        $programIds = $scholarships->pluck('id');
        $programAttention = $scholarships->whereIn('status', ['draft', 'rejected'])->count();
        $pendingAgreements = 0;
        if ($organizationApproved && $actor->hasPortalPermission('manage_recipients')) {
            $awaiting = $this->recipientOfficerApplications($actor);
            $this->applyRecipientOfficerQueueFilter($awaiting, 'awaiting');
            $pendingAgreements = $awaiting->count();
        }
        $monitoring = $organizationApproved && $actor->hasPortalPermission('manage_monitoring') && $programIds->isNotEmpty()
            ? $this->providerMonitoringDashboardItems($actor)->countBy('work_state')
            : collect();
        $releases = collect();
        if ($organizationApproved && $actor->hasPortalPermission('manage_benefit_releases') && $programIds->isNotEmpty()) {
            $releaseQueues = app(BenefitReleaseWorkspaceQueues::class);
            $releases = $releaseQueues->releases($programIds)->get()
                ->countBy(fn (RecipientBenefitRelease $release): string => $releaseQueues->classify($release)['state']);
        }
        $supportCases = $actor->hasPortalPermission('manage_reports')
            ? SupportReport::query()
                ->where('provider_id', $providerId)
                ->when($actor->hasLimitedProviderProgramAccess(), fn (Builder $query) => $query->where(
                    fn (Builder $scope) => $scope->whereNull('scholarship_id')->orWhereIn('scholarship_id', $actor->assignedProviderProgramIds()),
                ))
                ->where('assigned_role', 'provider')
                ->where('provider_status', 'open')
                ->count()
            : 0;
        $billingRequests = $billingActions = 0;
        if ($actor->hasPortalPermission('manage_billing')) {
            $purchases = ProviderServicePurchase::query()->where('provider_id', $providerId);
            $billingRequests = ProviderServiceRequest::query()->where('provider_id', $providerId)
                ->whereIn('status', ['ready_for_review', 'checkout_started'])->count()
                + (clone $purchases)->whereNull('service_request_id')->whereIn('status', ['pending', 'failed'])->count();
            $billingActions = $purchases->where('status', 'paid')
                ->whereIn('fulfillment_status', ['needs_information', 'provider_review'])->count();
        }
        $activeTeam = $actor->hasPortalPermission('manage_team')
            ? User::query()
                ->where('parent_account_id', $providerId)
                ->where('account_status', 'active')
                ->count()
            : 0;
        $verificationAttention = $owner->providerProfile?->verification_status === 'approved' ? 0 : 1;
        $workspaceItems = collect([
            ['key' => 'programs', 'label' => 'Program drafts and changes', 'detail' => 'Draft programs and programs returned for revision', 'count' => $programAttention, 'href' => '/provider/programs/drafts', 'permission' => 'manage_programs'],
            ['key' => 'reviews', 'label' => 'Application screening', 'detail' => 'Applications awaiting screening across the review team', 'count' => $applicationWorkflowCounts['needs_review'], 'href' => '/provider/workspaces/reviews/all', 'permission' => 'verify_applications'],
            ['key' => 'selection-scheduling', 'label' => 'Applicants who need a session', 'detail' => 'Exam and interview applicants waiting to be scheduled', 'count' => $applicationWorkflowCounts['waiting_activity'], 'href' => '/provider/workspaces/selection/unassigned', 'permission' => 'manage_selection_activities'],
            ['key' => 'selection-results', 'label' => 'Selection reviews', 'detail' => 'Applications or activity results ready for review', 'count' => $applicationWorkflowCounts['ready_result'], 'href' => '/provider/workspaces/selection/results', 'permission' => 'manage_selection_activities'],
            ['key' => 'decisions', 'label' => 'Pending final decisions', 'detail' => 'Applications awaiting their first final outcome', 'count' => $applicationWorkflowCounts['final_decision'], 'href' => '/provider/workspaces/decisions/pending', 'permission' => 'record_final_decisions'],
            ['key' => 'recipients', 'label' => 'Agreement responses', 'detail' => 'Selected recipients who have not responded', 'count' => $pendingAgreements, 'href' => '/provider/workspaces/recipients/agreements', 'permission' => 'manage_recipients'],
            ['key' => 'monitoring-review', 'label' => 'Check-ins to review', 'detail' => 'Check-ins with results, requests, or outcomes to record', 'count' => $monitoring->get('review', 0), 'href' => '/provider/workspaces/monitoring/review', 'permission' => 'manage_monitoring'],
            ['key' => 'monitoring-followup', 'label' => 'Check-in follow-ups', 'detail' => 'Check-ins with overdue work or open follow-ups', 'count' => $monitoring->get('followup', 0), 'href' => '/provider/workspaces/monitoring/follow-ups', 'permission' => 'manage_monitoring'],
            ['key' => 'releases-issues', 'label' => 'Distribution issues', 'detail' => 'Distributions with open recipient reports', 'count' => $releases->get('issues', 0), 'href' => '/provider/workspaces/releases/issues', 'permission' => 'manage_benefit_releases'],
            ['key' => 'releases-record', 'label' => 'Distributions due to record', 'detail' => 'Due distributions with recipient outcomes still missing', 'count' => $releases->get('record', 0), 'href' => '/provider/workspaces/releases/record', 'permission' => 'manage_benefit_releases'],
            ['key' => 'support', 'label' => 'Support cases', 'detail' => 'Applicant concerns awaiting a provider response', 'count' => $supportCases, 'href' => '/provider/workspaces/support/needs-response', 'permission' => 'manage_reports'],
            ['key' => 'billing-requests', 'label' => 'Service requests', 'detail' => 'Requests awaiting review or payment', 'count' => $billingRequests, 'href' => '/provider/workspaces/billing/requests', 'permission' => 'manage_billing'],
            ['key' => 'billing-action', 'label' => 'Paid services needing action', 'detail' => 'Paid requests needing a provider response or review', 'count' => $billingActions, 'href' => '/provider/workspaces/billing/action', 'permission' => 'manage_billing'],
            ['key' => 'profile', 'label' => 'Organization profile', 'detail' => 'Verification or organization details need attention', 'count' => $verificationAttention, 'href' => '/provider/workspaces/organization-profile/readiness', 'permission' => 'manage_profile'],
        ])->filter(fn (array $item): bool => $actor->hasPortalPermission($item['permission']))
            ->map(fn (array $item): array => collect($item)->except('permission')->all())
            ->values();
        $priority = $workspaceItems
            ->filter(fn (array $item): bool => $item['count'] > 0)
            ->sortBy(fn (array $item): int => array_search($item['key'], [
                'profile', 'programs', 'reviews', 'selection-results', 'selection-scheduling', 'decisions',
                'recipients', 'monitoring-review', 'monitoring-followup', 'releases-issues', 'releases-record',
                'support', 'billing-requests', 'billing-action',
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
                    'label' => 'Open queue',
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
