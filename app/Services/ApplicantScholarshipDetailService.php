<?php

namespace App\Services;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipBookmark;
use App\Models\User;
use App\Support\ScholarshipSelectionPlan;
use Illuminate\Support\Str;

class ApplicantScholarshipDetailService
{
    private const VIEWS = ['summary', 'eligibility', 'award', 'application', 'provider'];

    public function __construct(
        private readonly ScholarshipEligibilityService $eligibilityService,
    ) {}

    public function build(Scholarship $scholarship, User $user, string $view = 'summary'): array
    {
        $view = in_array($view, self::VIEWS, true) ? $view : 'summary';
        $scholarship
            ->loadMissing(['provider.providerProfile', 'benefits', 'events'])
            ->loadCount(['applications', 'bookmarks']);

        $application = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('applicant_id', $user->id)
            ->latest('id')
            ->first();
        $isSaved = ScholarshipBookmark::query()
            ->where('scholarship_id', $scholarship->id)
            ->where('user_id', $user->id)
            ->exists();
        $profileReadiness = $user->applicantProfileReadiness();
        $eligibility = $this->eligibilityService->evaluate($scholarship, $user);
        $decision = $this->decision(
            $scholarship,
            $application,
            $isSaved,
            $profileReadiness,
            $eligibility,
        );
        $eligibilityContent = $this->eligibility($scholarship, $eligibility, $profileReadiness);
        $requirementsContent = in_array($view, ['summary', 'application'], true)
            ? $this->requirements(
                $scholarship,
                $this->eligibilityService->preparedDocumentReadiness($scholarship, $user),
            )
            : null;
        $processContent = in_array($view, ['summary', 'application'], true)
            ? $this->process($scholarship)
            : null;
        $providerContent = $view === 'provider'
            ? $this->provider($scholarship, $user)
            : null;
        $awardContent = in_array($view, ['summary', 'award'], true)
            ? $this->overview($scholarship)
            : null;

        $content = match ($view) {
            'eligibility' => $eligibilityContent,
            'award' => $awardContent,
            'application' => [
                'requirements' => $requirementsContent,
                'process' => $processContent,
            ],
            'provider' => $providerContent,
            default => $this->summary(
                $scholarship,
                $eligibilityContent,
                $requirementsContent ?? [],
                $processContent ?? [],
                $awardContent ?? [],
            ),
        };

        return [
            'version' => 3,
            'view' => $view,
            'page' => $this->page($view),
            'identity' => $this->identity($scholarship),
            'decision' => $decision,
            'snapshot' => $this->facts($scholarship),
            'navigation' => $this->navigation($scholarship, $view),
            'content' => $content,
            'saved' => [
                'is_saved' => $isSaved,
                'label' => $isSaved ? 'Remove from saved' : 'Save scholarship',
                'endpoint' => route(
                    $isSaved ? 'dashboard.scholarships.unsave' : 'dashboard.scholarships.save',
                    $scholarship,
                    false,
                ),
                'method' => $isSaved ? 'DELETE' : 'POST',
            ],
        ];
    }

    public function buildPreview(
        Scholarship $scholarship,
        User $viewer,
        string $view = 'summary',
        string $scenario = 'general',
    ): array {
        $scenario = in_array($scenario, ['general', 'eligible', 'ineligible'], true)
            ? $scenario
            : 'general';
        $detail = $this->build($scholarship, $viewer, $view);

        $detail['preview'] = [
            'enabled' => true,
            'scenario' => $scenario,
            'status' => Str::headline($scholarship->status ?: 'draft'),
        ];
        $detail['saved'] = [
            'is_saved' => false,
            'label' => 'Save scholarship',
            'endpoint' => null,
            'method' => null,
            'disabled' => true,
        ];
        $detail['decision'] = $this->previewDecision($scenario);
        $detail['navigation'] = collect($detail['navigation'])
            ->map(function (array $item) use ($scholarship, $scenario): array {
                $routeParameters = [
                    'scholarship' => $scholarship,
                    'scenario' => $scenario,
                ];

                if ($item['key'] !== 'summary') {
                    $routeParameters['section'] = $item['key'];
                }

                return [
                    ...$item,
                    'href' => route(
                        $item['key'] === 'summary'
                            ? 'provider.previews.programs.show'
                            : 'provider.previews.programs.section',
                        $routeParameters,
                        false,
                    ),
                ];
            })
            ->all();
        $detail['identity']['provider']['href'] = route('provider.previews.organization.show', absolute: false);

        if ($view === 'summary') {
            $detail['content']['readiness'] = collect($detail['content']['readiness'] ?? [])
                ->map(function (array $item) use ($scholarship, $scenario): array {
                    $target = match ($item['key'] ?? null) {
                        'eligibility' => 'eligibility',
                        'files' => 'application',
                        default => 'award',
                    };

                    return [
                        ...$item,
                        'href' => route('provider.previews.programs.section', [
                            'scholarship' => $scholarship,
                            'section' => $target,
                            'scenario' => $scenario,
                        ], false),
                    ];
                })
                ->all();
        }

        if ($view === 'eligibility') {
            $detail['content']['profile'] = [
                'complete' => $scenario !== 'general',
                'verified' => $scenario !== 'general',
                'verification_status' => $scenario === 'general' ? 'unsubmitted' : 'approved',
                'percent' => $scenario === 'general' ? 0 : 100,
                'missing' => $scenario === 'general' ? ['Applicant profile information'] : [],
            ];
            $detail['content']['blockers'] = $scenario === 'ineligible'
                ? [['label' => 'A published eligibility rule does not match this sample applicant.']]
                : [];
            // Preview scenarios must not reuse the provider account's live comparison guidance.
            $detail['content']['guidance'] = [
                'state' => $scenario === 'ineligible' ? 'blocked' : 'ready',
                'label' => 'Applicant example',
                'title' => $scenario === 'ineligible'
                    ? 'A required rule does not match this example'
                    : 'No required mismatches in this example',
                'description' => 'This is a sample outcome, not an actual eligibility decision. Live results use each applicant\'s verified profile and the program\'s Required and Preferred rules.',
            ];
        }

        if ($view === 'application') {
            $detail['content']['requirements']['manage_files_href'] = null;
        }

        if ($view === 'provider') {
            $detail['content']['profile_href'] = route('provider.previews.organization.show', absolute: false);
        }

        return $detail;
    }

    private function previewDecision(string $scenario): array
    {
        $content = match ($scenario) {
            'eligible' => [
                'state' => 'eligible',
                'tone' => 'positive',
                'eyebrow' => 'Eligible applicant example',
                'title' => 'Start with pre-screening',
                'description' => 'An applicant whose profile matches the published rules can begin the application.',
                'can_apply' => true,
            ],
            'ineligible' => [
                'state' => 'blocked',
                'tone' => 'blocked',
                'eyebrow' => 'Ineligible applicant example',
                'title' => 'Some required rules do not match',
                'description' => 'The applicant is directed to review the published requirements before continuing.',
                'can_apply' => false,
            ],
            default => [
                'state' => 'preview',
                'tone' => 'neutral',
                'eyebrow' => 'Applicant view preview',
                'title' => 'The next action depends on the applicant',
                'description' => 'Eligibility, profile readiness, dates, and application capacity determine the live action.',
                'can_apply' => false,
            ],
        };

        return [
            ...$content,
            'has_applied' => false,
            'application_id' => null,
            'application_status' => null,
            'blockers' => [],
            'action' => [
                'kind' => 'disabled',
                'label' => $scenario === 'eligible' ? 'Start pre-screening' : 'Applicant action',
                'href' => null,
                'disabled' => true,
            ],
        ];
    }

    private function decision(
        Scholarship $scholarship,
        ?ScholarshipApplication $application,
        bool $isSaved,
        array $profileReadiness,
        array $eligibility,
    ): array {
        $state = match (true) {
            $application !== null => 'applied',
            $scholarship->application_opens_at?->isAfter(now()->startOfDay()) === true => 'upcoming',
            $scholarship->hasReachedApplicationLimit() => 'full',
            ! $scholarship->isDiscoverable() => 'closed',
            ! ($profileReadiness['complete'] ?? false) => 'profile_incomplete',
            ! ($profileReadiness['verified'] ?? false) => 'profile_unverified',
            ! ($eligibility['is_eligible'] ?? false) => 'blocked',
            default => 'eligible',
        };

        $content = match ($state) {
            'applied' => [
                'tone' => 'positive',
                'eyebrow' => 'Application started',
                'title' => 'Continue from your application',
                'description' => 'Open your application to see its current stage and next action.',
                'action' => [
                    'kind' => 'link',
                    'label' => 'View application',
                    'href' => route('dashboard.applications.show', $application, false),
                ],
            ],
            'upcoming' => [
                'tone' => 'attention',
                'eyebrow' => 'Opens soon',
                'title' => 'Applications open '.$scholarship->application_opens_at->format('M d, Y'),
                'description' => 'Review the program now and save it if you want to return when applications open.',
                'action' => $this->unavailableAction($isSaved),
            ],
            'full' => [
                'tone' => 'muted',
                'eyebrow' => 'Application limit reached',
                'title' => 'This intake is currently full',
                'description' => 'The provider is no longer accepting additional applications for this intake.',
                'action' => $this->unavailableAction($isSaved),
            ],
            'closed' => [
                'tone' => 'muted',
                'eyebrow' => 'Applications closed',
                'title' => 'This application period has ended',
                'description' => 'You can still review the program or keep it saved for future updates.',
                'action' => $this->unavailableAction($isSaved),
            ],
            'profile_incomplete' => [
                'tone' => 'attention',
                'eyebrow' => 'Profile needed',
                'title' => 'Complete your profile before applying',
                'description' => count($profileReadiness['missing'] ?? []).' profile detail(s) still need your attention.',
                'action' => [
                    'kind' => 'link',
                    'label' => 'Complete profile',
                    'href' => '/dashboard/profile',
                ],
            ],
            'profile_unverified' => [
                'tone' => 'attention',
                'eyebrow' => 'Profile verification required',
                'title' => 'Verify your profile before applying',
                'description' => ($profileReadiness['verification_status'] ?? null) === 'pending'
                    ? 'Your supporting evidence is awaiting review. You can apply once your profile is verified.'
                    : 'Upload or replace the required supporting evidence in your profile. An authorized reviewer must verify your profile before you can apply.',
                'action' => [
                    'kind' => 'link',
                    'label' => 'Review supporting evidence',
                    'href' => '/dashboard/profile?section=verification',
                ],
            ],
            'blocked' => [
                'tone' => 'blocked',
                'eyebrow' => 'Review eligibility',
                'title' => 'Some required rules do not match',
                'description' => $eligibility['difference_summary'] ?? 'Review the eligibility comparison before applying.',
                'action' => [
                    'kind' => 'link',
                    'label' => 'Review eligibility',
                    'href' => route('dashboard.scholarships.show.section', [
                        'scholarship' => $scholarship,
                        'section' => 'eligibility',
                    ], false),
                ],
            ],
            default => [
                'tone' => ($eligibility['guidance']['state'] ?? 'ready') === 'attention' ? 'attention' : 'positive',
                'eyebrow' => 'Ready to begin',
                'title' => 'Start with pre-screening',
                'description' => ($eligibility['guidance']['state'] ?? 'ready') === 'attention'
                    ? ($eligibility['difference_summary'].' You may start pre-screening; the provider still reviews your evidence and conditions.')
                    : 'No required rule mismatches were found. The provider will still verify your evidence and conditions.',
                'action' => [
                    'kind' => 'link',
                    'label' => 'Start pre-screening',
                    'href' => route('dashboard.applications.start', $scholarship, false),
                ],
            ],
        };

        return [
            'state' => $state,
            'can_apply' => $state === 'eligible',
            'has_applied' => $application !== null,
            'application_id' => $application?->id,
            'application_status' => $application?->status,
            'blockers' => $this->eligibilityService->blockers($eligibility),
            ...$content,
        ];
    }

    private function unavailableAction(bool $isSaved): array
    {
        return $isSaved
            ? [
                'kind' => 'link',
                'label' => 'View saved scholarships',
                'href' => '/dashboard/scholarships?view=saved',
            ]
            : [
                'kind' => 'save',
                'label' => 'Save scholarship',
            ];
    }

    private function identity(Scholarship $scholarship): array
    {
        return [
            'id' => $scholarship->id,
            'title' => $scholarship->title,
            'category' => $scholarship->category,
            'program_cycle' => $scholarship->program_cycle,
            'image_url' => $scholarship->coverImageUrl(),
            'provider' => [
                'id' => $scholarship->provider?->id,
                'name' => $scholarship->provider?->provider_name ?? $scholarship->provider?->name,
                'type' => $scholarship->provider?->provider_type,
                'logo_url' => $this->publicAssetUrl($scholarship->provider?->providerProfile?->logo_path),
                'cover_url' => $scholarship->provider?->providerProfile?->coverImageUrl(),
                'href' => $scholarship->provider
                    ? route('dashboard.providers.show', $scholarship->provider, false)
                    : null,
                'verified' => $scholarship->provider?->providerProfile?->isVerified() ?? false,
                'verification_label' => $scholarship->provider?->providerProfile?->isVerified()
                    ? 'Verified organization'
                    : 'Verification pending',
            ],
        ];
    }

    private function page(string $view): array
    {
        return match ($view) {
            'eligibility' => [
                'eyebrow' => 'Eligibility',
                'title' => 'Compare your profile with the rules',
                'description' => 'See what matches and what the provider still needs to verify.',
            ],
            'award' => [
                'eyebrow' => 'Benefits and terms',
                'title' => 'Understand the complete offer',
                'description' => 'Review the support and recipient responsibilities before applying.',
            ],
            'application' => [
                'eyebrow' => 'How to apply',
                'title' => 'Prepare for the application process',
                'description' => 'Check your files and the stages that follow submission.',
            ],
            'provider' => [
                'eyebrow' => 'Scholarship provider',
                'title' => 'Know who manages the program',
                'description' => 'Review the verified organization, public contact, and program location.',
            ],
            default => [
                'eyebrow' => 'Overview',
                'title' => 'Review the essentials',
                'description' => 'See the offer, your readiness, and the next action.',
            ],
        };
    }

    private function navigation(Scholarship $scholarship, string $activeView): array
    {
        return collect([
            ['key' => 'summary', 'label' => 'Overview', 'icon' => 'fa-solid fa-compass'],
            ['key' => 'eligibility', 'label' => 'Eligibility', 'icon' => 'fa-solid fa-list-check'],
            ['key' => 'award', 'label' => 'Benefits & terms', 'icon' => 'fa-solid fa-gift'],
            ['key' => 'application', 'label' => 'How to apply', 'icon' => 'fa-solid fa-route'],
            ['key' => 'provider', 'label' => 'Provider', 'icon' => 'fa-solid fa-building-shield'],
        ])->map(function (array $item) use ($scholarship, $activeView): array {
            $href = $item['key'] === 'summary'
                ? route('dashboard.scholarships.show', $scholarship, false)
                : route('dashboard.scholarships.show.section', [
                    'scholarship' => $scholarship,
                    'section' => $item['key'],
                ], false);

            return [
                ...$item,
                'href' => $href,
                'active' => $item['key'] === $activeView,
            ];
        })->all();
    }

    private function facts(Scholarship $scholarship): array
    {
        $remainingCapacity = $scholarship->remainingApplicationCapacity();
        return [
            [
                'key' => 'benefit',
                'label' => 'Benefit',
                'value' => $this->compactBenefitSummary($scholarship),
            ],
            [
                'key' => 'deadline',
                'label' => 'Deadline',
                'value' => $scholarship->deadline?->format('M d, Y') ?? 'No deadline listed',
            ],
            [
                'key' => 'capacity',
                'label' => 'Application capacity',
                'value' => $remainingCapacity === null
                    ? 'No application limit'
                    : ($remainingCapacity === 0 ? 'Full' : $remainingCapacity.' remaining'),
            ],
        ];
    }

    private function summary(
        Scholarship $scholarship,
        array $eligibility,
        array $requirements,
        array $process,
        array $award,
    ): array {
        $profile = $eligibility['profile'] ?? [];
        $blockers = $eligibility['blockers'] ?? [];
        $readiness = $requirements['readiness'] ?? [];
        $stages = $process['stages'] ?? [];

        $eligibilityRow = match (true) {
            ! ($profile['complete'] ?? false) => [
                'state' => 'attention',
                'status' => 'Profile needed',
                'title' => 'Complete your eligibility check',
                'description' => count($profile['missing'] ?? []).' profile detail(s) are still missing.',
            ],
            ! ($profile['verified'] ?? false) => [
                'state' => 'attention',
                'status' => 'Verification required',
                'title' => 'Your profile needs verification',
                'description' => 'An authorized reviewer must approve your supporting evidence before you can apply.',
            ],
            $blockers !== [] => [
                'state' => 'blocked',
                'status' => count($blockers).' required rule(s) not met',
                'title' => 'Some required rules do not match',
                'description' => $eligibility['guidance']['description'] ?? 'Open the comparison to see what prevents applying.',
            ],
            ($eligibility['guidance']['state'] ?? null) === 'attention' => [
                'state' => 'attention',
                'status' => $eligibility['guidance']['label'],
                'title' => $eligibility['guidance']['title'],
                'description' => $eligibility['guidance']['description'],
            ],
            default => [
                'state' => 'ready',
                'status' => 'Matched',
                'title' => 'Published rules match your profile',
                'description' => 'The provider will still verify your information during review.',
            ],
        };

        $fileRow = match ($readiness['state'] ?? 'not_required') {
            'ready' => [
                'state' => 'ready',
                'status' => 'Ready',
                'title' => 'Your required files are prepared',
                'description' => ($readiness['ready_count'] ?? 0).' saved file(s) can be reused.',
            ],
            'needs_files' => [
                'state' => 'attention',
                'status' => ($readiness['missing_count'] ?? 0).' missing',
                'title' => 'Prepare the required files',
                'description' => 'Upload missing records in My Documents before submitting.',
            ],
            default => [
                'state' => 'neutral',
                'status' => 'Not required now',
                'title' => 'Initial review uses your profile',
                'description' => 'The provider may request proof after pre-screening.',
            ],
        };

        return [
            'description' => $scholarship->description,
            'primary_benefit' => collect($award['benefits'] ?? [])->first(),
            'additional_benefit_count' => max(0, count($award['benefits'] ?? []) - 1),
            'readiness' => [
                [
                    'key' => 'eligibility',
                    'label' => 'Eligibility',
                    'icon' => 'fa-solid fa-list-check',
                    'href' => route('dashboard.scholarships.show.section', [
                        'scholarship' => $scholarship,
                        'section' => 'eligibility',
                    ], false),
                    ...$eligibilityRow,
                ],
                [
                    'key' => 'files',
                    'label' => 'Application files',
                    'icon' => 'fa-solid fa-folder-open',
                    'href' => route('dashboard.scholarships.show.section', [
                        'scholarship' => $scholarship,
                        'section' => 'application',
                    ], false),
                    ...$fileRow,
                ],
                [
                    'key' => 'process',
                    'label' => 'Selection process',
                    'icon' => 'fa-solid fa-route',
                    'href' => route('dashboard.scholarships.show.section', [
                        'scholarship' => $scholarship,
                        'section' => 'application',
                    ], false),
                    'state' => 'neutral',
                    'status' => count($stages).' step'.(count($stages) === 1 ? '' : 's'),
                    'title' => $stages[0]['label'] ?? 'Provider review',
                    'description' => 'Open the plan to see what happens after you apply.',
                ],
            ],
        ];
    }

    private function overview(Scholarship $scholarship): array
    {
        $agreement = $scholarship->recipient_agreement ?? [];
        $termsPreview = collect([
            [
                'key' => 'responsibilities',
                'label' => 'Recipient responsibilities',
                'value' => $agreement['responsibilities']
                    ?? $scholarship->return_service_contract
                    ?? $scholarship->other_contract_terms,
            ],
            [
                'key' => 'release_conditions',
                'label' => 'Benefit release',
                'value' => $agreement['release_conditions'] ?? null,
            ],
            [
                'key' => filled($scholarship->renewal_policy) ? 'renewal' : 'duration',
                'label' => filled($scholarship->renewal_policy) ? 'Renewal' : 'Commitment period',
                'value' => $scholarship->renewal_policy ?? $agreement['duration'] ?? null,
            ],
        ])->filter(fn (array $term): bool => filled($term['value']))
            ->values()
            ->all();
        $agreementRows = collect([
            ['key' => 'responsibilities', 'label' => 'Recipient responsibilities', 'value' => $agreement['responsibilities'] ?? null],
            ['key' => 'required_evidence', 'label' => 'Proof recipients submit', 'value' => $agreement['required_evidence'] ?? null],
            ['key' => 'release_conditions', 'label' => 'Benefit release conditions', 'value' => $agreement['release_conditions'] ?? null],
            ['key' => 'duration', 'label' => 'How long responsibilities apply', 'value' => $agreement['duration'] ?? null],
            ['key' => 'noncompliance_consequence', 'label' => 'If a requirement is not completed', 'value' => $agreement['noncompliance_consequence'] ?? null],
            ['key' => 'exit_or_exception_process', 'label' => 'Exceptions or withdrawal', 'value' => $agreement['exit_or_exception_process'] ?? null],
            ['key' => 'renewal_policy', 'label' => 'Renewal policy', 'value' => $scholarship->renewal_policy],
            ['key' => 'return_service_contract', 'label' => 'Return service', 'value' => $scholarship->return_service_contract],
            ['key' => 'other_contract_terms', 'label' => 'Other terms', 'value' => $scholarship->other_contract_terms],
        ])->filter(fn (array $row): bool => filled($row['value']))
            ->values()
            ->all();

        return [
            'description' => $scholarship->description,
            'benefits' => $scholarship->benefitPayload(),
            'support_period' => collect([
                $scholarship->support_starts_at?->format('M d, Y'),
                $scholarship->support_ends_at?->format('M d, Y'),
            ])->filter()->implode(' to ') ?: 'Not specified',
            'provider_objectives' => $scholarship->provider_objectives ?? [],
            'provider_objective_notes' => $scholarship->provider_objective_notes,
            'terms_preview' => $termsPreview,
            'commitment_type' => $agreement['commitment_type'] ?? null,
            'terms' => $agreementRows,
            'has_full_terms' => $agreement !== []
                || filled($scholarship->renewal_policy)
                || filled($scholarship->return_service_contract)
                || filled($scholarship->other_contract_terms),
            'recipient_terms' => [
                'renewal_policy' => $scholarship->renewal_policy,
                'return_service_contract' => $scholarship->return_service_contract,
                'other_contract_terms' => $scholarship->other_contract_terms,
                'agreement' => $scholarship->recipient_agreement ?? [],
            ],
        ];
    }

    private function eligibility(
        Scholarship $scholarship,
        array $eligibility,
        array $profileReadiness,
    ): array {
        $criteria = collect($eligibility['criteria'] ?? []);
        $conditions = collect($eligibility['condition_results'] ?? []);
        $providerReview = $conditions
            ->whereIn('status', ['confirmation_required', 'provider_review'])
            ->values();

        return [
            'criteria' => $criteria->values()->all(),
            'guidance' => $eligibility['guidance'] ?? null,
            'preferred_mismatches' => $eligibility['preferred_mismatches'] ?? [],
            'review_criteria' => $eligibility['review_criteria'] ?? [],
            'blockers' => $this->eligibilityService->blockers($eligibility),
            'missing_checks' => $criteria
                ->where('category', 'eligibility')
                ->where('status', 'missing')
                ->reject(fn (array $criterion): bool => ($criterion['key'] ?? null) === 'documents' || ($criterion['needs_review'] ?? false))
                ->map(fn (array $criterion): array => [
                    'key' => $criterion['key'] ?? null,
                    'label' => $criterion['label'] ?? 'Eligibility detail',
                    'note' => $criterion['note'] ?? $criterion['comparison'] ?? null,
                ])
                ->values()
                ->all(),
            'written_conditions' => $scholarship->eligibility,
            'provider_review' => $providerReview->all(),
            'profile' => [
                'complete' => (bool) ($profileReadiness['complete'] ?? false),
                'verified' => (bool) ($profileReadiness['verified'] ?? false),
                'verification_status' => $profileReadiness['verification_status'] ?? 'unsubmitted',
                'percent' => (int) ($profileReadiness['percent'] ?? 0),
                'missing' => $profileReadiness['missing'] ?? [],
            ],
        ];
    }

    private function requirements(Scholarship $scholarship, array $documents): array
    {
        $requiredDocuments = collect($documents['required_documents'] ?? []);
        $matchedDocuments = collect($documents['matched'] ?? []);
        $requiredCount = $requiredDocuments->count();
        $readyCount = $matchedDocuments->count();
        $missingCount = max(0, $requiredCount - $readyCount);
        $state = match (true) {
            $requiredCount === 0 => 'not_required',
            $missingCount === 0 => 'ready',
            default => 'needs_files',
        };
        $readinessContent = match ($state) {
            'ready' => [
                'title' => 'Required files are ready',
                'description' => 'Your saved files can be reused when you start this application.',
            ],
            'not_required' => [
                'title' => 'No files needed before you apply',
                'description' => 'The initial review uses the information already saved in your profile.',
            ],
            default => [
                'title' => 'Prepare '.$missingCount.' file'.($missingCount === 1 ? '' : 's').' before applying',
                'description' => 'Upload each file once in My Documents, then reuse it for this application.',
            ],
        };

        return [
            'review_method' => $scholarship->application_mode,
            'review_method_label' => match ($scholarship->application_mode) {
                'online' => 'Online document review',
                'onsite' => 'In-person document review',
                'hybrid' => 'Online and in-person review',
                'provider_review' => 'Profile review',
                default => 'Provider review method not specified',
            },
            'review_method_note' => match ($scholarship->application_mode) {
                'online' => 'The provider reviews the required files through the portal.',
                'onsite', 'hybrid' => 'Upload the required files and keep the originals for possible in-person verification.',
                'provider_review' => 'The provider checks your profile first and may request files later.',
                default => 'The provider will explain how supporting files are reviewed.',
            },
            'readiness' => [
                'state' => $state,
                ...$readinessContent,
                'required_count' => $requiredCount,
                'ready_count' => $readyCount,
                'missing_count' => $missingCount,
                'items' => $requiredDocuments
                    ->map(fn (string $name): array => [
                        'name' => $name,
                        'status' => $matchedDocuments->contains($name) ? 'ready' : 'missing',
                        'status_label' => $matchedDocuments->contains($name) ? 'Ready' : 'Upload needed',
                    ])
                    ->values()
                    ->all(),
            ],
            'optional_documents' => $scholarship->application_mode === 'provider_review'
                ? []
                : $this->eligibilityService->optionalDocumentRequirements($scholarship),
            'post_qualification_documents' => $this->eligibilityService
                ->splitDocumentRequirements($scholarship->post_qualification_requirements),
            'manage_files_href' => '/dashboard/documents',
        ];
    }

    private function process(Scholarship $scholarship): array
    {
        $stages = ScholarshipSelectionPlan::normalize($scholarship->selection_stages);
        $events = $scholarship->events
            ->where('status', 'scheduled')
            ->filter(fn ($event): bool => in_array($event->type, $stages, true))
            ->sortBy('scheduled_at')
            ->map(fn ($event): array => [
                'id' => $event->id,
                'type' => $event->type,
                'title' => $event->title,
                'scheduled_label' => $event->scheduled_at?->format('M d, Y h:i A'),
                'mode' => $event->mode,
                'venue' => $event->venue,
                'location_address' => $event->location_address,
                'status' => $event->status,
            ])
            ->values()
            ->values();
        $eventsByType = $events->keyBy('type');
        $expectedResults = $scholarship->expected_results_at?->format('M d, Y');

        return [
            'stages' => collect($stages)
                ->map(function (string $stage, int $index) use ($eventsByType, $expectedResults): array {
                    $event = $eventsByType->get($stage);
                    $definition = match ($stage) {
                        'screening' => [
                            'label' => 'Pre-screening review',
                            'icon' => 'fa-solid fa-list-check',
                            'description' => 'The provider checks your profile, eligibility, and required files.',
                        ],
                        'formal_application' => [
                            'label' => 'Formal application',
                            'icon' => 'fa-solid fa-file-signature',
                            'description' => 'Complete the provider instructions only if you pass pre-screening.',
                        ],
                        'exam' => [
                            'label' => 'Exam',
                            'icon' => 'fa-solid fa-clipboard-question',
                            'description' => 'Take the provider assessment if you advance to this stage.',
                        ],
                        'interview' => [
                            'label' => 'Interview',
                            'icon' => 'fa-solid fa-comments',
                            'description' => 'Meet the provider online or in person if you are shortlisted.',
                        ],
                        default => [
                            'label' => 'Final decision',
                            'icon' => 'fa-solid fa-award',
                            'description' => 'Receive the selected, waitlisted, or not selected result.',
                        ],
                    };
                    $eventPlace = $event
                        ? collect([
                            match ($event['mode'] ?? null) {
                                'online' => 'Online',
                                'hybrid' => 'Online and in person',
                                'onsite' => 'In person',
                                default => Str::headline($event['mode'] ?? ''),
                            },
                            $event['venue'] ?: $event['location_address'],
                        ])->filter()->implode(' | ')
                        : null;
                    $timing = $event
                        ? [
                            'label' => $event['scheduled_label'] ?? 'Scheduled activity',
                            'note' => $eventPlace,
                            'state' => 'scheduled',
                        ]
                        : match ($stage) {
                            'screening' => ['label' => 'First step', 'note' => 'Portal review', 'state' => 'standard'],
                            'formal_application' => ['label' => 'If you qualify', 'note' => 'Provider instructions', 'state' => 'conditional'],
                            'exam', 'interview' => ['label' => 'If you advance', 'note' => 'Date shared later', 'state' => 'conditional'],
                            default => [
                                'label' => $expectedResults ?: 'After all reviews',
                                'note' => $expectedResults ? 'Expected result date' : 'Provider decision',
                                'state' => $expectedResults ? 'scheduled' : 'standard',
                            ],
                        };

                    return [
                        'key' => $stage,
                        'step' => $index + 1,
                        ...$definition,
                        'timing' => $timing,
                    ];
                })
                ->all(),
            'intro' => 'You move forward one step at a time. The provider will notify you before any activity that needs a schedule.',
        ];
    }

    private function provider(Scholarship $scholarship, User $user): array
    {
        $provider = $scholarship->provider;
        $profile = $provider?->providerProfile;
        $distanceKm = $this->distanceKm($user, $scholarship);
        $contactDepartment = $scholarship->contact_department ?: $profile?->contact_department;
        $contactEmail = $scholarship->contact_email ?: $profile?->provider_contact_email;
        $contactNumber = $scholarship->contact_number ?: $profile?->provider_contact_number;

        return [
            'name' => $provider?->provider_name ?? $provider?->name,
            'type_label' => Str::headline($provider?->provider_type ?: 'Scholarship provider'),
            'verified' => $profile?->isVerified() ?? false,
            'verification_label' => $profile?->isVerified()
                ? 'Verified organization'
                : 'Verification pending',
            'verified_at' => $profile?->verified_at?->format('M d, Y'),
            'summary' => $profile?->mission ?: $provider?->provider_description,
            'year_established' => $profile?->year_established,
            'service_area' => $profile?->service_area,
            'website' => $provider?->provider_website,
            'logo_url' => $this->publicAssetUrl($profile?->logo_path),
            'cover_url' => $profile?->coverImageUrl(),
            'profile_href' => $provider
                ? route('dashboard.providers.show', $provider, false)
                : null,
            'official_program_url' => $scholarship->official_program_url,
            'contact' => [
                'person' => $scholarship->contact_person,
                'department' => $contactDepartment,
                'email' => $contactEmail,
                'number' => $contactNumber,
                'office_hours' => $profile?->office_hours,
                'available' => collect([
                    $scholarship->contact_person,
                    $contactDepartment,
                    $contactEmail,
                    $contactNumber,
                    $profile?->office_hours,
                ])->contains(fn (mixed $value): bool => filled($value)),
            ],
            'location' => [
                'name' => $scholarship->location_name,
                'address' => $scholarship->location_address ?: $scholarship->eligible_locations,
                'latitude' => $scholarship->latitude,
                'longitude' => $scholarship->longitude,
                'distance_label' => $distanceKm === null ? null : number_format($distanceKm, 1).' km away',
                'has_map' => $scholarship->latitude !== null
                    || $scholarship->longitude !== null
                    || filled($scholarship->location_address)
                    || filled($scholarship->location_name),
            ],
            'decision_note' => 'The provider reviews applications and makes the final scholarship decision.',
        ];
    }

    private function distanceKm(User $user, Scholarship $scholarship): ?float
    {
        $profile = $user->studentProfile;

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

    private function compactBenefitSummary(Scholarship $scholarship): string
    {
        $benefits = collect($scholarship->benefitPayload());
        $primary = $benefits->first();

        if (! is_array($primary)) {
            return 'Benefits not specified';
        }

        $amount = filled($primary['amount'] ?? null)
            ? 'PHP '.number_format((float) $primary['amount'], 0)
            : null;
        $details = collect([$amount, $primary['frequency_label'] ?? null])
            ->filter()
            ->implode(' | ');
        $summary = (string) ($primary['title'] ?? $primary['type_label'] ?? 'Scholarship support');

        if ($details !== '') {
            $summary .= ' - '.$details;
        }

        $remaining = $benefits->count() - 1;

        return $remaining > 0 ? $summary.' + '.$remaining.' more' : $summary;
    }

    private function publicAssetUrl(?string $path, ?string $fallback = null): ?string
    {
        $path = filled($path) ? $path : $fallback;

        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return '/'.ltrim($path, '/');
    }
}
