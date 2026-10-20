<?php

namespace App\Services;

use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\ApplicantStageActivityPayload;
use App\Support\ApplicationSchedulePayload;
use App\Support\RecipientAgreement;
use App\Support\Terms;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ApplicantApplicationDetailService
{
    private const VIEWS = ['summary', 'submission', 'activities', 'outcome', 'history'];

    public function __construct(
        private readonly ApplicationWorkflowService $workflowService,
        private readonly ScholarshipEligibilityService $eligibilityService,
    ) {}

    public function build(
        ScholarshipApplication $application,
        User $applicant,
        string $view = 'summary',
    ): array {
        if ((int) $application->applicant_id !== (int) $applicant->id) {
            throw new InvalidArgumentException('The application does not belong to this applicant.');
        }

        $view = in_array($view, self::VIEWS, true) ? $view : 'summary';

        $application->loadMissing([
            'documents',
            'schedules',
            'stageProgresses',
            'stageAssignments.session',
            'stageAssignments.attendance',
            'statusHistories.actor',
            'scholarship.provider.providerProfile',
            'scholarship.benefits',
        ]);

        $workflow = $this->workflowService->payload($application);
        $documentRows = $this->documentRows($application, $workflow);
        $activities = $this->activities($application);
        $agreement = RecipientAgreement::payload($application);
        $decision = $this->decision($application, $workflow, $documentRows, $activities, $agreement);

        $content = match ($view) {
            'submission' => $this->submission($application, $workflow, $documentRows),
            'activities' => $this->activityContent($application, $workflow, $activities),
            'outcome' => $this->outcome($application, $agreement),
            'history' => $this->history($application, $workflow),
            default => $this->summary($application, $workflow, $documentRows, $activities),
        };

        return [
            'version' => 1,
            'view' => $view,
            'page' => $this->page($view),
            'identity' => $this->identity($application, $workflow),
            'decision' => $decision,
            'progress' => $this->progress($workflow),
            'snapshot' => $this->snapshot($application, $workflow, $documentRows),
            'navigation' => $this->navigation($application, $view, $documentRows, $activities, $agreement),
            'content' => $content,
            'permissions' => [
                'can_withdraw' => ! ($workflow['is_closed'] ?? false),
                'can_respond_to_correction' => $application->correction_status === 'requested',
                'can_respond_to_agreement' => (bool) ($agreement['can_respond'] ?? false),
            ],
        ];
    }

    private function identity(ScholarshipApplication $application, array $workflow): array
    {
        $scholarship = $application->scholarship;
        $provider = $scholarship?->provider;
        $providerProfile = $provider?->providerProfile;
        $status = $workflow['final_outcome_label']
            ?: ($workflow['application_state_label'] ?? $this->label($application->status));

        return [
            'program' => [
                'title' => $scholarship?->title ?? 'Scholarship application',
                'image_url' => $this->publicAssetUrl($scholarship?->image_path, 'uploads/scholarship-default.jpg'),
                'deadline' => $scholarship?->deadline?->format('M d, Y'),
                'review_method' => $this->applicationModeLabel($scholarship?->application_mode),
            ],
            'provider' => [
                'name' => $provider?->provider_name ?? $provider?->name ?? 'Scholarship provider',
                'logo_url' => $this->publicAssetUrl($providerProfile?->logo_path),
                'verified' => $providerProfile?->isVerified() ?? false,
                'href' => $provider
                    ? route('dashboard.providers.show', $provider, false)
                    : null,
            ],
            'status' => [
                'key' => $workflow['final_outcome'] ?: $workflow['application_state'],
                'label' => $status,
                'tone' => $this->statusTone($application, $workflow),
            ],
            'submitted_at' => $application->submitted_at?->format('M d, Y h:i A'),
        ];
    }

    private function decision(
        ScholarshipApplication $application,
        array $workflow,
        array $documents,
        array $activities,
        ?array $agreement,
    ): array {
        if ($application->correction_status === 'requested') {
            return [
                'state' => 'action_required',
                'tone' => 'attention',
                'eyebrow' => 'Your next action',
                'title' => 'Complete the requested update',
                'description' => $application->correction_message
                    ?: 'Review the provider request, update the affected information, and send your response.',
                'actor' => 'applicant',
                'actor_label' => 'You',
                'due_label' => null,
                'action' => [
                    'kind' => 'link',
                    'label' => 'Review requested update',
                    'href' => $this->viewUrl($application, 'submission').'?action=correction',
                    'target' => 'correction',
                ],
            ];
        }

        if ($agreement['can_respond'] ?? false) {
            return [
                'state' => 'action_required',
                'tone' => 'attention',
                'eyebrow' => 'Your next action',
                'title' => 'Review the recipient agreement',
                'description' => 'Check the recorded support and responsibilities before accepting or declining.',
                'actor' => 'applicant',
                'actor_label' => 'You',
                'due_label' => null,
                'action' => [
                    'kind' => 'link',
                    'label' => 'Review agreement',
                    'href' => $this->viewUrl($application, 'outcome').'?action=agreement',
                    'target' => 'agreement',
                ],
            ];
        }

        $activity = collect($activities)->first(
            fn (array $item): bool => (bool) ($item['requires_applicant_acknowledgment'] ?? false),
        );

        if ($activity) {
            return [
                'state' => 'action_required',
                'tone' => 'attention',
                'eyebrow' => 'Your next action',
                'title' => 'Review the '.$activity['type_label'].' details',
                'description' => 'Confirm that you received the published date and instructions.',
                'actor' => 'applicant',
                'actor_label' => 'You',
                'due_label' => $activity['scheduled_label'],
                'action' => [
                    'kind' => 'link',
                    'label' => 'Open activity',
                    'href' => $this->viewUrl($application, 'activities'),
                ],
            ];
        }

        $documentAttentionRows = collect($documents)->where('needs_attention', true);
        $documentAttention = $documentAttentionRows->count();

        if (! ($workflow['is_closed'] ?? false) && $documentAttention > 0) {
            $missing = $documentAttentionRows->where('status', 'missing')->count();
            $replacements = $documentAttention - $missing;
            $title = match (true) {
                $missing > 0 && $replacements > 0 => 'Complete the required application files',
                $missing === 1 => 'Upload one required application file',
                $missing > 1 => "Upload {$missing} required application files",
                $replacements === 1 => 'Replace one application file',
                default => "Replace {$replacements} application files",
            };

            return [
                'state' => 'action_required',
                'tone' => 'attention',
                'eyebrow' => 'Your next action',
                'title' => $title,
                'description' => 'Open the submission record to review the provider feedback and upload a replacement.',
                'actor' => 'applicant',
                'actor_label' => 'You',
                'due_label' => null,
                'action' => [
                    'kind' => 'link',
                    'label' => 'Review files',
                    'href' => $this->viewUrl($application, 'submission'),
                ],
            ];
        }

        if (($application->final_outcome === 'selected')
            && $application->student_response_status === 'accepted') {
            return [
                'state' => 'recipient_support',
                'tone' => 'positive',
                'eyebrow' => 'Application complete',
                'title' => 'Continue to recipient support',
                'description' => 'Your selection and agreement are recorded. Monitoring and benefit releases continue in their own workspace.',
                'actor' => 'applicant',
                'actor_label' => 'You',
                'due_label' => null,
                'action' => [
                    'kind' => 'link',
                    'label' => 'Open recipient support',
                    'href' => route('dashboard.monitoring.show', $application, false),
                ],
            ];
        }

        $nextAction = $workflow['next_action'] ?? [];
        $actor = (string) ($nextAction['actor'] ?? 'provider');
        $isClosed = (bool) ($workflow['is_closed'] ?? false);
        $view = $this->actionView($application, $nextAction);

        return [
            'state' => $isClosed ? 'complete' : ($actor === 'applicant' ? 'action_required' : 'waiting'),
            'tone' => $isClosed ? $this->statusTone($application, $workflow) : ($actor === 'applicant' ? 'attention' : 'neutral'),
            'eyebrow' => $isClosed
                ? 'Application result'
                : ($actor === 'applicant' ? 'Your next action' : 'Provider reviewing'),
            'title' => $nextAction['label'] ?? 'Review application status',
            'description' => $nextAction['description'] ?? 'Open the application record for the latest update.',
            'actor' => $actor,
            'actor_label' => $actor === 'applicant' ? 'You' : ($nextAction['actor_label'] ?? 'Scholarship provider'),
            'due_label' => null,
            'action' => ($actor === 'applicant' || $isClosed)
                ? [
                    'kind' => 'link',
                    'label' => $isClosed ? 'Review result' : 'Open current step',
                    'href' => $this->viewUrl($application, $view),
                ]
                : null,
        ];
    }

    private function progress(array $workflow): array
    {
        return [
            'current_stage' => $workflow['current_stage'],
            'current_stage_label' => $workflow['current_stage_label'],
            'completed_steps' => $workflow['completed_steps'],
            'total_steps' => $workflow['total_steps'],
            'percent' => $workflow['percent'],
            'steps' => collect($workflow['steps'] ?? [])->map(fn (array $step): array => [
                'key' => $step['key'],
                'position' => $step['position'],
                'label' => $step['label'],
                'description' => $step['description'],
                'status' => $step['status'],
                'result' => $step['result'],
                'result_label' => $this->resultLabel($step['result']),
                'started_at' => $step['started_at'],
                'completed_at' => $step['completed_at'],
                'current' => $step['key'] === ($workflow['current_stage'] ?? null),
            ])->values()->all(),
        ];
    }

    private function snapshot(
        ScholarshipApplication $application,
        array $workflow,
        array $documents,
    ): array {
        $required = collect($documents)->where('required', true);
        $accepted = $required->where('status', 'accepted')->count();

        return [
            [
                'key' => 'stage',
                'label' => 'Current stage',
                'value' => $workflow['current_stage_label'] ?? 'Application review',
            ],
            [
                'key' => 'progress',
                'label' => 'Process progress',
                'value' => ($workflow['percent'] ?? 0).'%',
            ],
            [
                'key' => 'files',
                'label' => 'Required files',
                'value' => $required->isEmpty()
                    ? 'No files required'
                    : "{$accepted} of {$required->count()} accepted",
            ],
            [
                'key' => 'submitted',
                'label' => 'Submitted',
                'value' => $application->submitted_at?->format('M d, Y') ?? 'Not recorded',
            ],
        ];
    }

    private function summary(
        ScholarshipApplication $application,
        array $workflow,
        array $documents,
        array $activities,
    ): array {
        $currentStep = collect($workflow['steps'] ?? [])->firstWhere('key', $workflow['current_stage'] ?? null);
        $currentActivity = collect($activities)->first(
            fn (array $activity): bool => in_array($activity['status'], ['scheduled', 'ongoing'], true),
        );
        $scholarship = $application->scholarship;

        return [
            'current_step' => [
                'key' => $workflow['current_stage'],
                'label' => $workflow['current_stage_label'],
                'description' => $currentStep['description']
                    ?? ($workflow['is_closed'] ? 'The provider process is complete.' : 'The provider is reviewing this application.'),
                'status' => $currentStep['status'] ?? ($workflow['is_closed'] ? 'complete' : 'current'),
            ],
            'upcoming_activity' => $currentActivity,
            'latest_update' => $this->latestUpdate($application, $workflow),
            'record' => [
                ['label' => 'Submitted', 'value' => $application->submitted_at?->format('M d, Y h:i A') ?? 'Not recorded'],
                ['label' => 'Program deadline', 'value' => $scholarship?->deadline?->format('M d, Y') ?? 'Not listed'],
                ['label' => 'Review method', 'value' => $this->applicationModeLabel($scholarship?->application_mode)],
                ['label' => 'Submission version', 'value' => 'Version '.max(1, (int) data_get($application->submission_snapshot, 'version', 1))],
            ],
            'readiness' => [
                'required_files' => collect($documents)->where('required', true)->count(),
                'files_needing_attention' => collect($documents)->where('needs_attention', true)->count(),
                'provider_questions' => count($application->application_answers ?? []),
            ],
            'links' => [
                'scholarship' => route('dashboard.scholarships.show', $application->scholarship, false),
                'provider' => $application->scholarship?->provider
                    ? route('dashboard.providers.show', $application->scholarship->provider, false)
                    : null,
            ],
        ];
    }

    private function submission(
        ScholarshipApplication $application,
        array $workflow,
        array $documents,
    ): array {
        $snapshot = data_get($application->submission_snapshot, 'current.applicant', []);
        $canEdit = ! ($workflow['is_closed'] ?? false)
            && ($application->correction_status === 'requested'
                || in_array($application->status, ['submitted', 'under_review', 'qualified', 'shortlisted'], true));

        return [
            'profile_snapshot' => collect([
                ['group' => 'Contact', 'label' => 'Contact number', 'value' => data_get($snapshot, 'contact_number')],
                ['group' => 'Education', 'label' => 'Education level', 'value' => data_get($snapshot, 'education_level')],
                ['group' => 'Education', 'label' => 'School', 'value' => data_get($snapshot, 'school')],
                ['group' => 'Education', 'label' => 'Course or strand', 'value' => data_get($snapshot, 'course_or_strand')],
                ['group' => 'Education', 'label' => 'Year level', 'value' => data_get($snapshot, 'year_level')],
                ['group' => 'Education', 'label' => 'Academic period', 'value' => collect([
                    data_get($snapshot, 'academic_term'),
                    data_get($snapshot, 'academic_year'),
                ])->filter()->implode(' - ')],
                ['group' => 'Education', 'label' => 'Academic result', 'value' => $this->academicResult($snapshot)],
                ['group' => 'Household', 'label' => 'Income bracket', 'value' => data_get($snapshot, 'income_bracket')],
                ['group' => 'Household', 'label' => 'Household size', 'value' => data_get($snapshot, 'household_size')],
            ])->filter(fn (array $row): bool => filled($row['value']))->values()->all(),
            'questions' => collect($application->application_answers ?? [])->map(fn (array $answer): array => [
                'prompt' => $answer['prompt'] ?? 'Provider question',
                'answer' => $answer['answer'] ?? '',
                'required' => (bool) ($answer['required'] ?? false),
            ])->values()->all(),
            'documents' => $documents,
            'document_summary' => $this->eligibilityService->applicationDocumentReadiness($application),
            'correction' => [
                'status' => $application->correction_status,
                'message' => $application->correction_message,
                'targets' => collect($application->correction_targets ?? [])->map(fn (string $target): array => [
                    'key' => $target,
                    'label' => $this->correctionTargetLabel($target),
                ])->values()->all(),
                'response' => $application->correction_response,
                'requested_at' => $application->correction_requested_at?->format('M d, Y h:i A'),
                'responded_at' => $application->correction_responded_at?->format('M d, Y h:i A'),
            ],
            'actions' => [
                'can_edit_files' => $canEdit,
                'upload_endpoint' => route('dashboard.applications.documents.store', $application, false),
                'correction_endpoint' => route('dashboard.applications.correction-response', $application, false),
                'profile_href' => route('dashboard.profile', absolute: false),
                'upload_terms_version' => Terms::VERSION,
            ],
        ];
    }

    private function activityContent(
        ScholarshipApplication $application,
        array $workflow,
        array $activities,
    ): array {
        $active = collect($activities)->filter(
            fn (array $activity): bool => in_array($activity['status'], ['scheduled', 'ongoing'], true),
        )->values();

        return [
            'active' => $active->all(),
            'past' => collect($activities)->reject(
                fn (array $activity): bool => in_array($activity['status'], ['scheduled', 'ongoing'], true),
            )->values()->all(),
            'formal_application' => $this->formalApplicationHandoff($application, $workflow),
            'empty_state' => $active->isEmpty()
                ? [
                    'title' => $this->activityEmptyTitle($workflow),
                    'description' => $this->activityEmptyDescription($workflow),
                ]
                : null,
        ];
    }

    private function outcome(ScholarshipApplication $application, ?array $agreement): array
    {
        $outcome = $application->final_outcome ?: match (true) {
            $application->status === 'rejected' => 'not_qualified',
            $application->application_state === 'withdrawn' => 'withdrawn',
            default => null,
        };
        $selected = $outcome === 'selected';
        $recordedAt = match ($outcome) {
            'waitlisted' => $application->waitlisted_at ?: $application->reviewed_at,
            'withdrawn' => $application->statusHistories->sortByDesc('changed_at')->first()?->changed_at,
            default => $application->outcome_at ?: $application->reviewed_at,
        };

        return [
            'available' => filled($outcome),
            'result' => [
                'key' => $outcome,
                'label' => $this->outcomeLabel($outcome),
                'tone' => match ($outcome) {
                    'selected' => 'positive',
                    'not_selected', 'not_qualified' => 'negative',
                    'waitlisted' => 'attention',
                    default => 'neutral',
                },
                'recorded_at' => $recordedAt?->format('M d, Y'),
                'feedback' => $application->outcome_notes,
                'waitlist_position' => $application->waitlist_position,
                'award' => $selected ? $this->awardSummary($application) : null,
            ],
            'agreement' => $agreement,
            'response_endpoint' => $agreement
                ? route('dashboard.applications.response', $application, false)
                : null,
            'recipient_support' => $selected && $application->student_response_status === 'accepted'
                ? [
                    'title' => 'Recipient support',
                    'description' => 'Monitoring and benefit releases continue in a separate workspace.',
                    'href' => route('dashboard.monitoring.show', $application, false),
                ]
                : null,
            'pending' => blank($outcome)
                ? [
                    'title' => 'No final result yet',
                    'description' => 'The provider will publish the recorded result after the configured selection stages are complete.',
                ]
                : null,
        ];
    }

    private function history(ScholarshipApplication $application, array $workflow): array
    {
        $events = collect();
        $eventSequence = 0;
        $addEvent = function (
            string $key,
            string $category,
            string $title,
            string $description,
            string $actor,
            mixed $occurredAt,
            string $tone = 'neutral',
            array $details = [],
        ) use ($events, &$eventSequence): void {
            if (! $occurredAt) {
                return;
            }

            $events->push([
                'key' => $key,
                'category' => $category,
                'category_label' => $this->historyCategoryLabel($category),
                'icon' => $this->historyIcon($category),
                'title' => $title,
                'description' => $description,
                'actor_label' => $actor,
                'occurred_at' => $occurredAt->toISOString(),
                'occurred_label' => $occurredAt->format('M d, Y h:i A'),
                'tone' => $tone,
                '_sequence' => ++$eventSequence,
                'details' => collect($details)
                    ->filter(fn (array $detail): bool => filled($detail['value'] ?? null))
                    ->values()
                    ->all(),
            ]);
        };

        $addEvent(
            'submitted',
            'submission',
            'Application submitted',
            'Your application record was sent to the scholarship provider.',
            'You',
            $application->submitted_at,
            'neutral',
            [['label' => 'Recorded status', 'value' => 'Submitted']],
        );

        $application->stageProgresses
            ->whereIn('status', ['passed', 'not_passed'])
            ->where('stage_key', '!=', 'decision')
            ->sortBy('position')
            ->each(function ($progress) use ($addEvent): void {
                $passed = $progress->status === 'passed';
                $label = $this->stageLabel($progress->stage_key);

                $addEvent(
                    'stage-'.$progress->stage_key,
                    'stage',
                    $passed ? $label.' completed' : $label.' not passed',
                    $passed
                        ? 'The provider completed this stage and moved the application forward.'
                        : 'The provider recorded that the application did not pass this stage.',
                    'Scholarship provider',
                    $progress->completed_at,
                    $passed ? 'positive' : 'negative',
                    [
                        ['label' => 'Stage', 'value' => $label],
                        ['label' => 'Recorded result', 'value' => $passed ? 'Passed' : 'Not passed'],
                    ],
                );
            });

        $failedProviderStage = $application->stageProgresses
            ->where('stage_key', '!=', 'decision')
            ->firstWhere('status', 'not_passed');
        $stageFailureAlreadyExplainsOutcome = $application->final_outcome === 'not_selected'
            && $failedProviderStage?->completed_at
            && $application->outcome_at
            && $failedProviderStage->completed_at->equalTo($application->outcome_at);

        if (filled($application->final_outcome) && ! $stageFailureAlreadyExplainsOutcome) {
            $outcomeLabel = $this->outcomeLabel($application->final_outcome);
            $addEvent(
                'final-outcome',
                'outcome',
                match ($application->final_outcome) {
                    'selected' => 'Selected for scholarship support',
                    'waitlisted' => 'Added to the waitlist',
                    default => 'Final result recorded',
                },
                match ($application->final_outcome) {
                    'selected' => 'The provider selected this application for scholarship support.',
                    'waitlisted' => 'The application remains under consideration if support becomes available.',
                    default => 'The provider completed the selection process for this application.',
                },
                'Scholarship provider',
                $application->final_outcome === 'waitlisted'
                    ? ($application->waitlisted_at ?: $application->reviewed_at)
                    : ($application->outcome_at ?: $application->reviewed_at),
                $application->final_outcome === 'selected'
                    ? 'positive'
                    : ($application->final_outcome === 'waitlisted' ? 'attention' : 'negative'),
                [
                    ['label' => 'Result', 'value' => $outcomeLabel],
                    ['label' => 'Waitlist position', 'value' => $application->waitlist_position],
                ],
            );
        }

        $application->statusHistories
            ->reject(fn (ApplicationStatusHistory $history): bool => $this->historyStatusIsRepresentedElsewhere($history, $application))
            ->each(function (ApplicationStatusHistory $history) use ($addEvent, $application): void {
                $addEvent(
                    'status-'.$history->id,
                    'update',
                    $this->statusHistoryTitle($history),
                    $this->statusHistoryDescription($history),
                    $this->actorLabel($history, $application->applicant_id),
                    $history->changed_at,
                    $this->historyTone($history->to_status),
                    [['label' => 'Recorded status', 'value' => $this->label($history->to_status)]],
                );
            });

        if ($application->correction_requested_at) {
            $addEvent(
                'correction-requested',
                'correction',
                'Application update requested',
                'The provider asked you to update part of the submitted record.',
                'Scholarship provider',
                $application->correction_requested_at,
                'attention',
                [['label' => 'Request status', 'value' => 'Waiting for applicant update']],
            );
        }

        if ($application->correction_responded_at) {
            $addEvent(
                'correction-submitted',
                'correction',
                'Updated record submitted',
                'Your response was sent to the provider for another review.',
                'You',
                $application->correction_responded_at,
                'neutral',
                [['label' => 'Request status', 'value' => 'Update submitted']],
            );
        }

        if ($application->correction_resolved_at) {
            $addEvent(
                'correction-resolved',
                'correction',
                'Requested update reviewed',
                'The provider completed its review of your updated record.',
                'Scholarship provider',
                $application->correction_resolved_at,
                'positive',
                [['label' => 'Request status', 'value' => 'Resolved']],
            );
        }

        if ($application->student_responded_at) {
            $accepted = $application->student_response_status === 'accepted';
            $addEvent(
                'agreement-response',
                'agreement',
                $accepted ? 'Recipient agreement accepted' : 'Recipient agreement declined',
                'Your response to the recorded scholarship terms was saved.',
                'You',
                $application->student_responded_at,
                $accepted ? 'positive' : 'negative',
                [['label' => 'Response', 'value' => $accepted ? 'Accepted' : 'Declined']],
            );
        }

        $events = $events
            ->sort(function (array $left, array $right): int {
                $dateOrder = strcmp($right['occurred_at'], $left['occurred_at']);

                return $dateOrder !== 0
                    ? $dateOrder
                    : $right['_sequence'] <=> $left['_sequence'];
            })
            ->map(function (array $event): array {
                unset($event['_sequence']);

                return $event;
            })
            ->values();

        return [
            'summary' => [
                'event_count' => $events->count(),
                'stage_count' => $events->where('category', 'stage')->count(),
                'latest_title' => $events->first()['title'] ?? 'Application submitted',
                'latest_at' => $events->first()['occurred_label'] ?? $application->submitted_at?->format('M d, Y h:i A'),
            ],
            'events' => $events->all(),
        ];
    }

    private function documentRows(ScholarshipApplication $application, array $workflow): array
    {
        $required = collect($application->document_checklist ?? []);
        $optional = collect($application->optional_document_checklist ?? []);
        $documents = $application->documents->sortByDesc('id');
        $knownNames = $required->merge($optional)->filter()->unique(fn (string $name): string => $this->normalize($name));
        $rows = $knownNames->map(function (string $name) use ($required, $documents, $application, $workflow): array {
            $document = $documents->first(
                fn (ApplicationDocument $file): bool => $this->normalize($file->document_name) === $this->normalize($name),
            );

            return $this->documentRow(
                $application,
                $workflow,
                $name,
                $document,
                $required->contains(fn (string $requiredName): bool => $this->normalize($requiredName) === $this->normalize($name)),
            );
        });

        $extraRows = $documents
            ->reject(fn (ApplicationDocument $document): bool => $knownNames->contains(
                fn (string $name): bool => $this->normalize($name) === $this->normalize($document->document_name),
            ))
            ->map(fn (ApplicationDocument $document): array => $this->documentRow(
                $application,
                $workflow,
                $document->document_name,
                $document,
                false,
            ));

        return $rows->concat($extraRows)->values()->all();
    }

    private function documentRow(
        ScholarshipApplication $application,
        array $workflow,
        string $name,
        ?ApplicationDocument $document,
        bool $required,
    ): array {
        $status = $document?->status ?? 'missing';
        $needsAttention = in_array($status, ['rejected', 'needs_replacement'], true);
        $canReplace = ! ($workflow['is_closed'] ?? false)
            && ($application->correction_status === 'requested'
                || in_array($application->status, ['submitted', 'under_review', 'qualified', 'shortlisted'], true)
                || $needsAttention);

        return [
            'requirement' => $name,
            'required' => $required,
            'status' => $status,
            'status_label' => match ($status) {
                'accepted' => 'Accepted',
                'rejected', 'needs_replacement' => 'Replacement needed',
                'pending' => 'Waiting for review',
                default => 'Not uploaded',
            },
            'needs_attention' => $needsAttention || ($required && ! $document),
            'can_replace' => $canReplace,
            'feedback' => $document?->review_notes,
            'file' => $document ? [
                'id' => $document->id,
                'name' => $document->original_name,
                'size' => $document->size,
                'uploaded_at' => $document->uploaded_at?->format('M d, Y h:i A'),
                'reviewed_at' => $document->reviewed_at?->format('M d, Y h:i A'),
                'view_url' => route('documents.view', $document, false),
                'download_url' => route('documents.download', $document, false),
                'delete_url' => route('dashboard.documents.destroy', $document, false),
            ] : null,
        ];
    }

    private function activities(ScholarshipApplication $application): array
    {
        $assigned = $application->stageAssignments
            ->sortBy(fn ($assignment) => $assignment->session?->starts_at?->getTimestamp() ?? PHP_INT_MAX)
            ->map(function ($assignment) use ($application): ?array {
                $payload = ApplicantStageActivityPayload::make($assignment);

                if (! $payload) {
                    return null;
                }

                return $this->activityRow(
                    $payload,
                    route('dashboard.applications.stage-activities.acknowledge', [
                        'application' => $application,
                        'assignment' => $assignment,
                    ], false),
                );
            })
            ->filter();

        $legacy = $application->schedules
            ->sortBy('scheduled_at')
            ->map(fn ($schedule): array => $this->activityRow(
                ['source' => 'legacy_schedule', ...ApplicationSchedulePayload::make($schedule)],
                route('dashboard.applications.schedules.acknowledge', [
                    'application' => $application,
                    'schedule' => $schedule,
                ], false),
            ));

        return $assigned
            ->concat($legacy)
            ->unique(fn (array $activity): string => implode('|', [
                $activity['type'],
                $activity['title'],
                $activity['scheduled_at'],
            ]))
            ->values()
            ->all();
    }

    private function activityRow(array $payload, string $acknowledgeEndpoint): array
    {
        $status = (string) ($payload['status'] ?? 'scheduled');
        $active = in_array($status, ['scheduled', 'ongoing'], true);

        return [
            'id' => $payload['id'] ?? null,
            'source' => $payload['source'] ?? 'stage_session',
            'type' => $payload['type'] ?? 'activity',
            'type_label' => $this->stageLabel($payload['type'] ?? 'activity'),
            'title' => $payload['title'] ?? 'Provider activity',
            'scheduled_at' => $payload['scheduled_at'] ?? null,
            'scheduled_label' => $payload['scheduled_label'] ?? 'Date to be announced',
            'ends_at' => $payload['ends_at'] ?? null,
            'ends_label' => $payload['ends_label'] ?? null,
            'timezone' => $payload['timezone'] ?? 'Asia/Manila',
            'mode' => $payload['mode'] ?? null,
            'mode_label' => $this->modeLabel($payload['mode'] ?? null),
            'venue' => $payload['venue'] ?? null,
            'location_address' => $payload['location_address'] ?? null,
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'map_url' => $payload['map_url'] ?? null,
            'online_url' => $active ? ($payload['online_url'] ?? null) : null,
            'instructions' => $payload['instructions'] ?? null,
            'status' => $status,
            'status_label' => $this->activityStatusLabel($status),
            'attendance_status' => $payload['attendance_status'] ?? null,
            'requires_applicant_acknowledgment' => (bool) ($payload['requires_applicant_acknowledgment'] ?? false),
            'applicant_acknowledged' => (bool) ($payload['applicant_acknowledged'] ?? false),
            'applicant_acknowledged_at' => $payload['applicant_acknowledged_at'] ?? null,
            'acknowledge_endpoint' => $acknowledgeEndpoint,
        ];
    }

    private function formalApplicationHandoff(ScholarshipApplication $application, array $workflow): ?array
    {
        $formalStep = collect($workflow['steps'] ?? [])->firstWhere('key', 'formal_application');

        if (! $formalStep || ! in_array($formalStep['status'], ['current', 'passed'], true)) {
            return null;
        }

        $scholarship = $application->scholarship;
        $mapQuery = $scholarship?->handoff_location_address ?: $scholarship?->handoff_location_name;

        return [
            'requirements' => $this->eligibilityService->splitDocumentRequirements($scholarship?->post_qualification_requirements),
            'mode' => $scholarship?->handoff_mode ?: 'provider_contact',
            'instructions' => $scholarship?->handoff_instructions,
            'deadline' => $scholarship?->handoff_deadline?->format('M d, Y'),
            'location_name' => $scholarship?->handoff_location_name,
            'location_address' => $scholarship?->handoff_location_address,
            'map_url' => filled($mapQuery)
                ? 'https://www.openstreetmap.org/search?query='.rawurlencode($mapQuery)
                : null,
            'url' => $scholarship?->handoff_url,
            'contact' => [
                'name' => $scholarship?->contact_person,
                'department' => $scholarship?->contact_department,
                'email' => $scholarship?->contact_email,
                'number' => $scholarship?->contact_number,
            ],
            'notice' => 'This step continues the provider application process. It is not a final scholarship award.',
        ];
    }

    private function latestUpdate(ScholarshipApplication $application, array $workflow): array
    {
        if ($application->correction_status === 'requested') {
            return [
                'title' => 'Provider requested an update',
                'description' => $application->correction_message,
                'occurred_at' => $application->correction_requested_at?->format('M d, Y h:i A'),
                'tone' => 'attention',
            ];
        }

        if (filled($application->final_outcome)) {
            return [
                'title' => 'Final result recorded',
                'description' => $this->outcomeLabel($application->final_outcome),
                'occurred_at' => $application->outcome_at?->format('M d, Y'),
                'tone' => $application->final_outcome === 'selected' ? 'positive' : 'neutral',
            ];
        }

        $latest = $application->statusHistories->sortByDesc('changed_at')->first();

        return $latest
            ? [
                'title' => $this->statusHistoryTitle($latest),
                'description' => $this->statusHistoryDescription($latest),
                'occurred_at' => $latest->changed_at?->format('M d, Y h:i A'),
                'tone' => $this->historyTone($latest->to_status),
            ]
            : [
                'title' => 'Application received',
                'description' => $workflow['next_action']['description'] ?? 'The provider can now review the submitted record.',
                'occurred_at' => $application->submitted_at?->format('M d, Y h:i A'),
                'tone' => 'neutral',
            ];
    }

    private function navigation(
        ScholarshipApplication $application,
        string $activeView,
        array $documents,
        array $activities,
        ?array $agreement,
    ): array {
        $counts = [
            'submission' => collect($documents)->where('needs_attention', true)->count(),
            'activities' => collect($activities)->where('requires_applicant_acknowledgment', true)->count(),
            'outcome' => ($agreement['can_respond'] ?? false) ? 1 : 0,
        ];

        return collect([
            ['key' => 'summary', 'label' => 'Overview', 'icon' => 'fa-solid fa-compass'],
            ['key' => 'submission', 'label' => 'Submission', 'icon' => 'fa-solid fa-folder-open'],
            ['key' => 'activities', 'label' => 'Activities', 'icon' => 'fa-regular fa-calendar'],
            ['key' => 'outcome', 'label' => 'Outcome', 'icon' => 'fa-solid fa-award'],
            ['key' => 'history', 'label' => 'History', 'icon' => 'fa-solid fa-clock-rotate-left'],
        ])->map(fn (array $item): array => [
            ...$item,
            'href' => $this->viewUrl($application, $item['key']),
            'active' => $item['key'] === $activeView,
            'count' => $counts[$item['key']] ?? 0,
        ])->all();
    }

    private function page(string $view): array
    {
        return match ($view) {
            'submission' => [
                'eyebrow' => 'Submitted record',
                'title' => 'What you sent',
                'description' => 'Review the saved profile details, answers, and application files.',
            ],
            'activities' => [
                'eyebrow' => 'Provider activities',
                'title' => 'Schedules and instructions',
                'description' => 'See formal application, exam, and interview details published for you.',
            ],
            'outcome' => [
                'eyebrow' => 'Application outcome',
                'title' => 'Result and next handoff',
                'description' => 'Review the recorded result, provider feedback, and recipient terms.',
            ],
            'history' => [
                'eyebrow' => 'Application history',
                'title' => 'How the record changed',
                'description' => 'Follow the important application events in chronological order.',
            ],
            default => [
                'eyebrow' => 'Application record',
                'title' => 'Current step and next action',
                'description' => 'Start with what is happening now, then open only the details you need.',
            ],
        };
    }

    private function viewUrl(ScholarshipApplication $application, string $view): string
    {
        return $view === 'summary'
            ? route('dashboard.applications.show', $application, false)
            : route('dashboard.applications.show.section', [
                'application' => $application,
                'section' => $view,
            ], false);
    }

    private function actionView(ScholarshipApplication $application, array $action): string
    {
        if (($application->application_state ?? null) === 'closed') {
            return 'outcome';
        }

        return match ($action['key'] ?? null) {
            'formal_application', 'exam', 'interview' => 'activities',
            'result', 'decision' => 'outcome',
            'correction' => 'submission',
            default => 'summary',
        };
    }

    private function awardSummary(ScholarshipApplication $application): array
    {
        $amount = $application->awarded_amount ?? $application->scholarship?->award_amount;

        return [
            'amount' => $amount !== null ? (float) $amount : null,
            'amount_label' => $amount !== null ? 'PHP '.number_format((float) $amount, 2) : null,
            'support_period' => collect([
                $application->scholarship?->support_starts_at?->format('M d, Y'),
                $application->scholarship?->support_ends_at?->format('M d, Y'),
            ])->filter()->implode(' to ') ?: null,
            'benefits' => $application->scholarship?->benefitPayload() ?? [],
        ];
    }

    private function academicResult(array $snapshot): ?string
    {
        $grade = data_get($snapshot, 'gwa');
        $scale = data_get($snapshot, 'grading_scale');

        return filled($grade)
            ? collect([$grade, $scale])->filter()->implode(' - ')
            : null;
    }

    private function statusTone(ScholarshipApplication $application, array $workflow): string
    {
        return match (true) {
            $application->final_outcome === 'selected' => 'positive',
            $application->final_outcome === 'not_selected' => 'negative',
            $application->application_state === 'withdrawn' => 'muted',
            $application->correction_status === 'requested' => 'attention',
            ($workflow['is_closed'] ?? false) => 'neutral',
            default => 'active',
        };
    }

    private function outcomeLabel(?string $outcome): string
    {
        return match ($outcome) {
            'selected' => 'Selected',
            'waitlisted' => 'Waitlisted',
            'not_selected' => 'Not selected',
            'not_qualified' => 'Not qualified',
            'withdrawn' => 'Withdrawn',
            default => 'Result pending',
        };
    }

    private function resultLabel(?string $result): ?string
    {
        return match ($result) {
            'passed' => 'Passed',
            'not_passed' => 'Not passed',
            'selected' => 'Selected',
            'waitlisted' => 'Waitlisted',
            'not_selected' => 'Not selected',
            default => null,
        };
    }

    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            'screening' => 'Pre-screening',
            'formal_application' => 'Formal application',
            'exam' => 'Exam',
            'interview' => 'Interview',
            'decision' => 'Final decision',
            default => Str::headline($stage),
        };
    }

    private function applicationModeLabel(?string $mode): string
    {
        return match ($mode) {
            'online' => 'Online application',
            'onsite' => 'Onsite verification',
            'hybrid' => 'Online with onsite verification',
            'provider_review' => 'Profile review',
            default => 'Provider review',
        };
    }

    private function modeLabel(?string $mode): ?string
    {
        return match ($mode) {
            'online' => 'Online',
            'onsite' => 'In person',
            'hybrid' => 'Hybrid',
            default => filled($mode) ? Str::headline($mode) : null,
        };
    }

    private function activityStatusLabel(string $status): string
    {
        return match ($status) {
            'scheduled' => 'Scheduled',
            'ongoing' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'rescheduled' => 'Rescheduled',
            default => Str::headline($status),
        };
    }

    private function correctionTargetLabel(string $target): string
    {
        return match ($target) {
            'profile' => 'Profile information',
            'academic_record' => 'Academic record',
            'application_files' => 'Application files',
            default => Str::headline($target),
        };
    }

    private function activityEmptyTitle(array $workflow): string
    {
        return match ($workflow['current_stage'] ?? null) {
            'formal_application' => 'Provider instructions are not published yet',
            'exam' => 'The exam schedule is not published yet',
            'interview' => 'The interview schedule is not published yet',
            default => 'No active provider activity',
        };
    }

    private function activityEmptyDescription(array $workflow): string
    {
        return in_array($workflow['current_stage'] ?? null, ['formal_application', 'exam', 'interview'], true)
            ? 'The provider will add the date, method, and instructions when the activity is ready.'
            : 'Formal application, exam, or interview details will appear here when they apply to this program.';
    }

    private function statusHistoryTitle(ApplicationStatusHistory $history): string
    {
        if ($history->decision_reason === 'decision_reopened') {
            return 'Application review reopened';
        }

        if ($history->decision_reason === 'waitlist_restored') {
            return 'Returned to final decision review';
        }

        if ($history->to_status === 'withdrawn') {
            return 'Application withdrawn';
        }

        return 'Status changed to '.$this->label($history->to_status);
    }

    private function statusHistoryDescription(ApplicationStatusHistory $history): string
    {
        if ($history->decision_reason === 'decision_reopened') {
            return 'The provider returned the latest completed stage for another review.';
        }

        if ($history->decision_reason === 'waitlist_restored') {
            return 'The provider returned the application from the waitlist to final review.';
        }

        return match ($history->to_status) {
            'rejected', 'exam_failed', 'interview_failed', 'not_awarded' => 'The provider recorded an unsuccessful result for this stage.',
            'awarded' => 'The provider recorded a successful final selection.',
            'waitlisted' => 'The application remains under consideration for an available award slot.',
            'withdrawn' => 'The application was withdrawn and is no longer under review.',
            default => 'The application moved to the next recorded status.',
        };
    }

    private function historyStatusIsRepresentedElsewhere(
        ApplicationStatusHistory $history,
        ScholarshipApplication $application,
    ): bool {
        if (in_array($history->decision_reason, ['decision_reopened', 'waitlist_restored', 'applicant_withdrawal'], true)) {
            return false;
        }

        if (str_starts_with((string) $history->to_status, 'agreement_')) {
            return true;
        }

        if ($history->changed_at && $application->stageProgresses->contains(
            fn ($progress): bool => $progress->completed_at?->equalTo($history->changed_at) ?? false,
        )) {
            return true;
        }

        $outcomeAt = $application->final_outcome === 'waitlisted'
            ? $application->waitlisted_at
            : $application->outcome_at;

        if ($history->changed_at && $outcomeAt?->equalTo($history->changed_at)) {
            return true;
        }

        return in_array($history->decision_reason, [
            'passed_prescreening',
            'outside_eligibility',
            'formal_application_completed',
            'formal_application_not_completed',
            'passed_exam',
            'failed_exam',
            'passed_interview',
            'failed_interview',
            'approved_for_award',
            'funds_limited',
            'not_selected',
        ], true)
            || ($history->from_status === null && $history->to_status === 'submitted');
    }

    private function historyCategoryLabel(string $category): string
    {
        return match ($category) {
            'submission' => 'Submission',
            'stage' => 'Selection stage',
            'correction' => 'Application update',
            'outcome' => 'Final outcome',
            'agreement' => 'Recipient agreement',
            default => 'Application update',
        };
    }

    private function historyIcon(string $category): string
    {
        return match ($category) {
            'submission' => 'fa-solid fa-paper-plane',
            'stage' => 'fa-solid fa-list-check',
            'correction' => 'fa-solid fa-pen-to-square',
            'outcome' => 'fa-solid fa-award',
            'agreement' => 'fa-solid fa-file-signature',
            default => 'fa-solid fa-clock-rotate-left',
        };
    }

    private function actorLabel(ApplicationStatusHistory $history, ?int $applicantId): string
    {
        if ((int) $history->changed_by === (int) $applicantId) {
            return 'You';
        }

        return match ($history->actor?->role) {
            'provider' => 'Scholarship provider',
            'admin' => 'Platform administrator',
            default => 'System',
        };
    }

    private function historyTone(?string $status): string
    {
        return match ($status) {
            'awarded', 'disbursed', 'renewed', 'exam_passed' => 'positive',
            'rejected', 'exam_failed', 'interview_failed', 'not_awarded' => 'negative',
            'waitlisted' => 'attention',
            default => 'neutral',
        };
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }

    private function label(?string $value): string
    {
        return filled($value) ? Str::headline($value) : 'Application review';
    }

    private function isoDate(?string $date): ?string
    {
        return filled($date) ? date(DATE_ATOM, strtotime($date)) : null;
    }

    private function publicAssetUrl(?string $path, ?string $fallback = null): ?string
    {
        $value = filled($path) ? $path : $fallback;

        if (blank($value)) {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        return '/'.ltrim($value, '/');
    }
}
