<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use App\Models\ApplicationSchedule;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipAnnouncement;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use App\Rules\PhoneNumber;
use App\Services\ScholarshipBenefitService as SB;
use App\Services\ScholarshipEventService;
use App\Support\AcademicRequirement;
use App\Support\LearnerProgramPath;
use App\Support\ReviewRubric;
use App\Support\ScholarshipEligibilityCondition;
use App\Support\ScholarshipEventPayload;
use App\Support\ScholarshipSelectionPlan;
use App\Support\Terms;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

trait HandlesProviderProgramManagement
{
    public function scholarships(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $scholarships = $this->providerScholarshipsQuery($request->user())
            ->with('events')
            ->withCount($this->providerProgramCountRelations())
            ->latest()
            ->get();

        return response()->json([
            'scholarships' => $scholarships->map(fn (Scholarship $scholarship) => $this->scholarshipPayload($scholarship))->values(),
        ]);
    }

    public function storeScholarshipAnnouncement(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'audience' => ['required', Rule::in([
                'active_applicants',
                'under_review',
                'qualified_applicants',
                'selected_recipients',
            ])],
            'title' => ['required', 'string', 'min:5', 'max:120'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $recipientQuery = $scholarship->applications()
            ->with('applicant')
            ->whereHas('applicant', fn ($query) => $query
                ->where('role', 'applicant')
                ->where('account_status', 'active'));

        match ($validated['audience']) {
            'active_applicants' => $recipientQuery->whereNotIn('status', [
                'withdrawn',
                'rejected',
                'not_awarded',
                'exam_failed',
                'interview_failed',
                'disbursed',
                'renewed',
                'benefits_terminated',
            ]),
            'under_review' => $recipientQuery->whereIn('status', [
                'submitted',
                'under_review',
                'qualified',
                'shortlisted',
                'exam_qualified',
                'exam_scheduled',
                'exam_taken',
                'exam_passed',
                'interview',
            ]),
            'qualified_applicants' => $recipientQuery->whereIn('status', ['approved', 'waitlisted']),
            'selected_recipients' => $recipientQuery->whereIn('status', self::AWARD_SLOT_STATUSES),
        };

        $recipients = $recipientQuery->get()->unique('applicant_id')->values();

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages([
                'audience' => 'No applicants currently match this audience.',
            ]);
        }

        $announcement = DB::transaction(function () use ($request, $scholarship, $validated, $recipients): ScholarshipAnnouncement {
            $announcement = $scholarship->announcements()->create([
                ...$validated,
                'recipient_count' => $recipients->count(),
                'published_by' => $request->user()->id,
                'published_at' => now(),
            ]);

            foreach ($recipients as $application) {
                PortalNotification::create([
                    'user_id' => $application->applicant_id,
                    'type' => 'program_announcement',
                    'title' => $validated['title'],
                    'message' => "{$scholarship->title}: {$validated['message']}",
                    'action_url' => route('dashboard.applications.show', $application, false),
                    'deduplication_key' => "program-announcement:{$announcement->id}:user:{$application->applicant_id}",
                ]);
            }

            return $announcement;
        });

        ActivityLog::record(
            $request->user(),
            'program_announcement_published',
            "{$request->user()->name} published an announcement for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'announcement_id' => $announcement->id,
                'audience' => $validated['audience'],
                'recipient_count' => $recipients->count(),
            ],
        );

        return response()->json([
            'message' => "Announcement sent to {$recipients->count()} applicant".($recipients->count() === 1 ? '.' : 's.'),
            'announcement' => $this->scholarshipAnnouncementPayload($announcement->load('publisher')),
        ], 201);
    }

    public function showScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $scholarship->load(['announcements.publisher', 'events']);
        $providerOwner = $request->user()->providerOrganizationOwner()->loadMissing('providerProfile');
        $canAccessApplicantWorkflow = $request->user()->hasAnyPortalPermission([
            'verify_applications',
            'manage_selection_activities',
            'record_final_decisions',
        ])
            && $providerOwner->hasVerifiedEmail()
            && $providerOwner->providerProfile?->isVerified();
        $applicationsBase = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id);
        $workflowCounts = $canAccessApplicantWorkflow
            ? $this->providerApplicationFilterCounts($applicationsBase)
            : [];
        $activityStatuses = collect();

        if ($canAccessApplicantWorkflow) {
            $stageCounts = (clone $applicationsBase)
                ->selectRaw("COALESCE(workflow_stage, 'screening') as workflow_stage, count(*) as total")
                ->whereNotIn('application_state', ['closed', 'withdrawn'])
                ->groupByRaw("COALESCE(workflow_stage, 'screening')")
                ->pluck('total', 'workflow_stage');
            $activityWaitingCounts = $this->providerActivityWaitingCounts($applicationsBase);
            $activityStatuses = collect(ScholarshipSelectionPlan::normalize($scholarship->selection_stages))
                ->filter(fn (string $stage): bool => ScholarshipSelectionPlan::isSchedulable($stage))
                ->map(function (string $stage) use ($scholarship, $stageCounts, $activityWaitingCounts): array {
                    $event = $scholarship->events->firstWhere('type', $stage);

                    return [
                        'type' => $stage,
                        'label' => ScholarshipSelectionPlan::label($stage),
                        'active_applicants' => (int) ($stageCounts[$stage] ?? 0),
                        'waiting_applicants' => (int) ($activityWaitingCounts[$stage] ?? 0),
                        'event' => $event ? ScholarshipEventPayload::make($event) : null,
                    ];
                })
                ->values();
        }

        return response()->json([
            'scholarship' => [
                ...$this->scholarshipPayload(
                    $scholarship->loadCount($this->providerProgramCountRelations()),
                ),
                'announcements' => $scholarship->announcements
                    ->map(fn (ScholarshipAnnouncement $announcement) => $this->scholarshipAnnouncementPayload($announcement))
                    ->values(),
                'workflow_counts' => [
                    'needs_review' => (int) ($workflowCounts['needs_review'] ?? 0),
                    'waiting_activity' => (int) ($workflowCounts['waiting_activity'] ?? 0),
                    'ready_result' => (int) ($workflowCounts['ready_result'] ?? 0),
                    'final_decision' => (int) ($workflowCounts['final_decision'] ?? 0),
                    'all' => (int) ($workflowCounts['all'] ?? 0),
                ],
                'activity_statuses' => $activityStatuses,
            ],
        ]);
    }

    public function duplicateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        $this->ensureProviderCanPost($request);

        $copiedImagePath = $this->copyScholarshipImage($scholarship->image_path);

        try {
            $duplicate = DB::transaction(function () use ($request, $scholarship, $copiedImagePath): Scholarship {
                $duplicate = $scholarship->replicate([
                    'created_at',
                    'updated_at',
                ]);
                $duplicate->title = $this->duplicateScholarshipTitle($request->user()->providerOrganizationId(), $scholarship->title);
                $duplicate->image_path = $copiedImagePath;
                $duplicate->status = 'draft';
                $duplicate->views_count = 0;
                $duplicate->provider_terms_accepted_at = null;
                $duplicate->provider_terms_version = null;
                $duplicate->save();
                app(SB::class)->copy($scholarship, $duplicate);

                return $duplicate;
            });
        } catch (Throwable $error) {
            $this->deleteScholarshipImageIfUnused($copiedImagePath);

            throw $error;
        }

        $duplicate->loadCount('bookmarks');
        $this->includeProgramInStaffScope($request->user(), $duplicate);

        ActivityLog::record(
            $request->user(),
            'scholarship_duplicated',
            "{$request->user()->name} duplicated scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'duplicate_id' => $duplicate->id],
        );

        return response()->json([
            'message' => 'Program duplicated as a draft.',
            'scholarship' => $this->scholarshipPayload($duplicate),
        ], 201);
    }

    public function destroyScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        if ($scholarship->status !== 'draft') {
            throw ValidationException::withMessages([
                'program' => 'Only draft programs can be deleted.',
            ]);
        }

        if ($scholarship->applications()->exists()) {
            throw ValidationException::withMessages([
                'program' => 'This draft has applicant records and cannot be deleted.',
            ]);
        }

        $scholarshipId = $scholarship->id;
        $scholarshipTitle = $scholarship->title;
        $imagePath = $scholarship->image_path;

        DB::transaction(fn () => $scholarship->delete());
        $this->deleteScholarshipImageIfUnused($imagePath);

        ActivityLog::record(
            $request->user(),
            'scholarship_draft_deleted',
            "{$request->user()->name} deleted scholarship draft {$scholarshipTitle}.",
            $request,
            ['scholarship_id' => $scholarshipId],
        );

        return response()->json([
            'message' => 'Draft deleted.',
        ]);
    }

    public function storeScholarship(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);
        $validated = $this->normalizeScholarshipProviderObjectives($validated, $request);
        $validated = $this->normalizeScholarshipRecipientAgreement($validated, $request);
        $validated = $this->normalizeScholarshipAcademicRequirement($validated);
        $validated = $this->normalizeScholarshipProgramPaths($validated);
        $validated = $this->normalizeScholarshipRequirements($validated);
        $validated = $this->normalizeScholarshipEligibilityConditions($validated, $request);
        $validated = $this->normalizeScholarshipReviewRubric($validated, $request);
        $validated = $this->normalizeScholarshipApplicationQuestions($validated, $request);
        $validated = $this->normalizeScholarshipSelectionStages($validated, $request);
        $validated = $this->normalizeScholarshipExamDetails($validated);
        $validated = $this->applyProviderProgramContactDefaults($validated, $request->user());
        [$validated, $benefits] = app(SB::class)->normalize($validated, $request);
        $programEvents = $this->normalizeScholarshipProgramEvents($validated, $request);
        $imagePath = $this->storeScholarshipImage($request);

        if ($imagePath === null && $request->boolean('use_provider_logo')) {
            $imagePath = $this->copyProviderLogoForScholarship($request->user());
        }
        $termsAccepted = $request->boolean('terms_accepted');

        unset($validated['image_file'], $validated['use_provider_logo'], $validated['terms_accepted'], $validated['program_events']);
        $validated['description'] = (string) ($validated['description'] ?? '');
        $validated['status'] = $validated['status'] === 'draft' ? 'draft' : 'pending_review';

        if ($termsAccepted) {
            $validated['provider_terms_accepted_at'] = now();
            $validated['provider_terms_version'] = Terms::VERSION;
        }

        try {
            $this->ensureScholarshipReadyForSubmission($validated, $benefits, null, $imagePath);

            $scholarship = DB::transaction(function () use ($validated, $imagePath, $programEvents, $benefits, $request): Scholarship {
                $scholarship = Scholarship::create([
                    ...$validated,
                    'image_path' => $imagePath,
                    'provider_id' => $request->user()->providerOrganizationId(),
                ]);

                foreach ($programEvents ?? [] as $programEvent) {
                    $this->persistScholarshipEvent($scholarship, $programEvent, $request->user());
                }

                app(SB::class)->sync($scholarship, $benefits);

                return $scholarship;
            });
        } catch (Throwable $error) {
            $this->deleteScholarshipImageIfUnused($imagePath);

            throw $error;
        }

        if ($scholarship->status === 'pending_review') {
            $this->notifyAdminsScholarshipSubmitted($request, $scholarship);
        }

        $this->includeProgramInStaffScope($request->user(), $scholarship);

        ActivityLog::record(
            $request->user(),
            'scholarship_created',
            "{$request->user()->name} created scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => $scholarship->status === 'pending_review'
                ? 'Scholarship submitted for admin review.'
                : 'Scholarship draft saved.',
            'scholarship' => $this->scholarshipPayload($scholarship),
        ], 201);
    }

    public function updateScholarship(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        $this->ensureProviderCanPost($request);

        $validated = $this->validateScholarship($request);
        $validated = $this->normalizeScholarshipProviderObjectives($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipRecipientAgreement($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipAcademicRequirement($validated);
        $validated = $this->normalizeScholarshipProgramPaths($validated);
        $validated = $this->normalizeScholarshipRequirements($validated);
        $validated = $this->normalizeScholarshipEligibilityConditions($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipReviewRubric($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipApplicationQuestions($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipSelectionStages($validated, $request, $scholarship);
        $validated = $this->normalizeScholarshipExamDetails($validated);
        $validated = $this->applyProviderProgramContactDefaults($validated, $request->user(), $scholarship);
        [$validated, $benefits] = app(SB::class)->normalize($validated, $request);
        $programEvents = $this->normalizeScholarshipProgramEvents($validated, $request, $scholarship);
        $benefitsChanged = $benefits !== null && app(SB::class)->changed($scholarship, $benefits);
        $this->ensureScholarshipSelectionPlanIsStable($scholarship, $validated['selection_stages']);
        $oldImagePath = $scholarship->image_path;
        $imagePath = $this->storeScholarshipImage($request);

        if ($imagePath === null && $request->boolean('use_provider_logo')) {
            $imagePath = $this->copyProviderLogoForScholarship($request->user());
        }

        try {
            $this->ensureScholarshipReadyForSubmission(
                $validated,
                $benefits,
                $scholarship,
                $imagePath ?: $scholarship->image_path,
            );
        } catch (Throwable $error) {
            $this->deleteScholarshipImageIfUnused($imagePath);

            throw $error;
        }

        $termsAccepted = $request->boolean('terms_accepted');

        unset($validated['image_file'], $validated['use_provider_logo'], $validated['terms_accepted'], $validated['program_events']);
        $validated['description'] = $request->has('description')
            ? (string) ($validated['description'] ?? '')
            : $scholarship->description;

        if ($termsAccepted) {
            $validated['provider_terms_accepted_at'] = now();
            $validated['provider_terms_version'] = Terms::VERSION;
        }

        if ($imagePath) {
            $validated['image_path'] = $imagePath;
        }

        $validated['status'] = $this->providerScholarshipStatus($scholarship, $validated['status'], $validated, $benefitsChanged);
        try {
            DB::transaction(function () use ($scholarship, $validated, $programEvents, $benefits, $request): void {
                $requestedSlots = array_key_exists('slots_available', $validated)
                    ? $validated['slots_available']
                    : $scholarship->slots_available;
                $requestedApplicationLimit = array_key_exists('application_limit', $validated)
                    ? $validated['application_limit']
                    : $scholarship->application_limit;

                $this->ensureScholarshipAwardCapacity($scholarship, $requestedSlots);
                $this->ensureScholarshipApplicationCapacity($scholarship, $requestedApplicationLimit);
                $scholarship->update($validated);

                if ($programEvents !== null) {
                    $submittedEventTypes = collect($programEvents)->pluck('type')->all();
                    $eventsToDelete = $scholarship->events()
                        ->whereIn('type', ['exam', 'interview', 'distribution']);

                    if ($submittedEventTypes !== []) {
                        $eventsToDelete->whereNotIn('type', $submittedEventTypes);
                    }

                    $this->deleteScholarshipEventsSafely($scholarship, $eventsToDelete->get());
                } else {
                    $eventsToDelete = $scholarship->events()
                        ->whereNotIn('type', ScholarshipSelectionPlan::normalize($scholarship->selection_stages))
                        ->get();

                    $this->deleteScholarshipEventsSafely($scholarship, $eventsToDelete);
                }

                foreach ($programEvents ?? [] as $programEvent) {
                    $this->persistScholarshipEvent($scholarship, $programEvent, $request->user());
                }

                app(SB::class)->sync($scholarship, $benefits);
            });
        } catch (Throwable $error) {
            $this->deleteScholarshipImageIfUnused($imagePath);

            throw $error;
        }

        if ($imagePath && $oldImagePath !== $imagePath) {
            $this->deleteScholarshipImageIfUnused($oldImagePath);
        }

        if ($scholarship->status === 'pending_review') {
            $this->notifyAdminsScholarshipSubmitted($request, $scholarship);
        }

        ActivityLog::record(
            $request->user(),
            'scholarship_updated',
            "{$request->user()->name} updated scholarship {$scholarship->title}.",
            $request,
            ['scholarship_id' => $scholarship->id, 'status' => $scholarship->status],
        );

        return response()->json([
            'message' => match ($scholarship->status) {
                'pending_review' => 'Scholarship submitted for admin review.',
                'closed' => 'Scholarship closed.',
                'published' => 'Published scholarship updated.',
                default => 'Scholarship draft saved.',
            },
            'scholarship' => $this->scholarshipPayload($scholarship->fresh()),
        ]);
    }

    private function ensureScholarshipReadyForSubmission(
        array $validated,
        ?array $benefits,
        ?Scholarship $scholarship = null,
        ?string $imagePath = null,
    ): void {
        if (in_array($validated['status'], ['draft', 'closed'], true)) {
            return;
        }

        $value = static fn (string $field) => array_key_exists($field, $validated)
            ? $validated[$field]
            : $scholarship?->{$field};
        $errors = [];
        $deadline = $value('deadline');
        $applicationOpensAt = $value('application_opens_at');
        $expectedResultsAt = $value('expected_results_at');
        $supportStartsAt = $value('support_starts_at');
        $supportEndsAt = $value('support_ends_at');

        if (filled($applicationOpensAt) && filled($deadline)
            && CarbonImmutable::parse($applicationOpensAt)->startOfDay()->isAfter(CarbonImmutable::parse($deadline)->startOfDay())) {
            $errors['application_opens_at'] = 'The application opening date must be on or before the deadline.';
        }

        if (filled($expectedResultsAt) && filled($deadline)
            && CarbonImmutable::parse($expectedResultsAt)->startOfDay()->isBefore(CarbonImmutable::parse($deadline)->startOfDay())) {
            $errors['expected_results_at'] = 'The expected results date must be on or after the application deadline.';
        }

        if (filled($supportStartsAt) && filled($supportEndsAt)
            && CarbonImmutable::parse($supportEndsAt)->startOfDay()->isBefore(CarbonImmutable::parse($supportStartsAt)->startOfDay())) {
            $errors['support_ends_at'] = 'The support end date must be on or after the support start date.';
        }

        $this->addScholarshipEligibilityConsistencyErrors($errors, $value);

        if (blank($imagePath)) {
            $errors['image_file'] = 'Upload a program logo or reuse the provider logo before submitting for review.';
        }

        // Do not strand an older live program that predates the expanded form.
        // Its unchanged deadline remains editable, while a new invalid value is blocked.
        if ($scholarship?->status === 'published') {
            if (array_key_exists('deadline', $validated)) {
                $incomingDeadline = filled($deadline)
                    ? CarbonImmutable::parse($deadline)->toDateString()
                    : null;
                $existingDeadline = $scholarship->deadline?->toDateString();

                if ($incomingDeadline !== $existingDeadline) {
                    if ($incomingDeadline === null) {
                        $errors['deadline'] = 'Keep an application deadline on a published program.';
                    } elseif (CarbonImmutable::parse($incomingDeadline)->startOfDay()->isBefore(CarbonImmutable::today())) {
                        $errors['deadline'] = 'The application deadline cannot be in the past.';
                    }
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        $agreement = (array) ($value('recipient_agreement') ?? []);
        $commitmentType = $agreement['commitment_type'] ?? 'provider_briefing';

        if ($commitmentType === 'provider_briefing') {
            $errors['recipient_agreement'] = 'Disclose the complete recipient agreement before submitting the program for review.';
        }

        if (blank($agreement['release_conditions'] ?? null)) {
            $errors['recipient_agreement.release_conditions'] = 'Explain the conditions for receiving the scholarship benefits.';
        }

        if ($commitmentType !== 'none') {
            foreach ([
                'responsibilities' => 'State the exact responsibilities the recipient must complete.',
                'required_evidence' => 'List the proof the recipient must submit.',
                'duration' => 'State how long the recipient responsibilities apply.',
                'noncompliance_consequence' => 'Explain what happens when a responsibility is not completed.',
                'exit_or_exception_process' => 'Explain the exception, adjustment, or withdrawal process.',
            ] as $field => $message) {
                if (blank($agreement[$field] ?? null)) {
                    $errors["recipient_agreement.{$field}"] = $message;
                }
            }
        }

        if (blank($deadline)) {
            $errors['deadline'] = 'Add an application deadline before submitting the program for review.';
        } elseif (CarbonImmutable::parse($deadline)->startOfDay()->isBefore(CarbonImmutable::today())) {
            $errors['deadline'] = 'The application deadline cannot be in the past.';
        }

        if (blank($supportStartsAt)) {
            $errors['support_starts_at'] = 'Add the date when recipient support is expected to begin.';
        }

        if (blank($supportEndsAt)) {
            $errors['support_ends_at'] = 'Add the date when recipient support is expected to end.';
        }

        if (blank($value('category'))) {
            $errors['category'] = 'Choose a scholarship category before submitting for review.';
        }

        $hasBenefits = $benefits !== null
            ? $benefits !== []
            : ($scholarship?->benefitPayload() ?? []) !== [];

        if (! $hasBenefits) {
            $errors['benefits'] = 'Add at least one program benefit before submitting for review.';
        }

        if (blank($value('application_mode'))) {
            $errors['application_mode'] = 'Choose how applicants will be verified during the portal review.';
        }

        if (blank($value('eligibility'))) {
            $errors['eligibility'] = 'Describe who is eligible for this program.';
        }

        $hasFinderRule = collect([
            $value('eligible_education_levels'),
            $value('eligible_courses'),
            $value('eligible_school_types'),
            $value('eligible_year_levels'),
            $value('eligible_locations'),
            $value('minimum_gwa'),
        ])->contains(fn ($field): bool => filled($field))
            || ($value('income_requirement') !== null && $value('income_requirement') !== 'Any')
            || (bool) $value('exclude_current_scholarship_recipients')
            || in_array($value('minimum_grade_scale'), ['pass_fail', 'other'], true);

        if (! $hasFinderRule) {
            $errors['eligible_education_levels'] = 'Add at least one applicant matching rule.';
        }

        if ($value('application_mode') !== 'provider_review' && blank($value('requirements'))) {
            $errors['requirements'] = 'Add at least one document needed for portal pre-screening.';
        }

        if (blank($value('post_qualification_requirements'))) {
            $errors['post_qualification_requirements'] = 'List at least one document a qualified applicant must bring for the formal application.';
        }

        $handoffMode = $value('handoff_mode');

        if (blank($handoffMode)) {
            $errors['handoff_mode'] = 'Choose how qualified applicants continue with the provider.';
        }

        if (blank($value('handoff_instructions'))) {
            $errors['handoff_instructions'] = 'Add short instructions for qualified applicants.';
        }

        if ($handoffMode === 'onsite' && blank($value('handoff_location_address'))) {
            $errors['handoff_location_address'] = 'Add the address where qualified applicants must bring their documents.';
        }

        if ($handoffMode === 'online' && blank($value('handoff_url'))) {
            $errors['handoff_url'] = 'Add the official link qualified applicants should use.';
        }

        $handoffDeadline = $value('handoff_deadline');

        if (filled($handoffDeadline) && filled($deadline)
            && CarbonImmutable::parse($handoffDeadline)->startOfDay()->isBefore(CarbonImmutable::parse($deadline)->startOfDay())) {
            $errors['handoff_deadline'] = 'The formal application deadline must be on or after the pre-screening deadline.';
        }

        foreach ([
            'location_name' => 'Add the program location name.',
            'location_address' => 'Add the program address.',
            'latitude' => 'Set the program location pin on the map.',
            'longitude' => 'Set the program location pin on the map.',
        ] as $field => $message) {
            if (blank($value($field))) {
                $errors[$field] = $message;
            }
        }

        if (blank($value('contact_email')) && blank($value('contact_number'))) {
            $errors['contact_email'] = 'Add an email address or contact number for applicant questions.';
        }

        if (blank($value('review_rubric'))) {
            $errors['review_rubric'] = 'Add at least one review criterion.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function addScholarshipEligibilityConsistencyErrors(array &$errors, callable $value): void
    {
        $conditions = ScholarshipEligibilityCondition::normalize((array) ($value('eligibility_conditions') ?? []));

        if ($conditions === []) {
            return;
        }

        $conditionKeys = collect($conditions)->pluck('key');

        if ($conditionKeys->contains('open_to_all')) {
            $educationLevels = collect(preg_split('/\r\n|\r|\n|,/', (string) $value('eligible_education_levels')))
                ->map(fn (string $level): string => Str::lower(trim($level)))
                ->filter()
                ->unique()
                ->values();
            $allEducationLevels = collect([
                'preschool',
                'elementary',
                'junior_high_school',
                'senior_high_school',
                'college',
                'tvet',
                'als',
            ]);
            $hasEducationRestriction = $educationLevels->isNotEmpty()
                && ($educationLevels->count() !== $allEducationLevels->count()
                    || $educationLevels->diff($allEducationLevels)->isNotEmpty());
            $hasFinderRestriction = $hasEducationRestriction
                || ! $this->isOpenScholarshipRule($value('eligible_courses'))
                || filled($value('eligible_school_types'))
                || ! $this->isOpenScholarshipRule($value('eligible_year_levels'))
                || ! $this->isOpenScholarshipRule($value('eligible_locations'))
                || ! $this->isOpenScholarshipRule($value('income_requirement'))
                || (bool) $value('exclude_current_scholarship_recipients')
                || filled($value('minimum_grade_scale'))
                || filled($value('minimum_gwa'));

            if (count($conditions) > 1 || $hasFinderRestriction) {
                $errors['eligibility_conditions'] = 'Open to all learners cannot be combined with other conditions or restrictive matching fields.';
            }
        }

        if ($conditionKeys->contains('academic_performance')
            && blank($value('minimum_grade_scale'))
            && blank($value('minimum_gwa'))) {
            $errors['minimum_grade_scale'] = 'Choose an academic grading rule or remove the academic requirement condition.';
        }

        if ($conditionKeys->contains('financial_need') && $this->isOpenScholarshipRule($value('income_requirement'))) {
            $errors['income_requirement'] = 'Choose a household-income range or remove the financial need condition.';
        }

        if ($conditionKeys->contains('location_coverage') && $this->isOpenScholarshipRule($value('eligible_locations'))) {
            $errors['eligible_locations'] = 'Add a covered location or remove the location coverage condition.';
        }

        if ($conditionKeys->contains('required_documents')) {
            if ($value('application_mode') === 'provider_review') {
                $errors['application_mode'] = 'Required documents cannot be checked in profile review only. Choose a portal document review method or remove the condition.';
            } elseif (blank($value('requirements'))) {
                $errors['requirements'] = 'Select at least one pre-screening file or remove the required documents condition.';
            }
        }

        if (collect($conditions)->contains(
            fn (array $condition): bool => $condition['source'] === 'provider_condition'
                && $condition['verification_type'] === 'automatic',
        )) {
            $errors['eligibility_conditions'] = 'A custom condition must be confirmed by the applicant or reviewed by the provider; it cannot be checked automatically.';
        }
    }

    private function isOpenScholarshipRule(mixed $value): bool
    {
        $normalized = Str::of((string) $value)->lower()->squish()->toString();

        return $normalized === '' || in_array($normalized, [
            'any',
            'all',
            'open to all',
            'nationwide',
            'no restriction',
            'no income requirement',
            'any income',
        ], true);
    }

    private function validateScholarship(Request $request): array
    {
        $requiresCompleteSubmission = ! in_array($request->input('status'), ['draft', 'closed'], true);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'program_cycle' => ['nullable', 'string', 'max:100'],
            'description' => [
                Rule::requiredIf($requiresCompleteSubmission),
                'nullable',
                'string',
                'max:5000',
            ],
            'provider_objectives' => ['nullable', 'string', 'max:2000', 'json'],
            'provider_objective_notes' => ['nullable', 'string', 'max:1500'],
            'eligibility' => ['nullable', 'string', 'max:5000'],
            'eligibility_conditions' => ['nullable', 'string', 'max:15000', 'json'],
            'eligible_education_levels' => ['nullable', 'string', 'max:2000'],
            'eligible_courses' => ['nullable', 'string', 'max:3000'],
            'eligible_school_types' => ['nullable', 'string', 'max:2000'],
            'eligible_year_levels' => ['nullable', 'string', 'max:2000'],
            'eligible_locations' => ['nullable', 'string', 'max:3000'],
            'income_requirement' => ['nullable', 'string', 'max:100'],
            'exclude_current_scholarship_recipients' => ['nullable', 'boolean'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'location_address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'requirements' => ['nullable', 'string', 'max:5000'],
            'optional_requirements' => ['nullable', 'string', 'max:5000'],
            'post_qualification_requirements' => ['nullable', 'string', 'max:5000'],
            'handoff_mode' => ['nullable', Rule::in(['onsite', 'online', 'provider_contact'])],
            'handoff_instructions' => ['nullable', 'string', 'max:3000'],
            'handoff_deadline' => ['nullable', 'date'],
            'handoff_location_name' => ['nullable', 'string', 'max:255'],
            'handoff_location_address' => ['nullable', 'string', 'max:500'],
            'handoff_url' => ['nullable', 'url:http,https', 'max:2048'],
            'award_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'benefits' => ['nullable', 'string', 'max:20000', 'json'],
            'minimum_gwa' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_grade_scale' => ['nullable', Rule::in(AcademicRequirement::SCALES)],
            'slots_available' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'application_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'application_mode' => ['nullable', Rule::in(['online', 'onsite', 'hybrid', 'provider_review'])],
            'selection_stages' => ['nullable', 'string', 'max:500', 'json'],
            'program_events' => ['nullable', 'string', 'max:20000', 'json'],
            'renewal_policy' => ['nullable', 'string', 'max:2000'],
            'return_service_contract' => ['nullable', 'string', 'max:3000'],
            'other_contract_terms' => ['nullable', 'string', 'max:3000'],
            'recipient_agreement' => ['nullable', 'string', 'max:12000', 'json'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:30', new PhoneNumber],
            'application_opens_at' => ['nullable', 'date'],
            'expected_results_at' => ['nullable', 'date'],
            'support_starts_at' => ['nullable', 'date'],
            'support_ends_at' => ['nullable', 'date', 'after_or_equal:support_starts_at'],
            'official_program_url' => ['nullable', 'url:http,https', 'max:2048'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'contact_department' => ['nullable', 'string', 'max:150'],
            'deadline' => ['nullable', 'date'],
            'status' => ['required', Rule::in(['draft', 'pending_review', 'published', 'closed', 'rejected'])],
            'image_file' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'use_provider_logo' => ['nullable', 'boolean'],
            'terms_accepted' => $requiresCompleteSubmission ? ['accepted'] : ['nullable'],
            'review_rubric' => ['nullable', 'string', 'max:8000', 'json'],
            'application_questions' => ['nullable', 'string', 'max:10000', 'json'],
        ]);

        return $validated;
    }

    private function normalizeScholarshipProviderObjectives(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if (! $request->has('provider_objectives')) {
            $validated['provider_objectives'] = $scholarship?->provider_objectives ?? [];

            return $validated;
        }

        $decoded = json_decode((string) ($validated['provider_objectives'] ?? '[]'), true);
        $validated['provider_objectives'] = collect(is_array($decoded) ? $decoded : [])
            ->filter(fn (mixed $objective): bool => is_string($objective)
                && in_array($objective, self::PROVIDER_OBJECTIVES, true))
            ->unique()
            ->values()
            ->all();

        return $validated;
    }

    private function normalizeScholarshipRecipientAgreement(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if (! $request->has('recipient_agreement')) {
            $validated['recipient_agreement'] = $scholarship?->recipient_agreement;

            return $validated;
        }

        $decoded = json_decode((string) ($validated['recipient_agreement'] ?? '{}'), true);
        $agreement = validator(['agreement' => is_array($decoded) ? $decoded : []], [
            'agreement.commitment_type' => ['required', Rule::in(self::RECIPIENT_COMMITMENT_TYPES)],
            'agreement.responsibilities' => ['nullable', 'string', 'max:2000'],
            'agreement.required_evidence' => ['nullable', 'string', 'max:1500'],
            'agreement.release_conditions' => ['nullable', 'string', 'max:1500'],
            'agreement.duration' => ['nullable', 'string', 'max:500'],
            'agreement.noncompliance_consequence' => ['nullable', 'string', 'max:1000'],
            'agreement.exit_or_exception_process' => ['nullable', 'string', 'max:1000'],
        ])->validate()['agreement'];

        $validated['recipient_agreement'] = [
            'commitment_type' => $agreement['commitment_type'],
            'responsibilities' => Str::squish((string) ($agreement['responsibilities'] ?? '')) ?: null,
            'required_evidence' => Str::squish((string) ($agreement['required_evidence'] ?? '')) ?: null,
            'release_conditions' => Str::squish((string) ($agreement['release_conditions'] ?? '')) ?: null,
            'duration' => Str::squish((string) ($agreement['duration'] ?? '')) ?: null,
            'noncompliance_consequence' => Str::squish((string) ($agreement['noncompliance_consequence'] ?? '')) ?: null,
            'exit_or_exception_process' => Str::squish((string) ($agreement['exit_or_exception_process'] ?? '')) ?: null,
        ];

        return $validated;
    }

    private function applyProviderProgramContactDefaults(
        array $validated,
        User $actor,
        ?Scholarship $scholarship = null,
    ): array {
        $profile = $actor->providerOrganizationOwner()->providerProfile()->first();

        if (blank($validated['contact_email'] ?? null)) {
            $validated['contact_email'] = $scholarship?->contact_email
                ?: $profile?->provider_contact_email;
        }

        if (blank($validated['contact_number'] ?? null)) {
            $validated['contact_number'] = $scholarship?->contact_number
                ?: $profile?->provider_contact_number;
        }

        return $validated;
    }

    private function normalizeScholarshipAcademicRequirement(array $validated): array
    {
        $scale = $validated['minimum_grade_scale'] ?? null;

        if ($scale === '') {
            $scale = null;
        }

        if (! AcademicRequirement::requiresNumeric($scale)) {
            $validated['minimum_gwa'] = null;
        }

        if (blank($validated['minimum_gwa'] ?? null) && in_array($scale, ['percentage', 'grade_point'], true)) {
            $scale = null;
        }

        if ($scale === 'grade_point' && (float) $validated['minimum_gwa'] > 5) {
            throw ValidationException::withMessages([
                'minimum_gwa' => 'A GWA or GPA grade point must be between 0 and 5.',
            ]);
        }

        $validated['minimum_grade_scale'] = $scale;

        return $validated;
    }

    private function normalizeScholarshipProgramPaths(array $validated): array
    {
        if (array_key_exists('eligible_courses', $validated)) {
            $validated['eligible_courses'] = LearnerProgramPath::canonicalizeList($validated['eligible_courses']);
        }

        return $validated;
    }

    private function normalizeScholarshipRequirements(array $validated): array
    {
        foreach (['requirements', 'optional_requirements', 'post_qualification_requirements'] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $normalized = collect(preg_split('/\r\n|\r|\n/', (string) ($validated[$field] ?? '')))
                ->map(fn (string $requirement): string => trim($requirement))
                ->filter()
                ->reject(fn (string $requirement): bool => Str::lower($requirement) === 'completed application form')
                ->unique(fn (string $requirement): string => Str::lower($requirement))
                ->implode("\n");

            $validated[$field] = $normalized !== '' ? $normalized : null;
        }

        return $validated;
    }

    private function normalizeScholarshipEligibilityConditions(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if (! $request->has('eligibility_conditions')) {
            $validated['eligibility_conditions'] = $scholarship?->eligibility_conditions ?? [];

            return $validated;
        }

        $validated['eligibility_conditions'] = ScholarshipEligibilityCondition::fromJson(
            $validated['eligibility_conditions'] ?? null,
        );

        return $validated;
    }

    private function normalizeScholarshipReviewRubric(array $validated, Request $request, ?Scholarship $scholarship = null): array
    {
        if (! $request->has('review_rubric')) {
            $validated['review_rubric'] = $scholarship?->review_rubric ?? ReviewRubric::DEFAULT;

            return $validated;
        }

        $validated['review_rubric'] = ReviewRubric::fromJson($validated['review_rubric'] ?? null);

        return $validated;
    }

    private function normalizeScholarshipApplicationQuestions(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if (! $request->has('application_questions')) {
            $validated['application_questions'] = $scholarship?->application_questions;

            return $validated;
        }

        $decoded = json_decode((string) ($validated['application_questions'] ?? '[]'), true);
        $questions = collect(is_array($decoded) ? $decoded : [])
            ->filter(fn (mixed $question): bool => is_array($question) && filled($question['prompt'] ?? null))
            ->values()
            ->all();
        $questions = validator(['questions' => $questions], [
            'questions' => ['array', 'max:5'],
            'questions.*.id' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'questions.*.prompt' => ['required', 'string', 'max:300'],
            'questions.*.required' => ['nullable', 'boolean'],
        ])->validate()['questions'] ?? [];
        $usedIds = [];

        $validated['application_questions'] = collect($questions)
            ->map(function (array $question, int $index) use (&$usedIds): array {
                $prompt = Str::squish($question['prompt']);
                $id = $question['id'] ?? null;

                if (blank($id) || in_array($id, $usedIds, true)) {
                    $id = 'question_'.($index + 1).'_'.substr(sha1($prompt), 0, 8);
                }

                $usedIds[] = $id;

                return [
                    'id' => $id,
                    'prompt' => $prompt,
                    'required' => (bool) ($question['required'] ?? false),
                ];
            })
            ->values()
            ->all();

        return $validated;
    }

    private function normalizeScholarshipSelectionStages(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): array {
        if ($request->has('selection_stages')) {
            $validated['selection_stages'] = ScholarshipSelectionPlan::normalize($validated['selection_stages'] ?? null);
        } elseif ($scholarship) {
            $validated['selection_stages'] = $scholarship->selection_stages;
        } else {
            $validated['selection_stages'] = ScholarshipSelectionPlan::DEFAULT;
        }

        return $validated;
    }

    private function normalizeScholarshipExamDetails(array $validated): array
    {
        // Exams are scheduled and explained by providers; scoring rules are not managed by the portal.
        $validated['exam_duration_minutes'] = null;
        $validated['exam_passing_score'] = null;

        return $validated;
    }

    private function normalizeScholarshipProgramEvents(
        array $validated,
        Request $request,
        ?Scholarship $scholarship = null,
    ): ?array {
        if (! $request->has('program_events')) {
            return null;
        }

        $events = json_decode((string) ($validated['program_events'] ?? '[]'), true);
        $events = is_array($events) ? $events : [];
        $eventData = validator(['program_events' => $events], [
            'program_events' => ['array', 'max:2'],
            'program_events.*.type' => ['required', 'string', 'distinct', Rule::in(ScholarshipSelectionPlan::SCHEDULABLE_STAGES)],
            'program_events.*.title' => ['nullable', 'string', 'max:255'],
            'program_events.*.scheduled_at' => ['required', 'date'],
            'program_events.*.mode' => ['required', Rule::in(['onsite', 'online', 'hybrid', 'provider_managed'])],
            'program_events.*.venue' => ['nullable', 'string', 'max:500'],
            'program_events.*.location_address' => ['nullable', 'string', 'max:1000'],
            'program_events.*.latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:program_events.*.longitude'],
            'program_events.*.longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:program_events.*.latitude'],
            'program_events.*.online_url' => ['nullable', 'url:http,https', 'max:2000'],
            'program_events.*.instructions' => ['required', 'string', 'max:3000'],
        ])->validate()['program_events'] ?? [];
        $selectionStages = ScholarshipSelectionPlan::normalize($validated['selection_stages'] ?? null);
        $scholarship?->loadMissing('events');

        $eventDates = [];
        $eventIndexes = [];

        foreach ($eventData as $index => $event) {
            if (! in_array($event['type'], $selectionStages, true)) {
                throw ValidationException::withMessages([
                    "program_events.{$index}.type" => 'Add this stage to the selection plan before setting its schedule.',
                ]);
            }

            if (in_array($event['mode'], ['onsite', 'hybrid'], true) && blank($event['venue'] ?? null)) {
                throw ValidationException::withMessages([
                    "program_events.{$index}.venue" => 'Add a venue for an on-site or hybrid schedule.',
                ]);
            }

            if (in_array($event['mode'], ['online', 'hybrid'], true) && blank($event['online_url'] ?? null)) {
                throw ValidationException::withMessages([
                    "program_events.{$index}.online_url" => 'Add the online meeting or assessment link.',
                ]);
            }

            $scheduledAt = CarbonImmutable::parse($event['scheduled_at']);
            $existingEvent = $scholarship?->events->firstWhere('type', $event['type']);
            $keepsExistingDate = $existingEvent?->scheduled_at?->format('Y-m-d H:i') === $scheduledAt->format('Y-m-d H:i');

            if ($scheduledAt->isBefore(now()->startOfMinute()) && ! $keepsExistingDate) {
                throw ValidationException::withMessages([
                    "program_events.{$index}.scheduled_at" => 'Use a future date and time for a new schedule.',
                ]);
            }

            $eventDates[$event['type']] = $scheduledAt;
            $eventIndexes[$event['type']] = $index;
        }

        $previousType = null;

        foreach (collect($selectionStages)->filter(
            fn (string $stage): bool => ScholarshipSelectionPlan::isSchedulable($stage),
        ) as $eventType) {
            if (! isset($eventDates[$eventType])) {
                continue;
            }

            if ($previousType !== null && $eventDates[$eventType]->isBefore($eventDates[$previousType])) {
                $eventLabel = ScholarshipSelectionPlan::label($eventType);
                $previousLabel = ScholarshipSelectionPlan::label($previousType);

                throw ValidationException::withMessages([
                    "program_events.{$eventIndexes[$eventType]}.scheduled_at" => ucfirst($eventLabel)." must be scheduled after {$previousLabel}.",
                ]);
            }

            $previousType = $eventType;
        }

        return $eventData;
    }

    private function persistScholarshipEvent(
        Scholarship $scholarship,
        array $eventData,
        User $provider,
    ): array {
        $selectionStages = ScholarshipSelectionPlan::normalize($scholarship->selection_stages);

        if (! in_array($eventData['type'], $selectionStages, true)) {
            throw ValidationException::withMessages([
                'type' => 'Add this stage to the scholarship selection plan before publishing its schedule.',
            ]);
        }

        $eventLabel = ScholarshipSelectionPlan::label($eventData['type']);
        $event = $scholarship->events()->firstOrNew(['type' => $eventData['type']]);
        $usesVenue = in_array($eventData['mode'], ['onsite', 'hybrid'], true);
        $usesOnlineLink = in_array($eventData['mode'], ['online', 'hybrid'], true);
        $announcementData = [
            'title' => filled($eventData['title'] ?? null) ? trim($eventData['title']) : ucfirst($eventLabel).' schedule',
            'scheduled_at' => CarbonImmutable::parse($eventData['scheduled_at']),
            'mode' => $eventData['mode'],
            'venue' => $usesVenue ? ($eventData['venue'] ?? null) : null,
            'location_address' => $usesVenue ? ($eventData['location_address'] ?? null) : null,
            'latitude' => $usesVenue ? ($eventData['latitude'] ?? null) : null,
            'longitude' => $usesVenue ? ($eventData['longitude'] ?? null) : null,
            'online_url' => $usesOnlineLink ? ($eventData['online_url'] ?? null) : null,
            'instructions' => $eventData['instructions'],
        ];
        $event->fill($announcementData);
        $announcementChanged = $event->isDirty(array_keys($announcementData));

        $event->fill([
            'status' => $event->exists && $event->status === 'completed' && ! $announcementChanged
                ? 'completed'
                : 'scheduled',
            'updated_by' => $provider->id,
        ]);

        if (! $event->exists) {
            $event->created_by = $provider->id;
        }

        $event->save();

        return [
            $event,
            app(ScholarshipEventService::class)->syncEligibleApplications($event),
        ];
    }

    private function deleteScholarshipEventsSafely(
        Scholarship $scholarship,
        EloquentCollection $events,
    ): void {
        if ($events->isEmpty()) {
            return;
        }

        $activeScheduleTypes = ApplicationSchedule::query()
            ->where('status', 'scheduled')
            ->whereIn('type', $events->pluck('type'))
            ->whereHas('application', fn ($query) => $query->where('scholarship_id', $scholarship->id))
            ->pluck('type')
            ->unique()
            ->map(fn (string $type): string => ScholarshipSelectionPlan::label($type))
            ->implode(', ');

        if ($activeScheduleTypes !== '') {
            throw ValidationException::withMessages([
                'program_events' => "The {$activeScheduleTypes} schedule is already active for applicants. Update its details instead of removing it.",
            ]);
        }

        ScholarshipEvent::query()->whereKey($events->modelKeys())->delete();
    }

    private function providerScholarshipStatus(Scholarship $scholarship, string $requestedStatus, array $validated, bool $benefitsChanged = false): string
    {
        if ($requestedStatus === 'published' && $scholarship->status === 'published') {
            return $benefitsChanged || $this->scholarshipHasReviewableChanges($scholarship, $validated)
                ? 'pending_review'
                : 'published';
        }

        if ($requestedStatus === 'closed' && in_array($scholarship->status, ['published', 'closed'], true)) {
            return 'closed';
        }

        return $requestedStatus === 'draft' ? 'draft' : 'pending_review';
    }

    private function ensureScholarshipSelectionPlanIsStable(
        Scholarship $scholarship,
        mixed $selectionStages,
    ): void {
        $currentStages = ScholarshipSelectionPlan::normalize($scholarship->selection_stages);
        $nextStages = ScholarshipSelectionPlan::normalize($selectionStages);

        if ($currentStages === $nextStages || ! $scholarship->applications()->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'selection_stages' => 'The review, exam, and interview path cannot change after applications have been submitted. Duplicate this program for a new intake instead.',
        ]);
    }

    private function ensureScholarshipAwardCapacity(
        Scholarship $scholarship,
        ?int $requestedSlots,
    ): void {
        if ($requestedSlots === null) {
            return;
        }

        Scholarship::query()
            ->whereKey($scholarship->id)
            ->lockForUpdate()
            ->firstOrFail();

        $occupiedSlots = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->whereIn('status', self::AWARD_SLOT_STATUSES)
            ->count();

        if ($requestedSlots < $occupiedSlots) {
            throw ValidationException::withMessages([
                'slots_available' => "This program already has {$occupiedSlots} awarded applicant(s). Keep at least {$occupiedSlots} award slot(s).",
            ]);
        }
    }

    private function ensureScholarshipApplicationCapacity(
        Scholarship $scholarship,
        ?int $requestedLimit,
    ): void {
        if ($requestedLimit === null) {
            return;
        }

        Scholarship::query()
            ->whereKey($scholarship->id)
            ->lockForUpdate()
            ->firstOrFail();

        $applicationCount = ScholarshipApplication::query()
            ->where('scholarship_id', $scholarship->id)
            ->count();

        if ($requestedLimit < $applicationCount) {
            throw ValidationException::withMessages([
                'application_limit' => "This program already has {$applicationCount} application(s). Keep the application limit at {$applicationCount} or higher.",
            ]);
        }
    }

    private function scholarshipHasReviewableChanges(Scholarship $scholarship, array $validated): bool
    {
        $reviewableFields = [
            'image_path',
            'title',
            'category',
            'program_cycle',
            'description',
            'provider_objectives',
            'provider_objective_notes',
            'eligibility',
            'eligible_education_levels',
            'eligible_courses',
            'eligible_school_types',
            'eligible_year_levels',
            'eligible_locations',
            'income_requirement',
            'exclude_current_scholarship_recipients',
            'location_name',
            'location_address',
            'latitude',
            'longitude',
            'requirements',
            'optional_requirements',
            'post_qualification_requirements',
            'handoff_mode',
            'handoff_instructions',
            'handoff_deadline',
            'handoff_location_name',
            'handoff_location_address',
            'handoff_url',
            'review_rubric',
            'application_questions',
            'award_amount',
            'minimum_gwa',
            'minimum_grade_scale',
            'slots_available',
            'application_limit',
            'application_mode',
            'selection_stages',
            'renewal_policy',
            'return_service_contract',
            'other_contract_terms',
            'recipient_agreement',
            'contact_email',
            'contact_number',
            'application_opens_at',
            'expected_results_at',
            'support_starts_at',
            'support_ends_at',
            'official_program_url',
            'contact_person',
            'contact_department',
            'deadline',
        ];

        foreach ($reviewableFields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $currentValue = $field === 'selection_stages'
                ? ScholarshipSelectionPlan::normalize($scholarship->selection_stages)
                : $scholarship->getAttribute($field);
            $nextValue = $field === 'selection_stages'
                ? ScholarshipSelectionPlan::normalize($validated[$field])
                : $validated[$field];

            if ($this->comparableScholarshipValue($currentValue)
                !== $this->comparableScholarshipValue($nextValue)) {
                return true;
            }
        }

        return false;
    }

    private function comparableScholarshipValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (string) (float) $value;
        }

        return trim((string) $value);
    }

    private function notifyAdminsScholarshipSubmitted(Request $request, Scholarship $scholarship): void
    {
        User::query()
            ->where('role', 'admin')
            ->get()
            ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_reviews'))
            ->each(fn (User $admin) => PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'scholarship_review',
                'title' => 'Scholarship ready for review',
                'message' => "{$request->user()->name} submitted {$scholarship->title} for admin review.",
                'action_url' => '/admin/reviews',
            ]));
    }


    private function copyProviderLogoForScholarship(User $actor): ?string
    {
        $profile = $actor->providerOrganizationOwner()->providerProfile()->first();
        $logoPath = ltrim(str_replace('\\', '/', (string) $profile?->logo_path), '/');

        if (! str_starts_with($logoPath, 'uploads/providers/')) {
            return null;
        }

        $sourcePath = public_path($logoPath);

        if (! is_file($sourcePath)) {
            return null;
        }

        $directory = public_path('uploads/scholarships');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'png';
        $relativePath = 'uploads/scholarships/'.Str::uuid().".{$extension}";

        return copy($sourcePath, public_path($relativePath)) ? $relativePath : null;
    }


    private function storeScholarshipImage(Request $request): ?string
    {
        if (! $request->hasFile('image_file')) {
            return null;
        }

        $file = $request->file('image_file');
        $directory = public_path('uploads/scholarships');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filename = $file->hashName();
        $file->move($directory, $filename);

        return "uploads/scholarships/{$filename}";
    }

    private function copyScholarshipImage(?string $imagePath): ?string
    {
        $normalizedPath = $this->managedScholarshipImagePath($imagePath);

        if ($normalizedPath === null) {
            return null;
        }

        $sourcePath = public_path($normalizedPath);

        if (! is_file($sourcePath)) {
            return null;
        }

        $directory = public_path('uploads/scholarships');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) ?: 'jpg';
        $relativePath = 'uploads/scholarships/'.Str::uuid().".{$extension}";

        return copy($sourcePath, public_path($relativePath)) ? $relativePath : null;
    }

    private function deleteScholarshipImageIfUnused(?string $imagePath): void
    {
        $normalizedPath = $this->managedScholarshipImagePath($imagePath);

        if ($normalizedPath === null
            || Scholarship::query()->where('image_path', $normalizedPath)->exists()) {
            return;
        }

        $absolutePath = public_path($normalizedPath);

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    private function managedScholarshipImagePath(?string $imagePath): ?string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', (string) $imagePath), '/');

        return str_starts_with($normalizedPath, 'uploads/scholarships/')
            ? $normalizedPath
            : null;
    }

    private function scholarshipPayload(Scholarship $scholarship): array
    {
        $scholarship->loadMissing('events');

        return [
            'id' => $scholarship->id,
            'image_path' => $scholarship->image_path,
            'image_url' => $this->scholarshipImageUrl($scholarship),
            'title' => $scholarship->title,
            'category' => $scholarship->category,
            'program_cycle' => $scholarship->program_cycle,
            'description' => $scholarship->description,
            'provider_objectives' => $scholarship->provider_objectives ?? [],
            'provider_objective_notes' => $scholarship->provider_objective_notes,
            'eligibility' => $scholarship->eligibility,
            'eligibility_conditions' => $scholarship->eligibility_conditions ?? [],
            'eligible_education_levels' => $scholarship->eligible_education_levels,
            'eligible_courses' => $scholarship->eligible_courses,
            'eligible_school_types' => $scholarship->eligible_school_types,
            'eligible_year_levels' => $scholarship->eligible_year_levels,
            'eligible_locations' => $scholarship->eligible_locations,
            'income_requirement' => $scholarship->income_requirement,
            'exclude_current_scholarship_recipients' => (bool) $scholarship->exclude_current_scholarship_recipients,
            'location_name' => $scholarship->location_name,
            'location_address' => $scholarship->location_address,
            'latitude' => $scholarship->latitude,
            'longitude' => $scholarship->longitude,
            'map_url' => $this->mapUrl($scholarship),
            'embed_map_url' => $this->embedMapUrl($scholarship),
            'requirements' => $scholarship->requirements,
            'optional_requirements' => $scholarship->optional_requirements,
            'post_qualification_requirements' => $scholarship->post_qualification_requirements,
            'handoff_mode' => $scholarship->handoff_mode,
            'handoff_instructions' => $scholarship->handoff_instructions,
            'handoff_deadline' => $scholarship->handoff_deadline?->format('Y-m-d'),
            'handoff_location_name' => $scholarship->handoff_location_name,
            'handoff_location_address' => $scholarship->handoff_location_address,
            'handoff_url' => $scholarship->handoff_url,
            'handoff_map_url' => $this->handoffMapUrl($scholarship),
            'review_rubric' => $scholarship->review_rubric ?? [],
            'application_questions' => $scholarship->application_questions ?? [],
            'benefits' => $scholarship->benefitPayload(),
            'benefit_summary' => $scholarship->benefitSummary(),
            'award_amount' => $scholarship->award_amount,
            'minimum_gwa' => $scholarship->minimum_gwa,
            'minimum_grade_scale' => AcademicRequirement::normalizeScale($scholarship->minimum_grade_scale, $scholarship->minimum_gwa),
            'minimum_grade_label' => AcademicRequirement::requirementLabel($scholarship->minimum_gwa, $scholarship->minimum_grade_scale),
            'slots_available' => $scholarship->slots_available,
            'application_limit' => $scholarship->application_limit,
            'applications_count' => $scholarship->applications_count ?? $scholarship->applications()->count(),
            'pending_review_applications_count' => $scholarship->pending_review_applications_count
                ?? $scholarship->applications()->whereIn('status', ['submitted', 'under_review'])->count(),
            'awarded_slots_count' => $scholarship->awarded_slots_count
                ?? $scholarship->applications()->whereIn('status', self::AWARD_SLOT_STATUSES)->count(),
            'application_mode' => $scholarship->application_mode,
            'selection_stages' => ScholarshipSelectionPlan::normalize($scholarship->selection_stages),
            'program_events' => $scholarship->events
                ->where('status', 'scheduled')
                ->sortBy('scheduled_at')
                ->map(fn (ScholarshipEvent $event): array => ScholarshipEventPayload::make($event))
                ->values(),
            'renewal_policy' => $scholarship->renewal_policy,
            'return_service_contract' => $scholarship->return_service_contract,
            'other_contract_terms' => $scholarship->other_contract_terms,
            'recipient_agreement' => $scholarship->recipient_agreement,
            'contact_email' => $scholarship->contact_email,
            'contact_number' => $scholarship->contact_number,
            'application_opens_at' => $scholarship->application_opens_at?->format('Y-m-d'),
            'expected_results_at' => $scholarship->expected_results_at?->format('Y-m-d'),
            'support_starts_at' => $scholarship->support_starts_at?->format('Y-m-d'),
            'support_ends_at' => $scholarship->support_ends_at?->format('Y-m-d'),
            'official_program_url' => $scholarship->official_program_url,
            'contact_person' => $scholarship->contact_person,
            'contact_department' => $scholarship->contact_department,
            'deadline' => $scholarship->deadline?->format('Y-m-d'),
            'status' => $scholarship->status,
            'bookmarks_count' => $scholarship->bookmarks_count ?? $scholarship->bookmarks()->count(),
            'views_count' => $scholarship->views_count,
            'created_at' => $scholarship->created_at?->format('M d, Y'),
            'updated_at' => $scholarship->updated_at?->format('M d, Y'),
        ];
    }

    private function scholarshipAnnouncementPayload(ScholarshipAnnouncement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'audience' => $announcement->audience,
            'audience_label' => match ($announcement->audience) {
                'active_applicants' => 'All active applicants',
                'under_review' => 'Applicants under review',
                'qualified_applicants' => 'Qualified applicants and alternates',
                'selected_recipients' => 'Selected recipients',
                default => Str::headline($announcement->audience),
            },
            'title' => $announcement->title,
            'message' => $announcement->message,
            'recipient_count' => $announcement->recipient_count,
            'publisher' => $announcement->publisher?->name,
            'published_at' => $announcement->published_at?->format('M d, Y h:i A'),
        ];
    }

    private function examPayload(Scholarship $scholarship): array
    {
        $scholarship->loadMissing('events');
        $event = $scholarship->events->firstWhere('type', 'exam');

        return [
            'title' => $event?->title ?: "{$scholarship->title} exam",
            'assessment_type' => 'qualifying_exam',
            'image_url' => $this->scholarshipImageUrl($scholarship),
            'description' => 'This exam is conducted and graded by the scholarship provider outside the portal.',
            'delivery_mode' => $event?->mode ?? 'provider_managed',
            'venue' => $event?->venue ?: $event?->location_address,
            'instructions' => $event?->instructions,
        ];
    }

    private function duplicateScholarshipTitle(int $providerId, string $title): string
    {
        $baseTitle = preg_replace('/\s+\(Copy(?:\s+\d+)?\)$/', '', $title) ?: $title;
        $candidate = "{$baseTitle} (Copy)";
        $counter = 2;

        while (
            Scholarship::query()
                ->where('provider_id', $providerId)
                ->where('title', $candidate)
                ->exists()
        ) {
            $candidate = "{$baseTitle} (Copy {$counter})";
            $counter++;
        }

        return $candidate;
    }

    private function includeProgramInStaffScope(User $actor, Scholarship $scholarship): void
    {
        if (! $actor->hasLimitedProviderProgramAccess()) {
            return;
        }

        $actor->forceFill([
            'assigned_program_ids' => collect($actor->assignedProviderProgramIds())
                ->push($scholarship->id)
                ->unique()
                ->values()
                ->all(),
        ])->save();
    }

    private function scholarshipImageUrl(Scholarship $scholarship): string
    {
        if (filled($scholarship->image_path)) {
            return asset(ltrim($scholarship->image_path, '/'));
        }

        return asset('uploads/scholarship-default.jpg');
    }

    private function ensureProviderCanPost(Request $request): void
    {
        $providerOwner = $request->user()->providerOrganizationOwner();

        abort_unless(
            $providerOwner->hasVerifiedEmail(),
            403,
            'Verify your email address before submitting a scholarship.'
        );

        if ($providerOwner->providerProfile?->isVerified()) {
            return;
        }

        abort(403, 'Your provider account must be approved by an admin before posting scholarships.');
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

    private function handoffMapUrl(Scholarship $scholarship): ?string
    {
        $query = $scholarship->handoff_location_address ?: $scholarship->handoff_location_name;

        return filled($query)
            ? 'https://www.openstreetmap.org/search?query='.rawurlencode($query)
            : null;
    }

}
