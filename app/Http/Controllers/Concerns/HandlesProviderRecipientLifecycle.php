<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use App\Models\ApplicationStatusHistory;
use App\Models\PortalNotification;
use App\Models\RecipientBenefitReceiptResponse;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\RecipientMonitoringAdjustmentRequest;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringCycleRequirement;
use App\Models\RecipientMonitoringIntervention;
use App\Models\RecipientMonitoringPlan;
use App\Models\RecipientMonitoringRequirement;
use App\Models\RecipientMonitoringSubmission;
use App\Models\RecipientSupportDecision;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Services\AcademicRecordOcrService;
use App\Services\ScholarshipBenefitService as SB;
use App\Support\AcademicRequirement;
use App\Support\CsvExport;
use App\Support\RecipientAgreement;
use App\Support\RecipientMonitoringRequirementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

trait HandlesProviderRecipientLifecycle
{
    public function exportRecipientMonitoring(Request $request, Scholarship $scholarship)
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $cycles = $scholarship->monitoringCycles()->with('requirements')->get();
        $applications = $this->supportRecipientApplications($scholarship);
        $applications->load([
            'monitoringSubmissions',
            'monitoringAdjustmentRequests',
            'monitoringInterventions',
            'benefitReleaseRecords.receiptResponse',
            'supportDecisions',
        ]);
        $rows = $applications->map(function (ScholarshipApplication $application) use ($cycles): array {
            $support = $this->recipientSupportPayload($application, $cycles);
            $latestDecision = $application->supportDecisions->first();

            return [
                $application->id,
                $support['name'],
                $support['email'],
                $support['agreement_status_label'],
                $support['support_status_label'],
                $support['requirements_met'],
                $support['requirements_total'],
                $support['released_count'],
                $support['release_count'],
                $application->monitoringSubmissions
                    ->whereIn('review_status', ['pending', 'needs_replacement', 'not_met'])
                    ->count(),
                $application->monitoringAdjustmentRequests->where('status', 'pending')->count(),
                $application->monitoringInterventions->where('status', 'open')->count(),
                $application->benefitReleaseRecords
                    ->pluck('receiptResponse')
                    ->filter(fn ($response) => $response?->status === 'open')
                    ->count(),
                $latestDecision?->response_status === 'open' ? 'Open' : 'None',
                $latestDecision?->decided_at?->format('Y-m-d H:i:s'),
                $latestDecision?->reason ?: $latestDecision?->next_period_terms,
                $latestDecision?->next_review_on?->format('Y-m-d'),
            ];
        });
        $filename = 'monitoring-'.Str::slug($scholarship->title).'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            CsvExport::writeRow($handle, [
                'Application ID',
                'Recipient',
                'Email',
                'Agreement Status',
                'Support Status',
                'Requirements Confirmed',
                'Requirements Total',
                'Benefits Released',
                'Benefit Records',
                'Requirements Needing Review',
                'Pending Adjustment Requests',
                'Open Follow-ups',
                'Open Release Issues',
                'Open Outcome Response',
                'Latest Outcome Date',
                'Latest Outcome Note',
                'Next Review Date',
            ]);

            foreach ($rows as $row) {
                CsvExport::writeRow($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function recipientMonitoringData(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $applications = $this->selectedRecipientApplications($scholarship);
        $cycles = $scholarship->monitoringCycles()
            ->with(['creator', 'requirements', 'submissions.requirement', 'submissions.reviewer', 'submissions.reviews.reviewer'])
            ->get();
        $releases = $scholarship->benefitReleases()
            ->with(['creator', 'records.applicant.studentProfile', 'records.recorder', 'records.receiptResponse.resolver'])
            ->get();
        $supportRecipients = $this->supportRecipientApplications($scholarship);
        $monitoringPlan = $scholarship->monitoringPlan()
            ->with('requirements')
            ->first();
        $cyclePayloads = $cycles
            ->map(fn (RecipientMonitoringCycle $cycle): array => $this->recipientMonitoringCyclePayload($cycle, $applications))
            ->values();
        $releasePayloads = $releases
            ->map(fn (RecipientBenefitRelease $release): array => $this->recipientBenefitReleasePayload($release))
            ->values();
        $supportPayloads = $supportRecipients
            ->map(fn (ScholarshipApplication $application): array => $this->recipientSupportPayload($application, $cycles))
            ->values();

        return response()->json([
            'scholarship' => [
                ...$this->scholarshipPayload($scholarship),
                'selected_recipients_count' => $supportRecipients->count(),
            ],
            'academic_ocr' => $this->academicRecordOcrService->publicConfiguration(),
            'monitoring_plan' => $monitoringPlan
                ? $this->recipientMonitoringPlanPayload($monitoringPlan)
                : null,
            'cycles' => $cyclePayloads,
            'release_candidates' => $this->recipientReleaseCandidatesPayload($applications, $cycles, now()),
            'benefit_releases' => $releasePayloads,
            'support_recipients' => $supportPayloads,
            'program_summary' => $this->recipientProgramSummaryPayload(
                $cyclePayloads,
                $releasePayloads,
                $supportPayloads,
            ),
        ]);
    }

    public function recipientMonitoringPlanData(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $plan = $scholarship->monitoringPlan()
            ->with(['requirements', 'creator', 'updater'])
            ->first();

        return response()->json([
            'scholarship' => [
                'id' => $scholarship->id,
                'title' => $scholarship->title,
                'program_cycle' => $scholarship->program_cycle,
                'support_starts_on' => $scholarship->support_starts_at?->toDateString(),
                'support_ends_on' => $scholarship->support_ends_at?->toDateString(),
                'minimum_grade' => $scholarship->minimum_gwa !== null ? (float) $scholarship->minimum_gwa : null,
                'grading_scale' => $scholarship->minimum_grade_scale ?: AcademicRequirement::SCALE_PERCENTAGE,
            ],
            'plan' => $plan ? $this->recipientMonitoringPlanPayload($plan) : null,
            'can_edit' => $request->user()->hasPortalPermission('manage_programs'),
            'frequencies' => collect(RecipientMonitoringPlan::FREQUENCIES)
                ->map(fn (string $value): array => [
                    'value' => $value,
                    'label' => Str::headline($value),
                ])
                ->values(),
            'requirement_types' => collect(RecipientMonitoringRequirementType::definitions())
                ->map(fn (array $definition, string $value): array => [
                    'value' => $value,
                    ...$definition,
                ])
                ->values(),
        ]);
    }

    public function upsertRecipientMonitoringPlan(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        abort_unless($request->user()->hasPortalPermission('manage_programs'), 403);

        $validated = $request->validate([
            'frequency' => ['required', Rule::in(RecipientMonitoringPlan::FREQUENCIES)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'grace_period_days' => ['required', 'integer', 'min:0', 'max:60'],
            'allow_exception_requests' => ['required', 'boolean'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(RecipientMonitoringPlan::STATUSES)],
            'requirements' => ['present', 'array', 'max:10'],
            'requirements.*.id' => ['nullable', 'integer', 'distinct'],
            'requirements.*.type' => ['required', Rule::in(RecipientMonitoringRequirementType::values())],
            'requirements.*.title' => ['required', 'string', 'max:120'],
            'requirements.*.description' => ['nullable', 'string', 'max:1000'],
            'requirements.*.evidence_description' => ['nullable', 'string', 'max:1000'],
            'requirements.*.required' => ['required', 'boolean'],
            'requirements.*.requires_file' => ['required', 'boolean'],
            'requirements.*.requires_original_verification' => ['required', 'boolean'],
            'requirements.*.minimum_grade' => ['nullable', 'numeric'],
            'requirements.*.grading_scale' => ['nullable', Rule::in([
                AcademicRequirement::SCALE_PERCENTAGE,
                AcademicRequirement::SCALE_GRADE_POINT,
            ])],
        ]);

        if (filled($validated['starts_on'] ?? null)
            && filled($validated['ends_on'] ?? null)
            && CarbonImmutable::parse($validated['ends_on'])->isBefore(CarbonImmutable::parse($validated['starts_on']))) {
            throw ValidationException::withMessages([
                'ends_on' => 'The monitoring end date must be on or after the start date.',
            ]);
        }

        $requirements = collect($validated['requirements']);

        if ($validated['status'] === 'active' && $requirements->isEmpty()) {
            throw ValidationException::withMessages([
                'requirements' => 'Add at least one requirement before activating the monitoring plan.',
            ]);
        }

        $duplicateTypes = $requirements
            ->where('type', '!=', RecipientMonitoringRequirementType::CUSTOM)
            ->groupBy('type')
            ->filter(fn (Collection $items): bool => $items->count() > 1)
            ->keys();

        if ($duplicateTypes->isNotEmpty()) {
            throw ValidationException::withMessages([
                'requirements' => 'Add each standard requirement type only once. Use a custom requirement for additional checks.',
            ]);
        }

        foreach ($requirements as $index => $requirement) {
            if (($requirement['requires_file'] ?? false) && blank($requirement['evidence_description'] ?? null)) {
                throw ValidationException::withMessages([
                    "requirements.{$index}.evidence_description" => 'Describe the evidence recipients should submit.',
                ]);
            }

            if ($requirement['type'] !== RecipientMonitoringRequirementType::ACADEMIC_PROGRESS) {
                continue;
            }

            if (! isset($requirement['minimum_grade'], $requirement['grading_scale'])) {
                throw ValidationException::withMessages([
                    "requirements.{$index}.minimum_grade" => 'Add the academic grade requirement.',
                ]);
            }

            $minimumGrade = (float) $requirement['minimum_grade'];
            $isValidGrade = $requirement['grading_scale'] === AcademicRequirement::SCALE_GRADE_POINT
                ? $minimumGrade >= 1 && $minimumGrade <= 5
                : $minimumGrade >= 0 && $minimumGrade <= 100;

            if (! $isValidGrade) {
                throw ValidationException::withMessages([
                    "requirements.{$index}.minimum_grade" => $requirement['grading_scale'] === AcademicRequirement::SCALE_GRADE_POINT
                        ? 'Enter a grade point from 1.00 to 5.00.'
                        : 'Enter a percentage from 0 to 100.',
                ]);
            }
        }

        $plan = DB::transaction(function () use ($request, $scholarship, $validated, $requirements): RecipientMonitoringPlan {
            $plan = $scholarship->monitoringPlan()->firstOrNew();
            $isNew = ! $plan->exists;
            $wasActive = $plan->status === 'active';

            $plan->fill([
                'frequency' => $validated['frequency'],
                'starts_on' => $validated['starts_on'] ?? null,
                'ends_on' => $validated['ends_on'] ?? null,
                'grace_period_days' => $validated['grace_period_days'],
                'allow_exception_requests' => $validated['allow_exception_requests'],
                'instructions' => trim((string) ($validated['instructions'] ?? '')) ?: null,
                'status' => $validated['status'],
                'updated_by' => $request->user()->id,
                'version' => $isNew ? 1 : ((int) $plan->version + 1),
                'activated_at' => $validated['status'] === 'active'
                    ? ($wasActive ? $plan->activated_at : now())
                    : null,
            ]);

            if ($isNew) {
                $plan->created_by = $request->user()->id;
            }

            $plan->save();
            $savedRequirementIds = [];

            foreach ($requirements->values() as $index => $values) {
                $requirement = null;
                $requirementId = (int) ($values['id'] ?? 0);

                if ($requirementId > 0) {
                    $requirement = $plan->requirements()->whereKey($requirementId)->first();

                    if (! $requirement) {
                        throw ValidationException::withMessages([
                            "requirements.{$index}.id" => 'The selected monitoring requirement does not belong to this plan.',
                        ]);
                    }
                }

                $requirement ??= new RecipientMonitoringRequirement([
                    'recipient_monitoring_plan_id' => $plan->id,
                ]);
                $isAcademic = $values['type'] === RecipientMonitoringRequirementType::ACADEMIC_PROGRESS;
                $requirement->fill([
                    'type' => $values['type'],
                    'title' => Str::squish($values['title']),
                    'description' => trim((string) ($values['description'] ?? '')) ?: null,
                    'evidence_description' => trim((string) ($values['evidence_description'] ?? '')) ?: null,
                    'required' => $values['required'],
                    'requires_file' => $values['requires_file'],
                    'requires_original_verification' => $values['requires_original_verification'],
                    'minimum_grade' => $isAcademic ? $values['minimum_grade'] : null,
                    'grading_scale' => $isAcademic ? $values['grading_scale'] : null,
                    'sort_order' => $index,
                    'active' => true,
                ]);
                $requirement->save();
                $savedRequirementIds[] = $requirement->id;
            }

            $staleRequirements = $plan->requirements();
            if ($savedRequirementIds !== []) {
                $staleRequirements->whereNotIn('id', $savedRequirementIds);
            }
            $staleRequirements->delete();

            return $plan;
        });

        ActivityLog::record(
            $request->user(),
            $plan->status === 'active' ? 'recipient_monitoring_plan_activated' : 'recipient_monitoring_plan_saved',
            "{$request->user()->name} saved monitoring plan version {$plan->version} for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'monitoring_plan_id' => $plan->id,
                'version' => $plan->version,
                'status' => $plan->status,
                'requirement_count' => $requirements->count(),
            ],
        );

        return response()->json([
            'message' => $plan->status === 'active'
                ? 'Monitoring plan activated.'
                : 'Monitoring plan saved as a draft.',
            'plan' => $this->recipientMonitoringPlanPayload(
                $plan->load(['requirements', 'creator', 'updater']),
            ),
        ]);
    }

    public function storeRecipientMonitoringCycle(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'period_type' => ['required', Rule::in(['semester', 'quarter', 'monthly', 'custom'])],
            'academic_period' => ['nullable', 'string', 'max:80'],
            'school_year' => ['nullable', 'string', 'max:30'],
            'opens_at' => ['nullable', 'date', 'after_or_equal:today'],
            'due_at' => ['required', 'date', 'after_or_equal:today'],
            'minimum_grade' => ['required', 'numeric'],
            'grading_scale' => ['required', Rule::in([
                AcademicRequirement::SCALE_PERCENTAGE,
                AcademicRequirement::SCALE_GRADE_POINT,
            ])],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        if (filled($validated['opens_at'] ?? null)
            && CarbonImmutable::parse($validated['due_at'])->isBefore(CarbonImmutable::parse($validated['opens_at']))) {
            throw ValidationException::withMessages([
                'due_at' => 'The due date must be on or after the opening date.',
            ]);
        }

        $minimumGrade = (float) $validated['minimum_grade'];
        $validGrade = $validated['grading_scale'] === AcademicRequirement::SCALE_GRADE_POINT
            ? $minimumGrade >= 1 && $minimumGrade <= 5
            : $minimumGrade >= 0 && $minimumGrade <= 100;

        if (! $validGrade) {
            throw ValidationException::withMessages([
                'minimum_grade' => $validated['grading_scale'] === AcademicRequirement::SCALE_GRADE_POINT
                    ? 'Enter a grade point from 1.00 to 5.00.'
                    : 'Enter a percentage from 0 to 100.',
            ]);
        }

        $applications = $this->selectedRecipientApplications($scholarship);

        if ($applications->isEmpty()) {
            throw ValidationException::withMessages([
                'recipients' => 'Select at least one scholarship recipient before publishing a monitoring period.',
            ]);
        }

        $cycle = DB::transaction(function () use ($request, $scholarship, $validated, $applications): RecipientMonitoringCycle {
            $cycle = $scholarship->monitoringCycles()->create([
                ...$validated,
                'created_by' => $request->user()->id,
                'status' => 'open',
                'published_at' => now(),
            ]);

            foreach ($applications as $application) {
                PortalNotification::query()->updateOrCreate([
                    'deduplication_key' => "recipient-monitoring:{$cycle->id}:application:{$application->id}",
                ], [
                    'user_id' => $application->applicant_id,
                    'type' => 'recipient_monitoring_request',
                    'title' => 'Academic progress update requested',
                    'message' => "{$scholarship->title}: upload your {$cycle->title} grade record by {$cycle->due_at->format('M d, Y')}.",
                    'action_url' => route('dashboard.monitoring.show', $application, false),
                    'read_at' => null,
                ]);
            }

            return $cycle;
        });

        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_cycle_published',
            "{$request->user()->name} published {$cycle->title} for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'monitoring_cycle_id' => $cycle->id,
                'recipient_count' => $applications->count(),
            ],
        );

        return response()->json([
            'message' => "Monitoring period published to {$applications->count()} selected recipient".($applications->count() === 1 ? '.' : 's.'),
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->load(['creator', 'submissions.reviewer', 'submissions.reviews.reviewer']),
                $applications,
            ),
        ], 201);
    }

    public function storeRecipientMonitoringCheckIn(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'period_label' => ['nullable', 'string', 'max:80'],
            'school_year' => ['nullable', 'string', 'max:30'],
            'opens_at' => ['nullable', 'date', 'after_or_equal:today'],
            'due_at' => ['required', 'date', 'after_or_equal:today'],
            'instructions' => ['nullable', 'string', 'max:2000'],
        ]);

        if (filled($validated['opens_at'] ?? null)
            && CarbonImmutable::parse($validated['due_at'])->isBefore(CarbonImmutable::parse($validated['opens_at']))) {
            throw ValidationException::withMessages([
                'due_at' => 'The due date must be on or after the opening date.',
            ]);
        }

        $plan = $scholarship->monitoringPlan()
            ->with('requirements')
            ->where('status', 'active')
            ->first();

        if (! $plan || $plan->requirements->isEmpty()) {
            throw ValidationException::withMessages([
                'plan' => 'Activate a monitoring plan with at least one requirement before publishing a check-in.',
            ]);
        }

        $dueAt = CarbonImmutable::parse($validated['due_at']);
        if ($plan->starts_on && $dueAt->isBefore($plan->starts_on)) {
            throw ValidationException::withMessages([
                'due_at' => 'The due date must be within the monitoring plan period.',
            ]);
        }
        if ($plan->ends_on && $dueAt->isAfter($plan->ends_on)) {
            throw ValidationException::withMessages([
                'due_at' => 'The due date must be within the monitoring plan period.',
            ]);
        }

        $applications = $this->selectedRecipientApplications($scholarship);
        if ($applications->isEmpty()) {
            throw ValidationException::withMessages([
                'recipients' => 'Select at least one scholarship recipient before publishing a check-in.',
            ]);
        }

        $academicRequirement = $plan->requirements
            ->firstWhere('type', RecipientMonitoringRequirementType::ACADEMIC_PROGRESS);
        $periodType = match ($plan->frequency) {
            'monthly' => 'monthly',
            'quarterly' => 'quarter',
            'semester' => 'semester',
            default => 'custom',
        };

        $cycle = DB::transaction(function () use (
            $request,
            $scholarship,
            $validated,
            $applications,
            $plan,
            $academicRequirement,
            $periodType,
        ): RecipientMonitoringCycle {
            $cycle = $scholarship->monitoringCycles()->create([
                'recipient_monitoring_plan_id' => $plan->id,
                'monitoring_plan_version' => $plan->version,
                'grace_period_days' => $plan->grace_period_days,
                'allow_exception_requests' => $plan->allow_exception_requests,
                'created_by' => $request->user()->id,
                'title' => Str::squish($validated['title']),
                'period_type' => $periodType,
                'academic_period' => trim((string) ($validated['period_label'] ?? '')) ?: null,
                'school_year' => trim((string) ($validated['school_year'] ?? '')) ?: null,
                'opens_at' => $validated['opens_at'] ?? null,
                'due_at' => $validated['due_at'],
                'minimum_grade' => $academicRequirement?->minimum_grade ?? 0,
                'grading_scale' => $academicRequirement?->grading_scale ?? AcademicRequirement::SCALE_PERCENTAGE,
                'instructions' => trim((string) ($validated['instructions'] ?? '')) ?: $plan->instructions,
                'status' => 'open',
                'published_at' => now(),
            ]);

            foreach ($plan->requirements as $requirement) {
                $cycle->requirements()->create([
                    'source_requirement_id' => $requirement->id,
                    'type' => $requirement->type,
                    'title' => $requirement->title,
                    'description' => $requirement->description,
                    'evidence_description' => $requirement->evidence_description,
                    'required' => $requirement->required,
                    'requires_file' => $requirement->requires_file,
                    'requires_original_verification' => $requirement->requires_original_verification,
                    'minimum_grade' => $requirement->minimum_grade,
                    'grading_scale' => $requirement->grading_scale,
                    'sort_order' => $requirement->sort_order,
                ]);
            }

            $uploadCount = $plan->requirements->where('requires_file', true)->count();
            foreach ($applications as $application) {
                PortalNotification::query()->updateOrCreate([
                    'deduplication_key' => "recipient-monitoring-check-in:{$cycle->id}:application:{$application->id}",
                ], [
                    'user_id' => $application->applicant_id,
                    'type' => 'recipient_monitoring_request',
                    'title' => 'Monitoring check-in available',
                    'message' => "{$scholarship->title}: {$uploadCount} item".($uploadCount === 1 ? ' is' : 's are')." ready by {$cycle->due_at->format('M d, Y')}.",
                    'action_url' => route('dashboard.monitoring.show', $application, false),
                    'read_at' => null,
                ]);
            }

            return $cycle;
        });

        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_check_in_published',
            "{$request->user()->name} published {$cycle->title} from monitoring plan version {$plan->version}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'monitoring_cycle_id' => $cycle->id,
                'monitoring_plan_id' => $plan->id,
                'monitoring_plan_version' => $plan->version,
                'requirement_count' => $plan->requirements->count(),
                'recipient_count' => $applications->count(),
            ],
        );

        return response()->json([
            'message' => "Check-in published to {$applications->count()} recipient".($applications->count() === 1 ? '.' : 's.'),
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->load(['creator', 'requirements', 'submissions.requirement', 'submissions.reviewer', 'submissions.reviews.reviewer']),
                $applications,
            ),
        ], 201);
    }

    public function reviewRecipientMonitoringSubmission(
        Request $request,
        RecipientMonitoringSubmission $submission,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $submission->loadMissing(['cycle.scholarship', 'requirement', 'application.applicant']);
        $scholarship = $submission->cycle?->scholarship;
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['met', 'not_met', 'needs_correction', 'excused'])],
            'notes' => [
                Rule::requiredIf(in_array($request->input('decision'), ['not_met', 'needs_correction', 'excused'], true)),
                'nullable',
                'string',
                'min:5',
                'max:1500',
            ],
        ]);
        $grade = $submission->grade_source === 'applicant_manual'
            ? $submission->reported_grade
            : ($submission->ocr_grade ?? $submission->reported_grade);
        $isAcademic = ($submission->requirement?->type ?? RecipientMonitoringRequirementType::ACADEMIC_PROGRESS)
            === RecipientMonitoringRequirementType::ACADEMIC_PROGRESS;

        if ($isAcademic && in_array($validated['decision'], ['met', 'not_met'], true) && $grade === null) {
            throw ValidationException::withMessages([
                'decision' => 'A readable or applicant-entered grade is required before confirming whether the requirement was met.',
            ]);
        }

        if ($validated['decision'] === 'needs_correction' && blank($submission->path)) {
            throw ValidationException::withMessages([
                'decision' => 'A replacement can only be requested for an uploaded record.',
            ]);
        }

        DB::transaction(function () use ($request, $submission, $validated): void {
            $now = now();
            $submission->update([
                'review_status' => $validated['decision'],
                'review_notes' => $validated['notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => $now,
            ]);
            $submission->reviews()->create([
                'reviewed_by' => $request->user()->id,
                'decision' => $validated['decision'],
                'notes' => $validated['notes'] ?? null,
                'decided_at' => $now,
            ]);
        });

        $application = $submission->application;
        $cycle = $submission->cycle;
        $requirementTitle = $submission->requirement?->title ?? $cycle->title;
        $decisionLabels = [
            'met' => 'Requirement confirmed',
            'not_met' => 'Requirement not met',
            'needs_correction' => 'Replacement record requested',
            'excused' => 'Monitoring exception approved',
        ];
        $decisionMessages = [
            'met' => "Your {$requirementTitle} record was verified and meets the listed requirement.",
            'not_met' => "Your {$requirementTitle} record does not meet the listed requirement. Open monitoring to read the provider note.",
            'needs_correction' => "The provider requested a replacement or clarification for your {$requirementTitle} record.",
            'excused' => "The provider approved an exception for {$requirementTitle}.",
        ];
        PortalNotification::query()->updateOrCreate([
            'deduplication_key' => "recipient-monitoring-review:{$submission->id}:{$validated['decision']}",
        ], [
            'user_id' => $application->applicant_id,
            'type' => 'recipient_monitoring_review',
            'title' => $decisionLabels[$validated['decision']],
            'message' => $decisionMessages[$validated['decision']],
            'action_url' => route('dashboard.monitoring.show', $application, false),
            'read_at' => null,
        ]);

        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_submission_reviewed',
            "{$request->user()->name} recorded {$validated['decision']} for {$application->applicant?->name}'s {$cycle->title} submission.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'application_id' => $application->id,
                'monitoring_cycle_id' => $cycle->id,
                'submission_id' => $submission->id,
                'decision' => $validated['decision'],
            ],
        );

        $applications = $this->selectedRecipientApplications($scholarship);

        return response()->json([
            'message' => $decisionLabels[$validated['decision']].'.',
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->fresh()->load(['creator', 'requirements', 'submissions.requirement', 'submissions.reviewer', 'submissions.reviews.reviewer']),
                $applications,
            ),
        ]);
    }

    public function recordRecipientMonitoringRequirement(
        Request $request,
        RecipientMonitoringCycleRequirement $requirement,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $requirement->loadMissing('cycle.scholarship');
        $cycle = $requirement->cycle;
        $scholarship = $cycle?->scholarship;
        abort_unless($cycle && $scholarship, 404);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        abort_unless($application->scholarship_id === $scholarship->id, 404);
        abort_if($requirement->requires_file, 422, 'This requirement must be submitted by the recipient.');

        $applications = $this->selectedRecipientApplications($scholarship);
        abort_unless($applications->contains('id', $application->id), 422, 'This applicant is not an active recipient of the program.');

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['met', 'not_met', 'excused'])],
            'notes' => [
                Rule::requiredIf(in_array($request->input('decision'), ['not_met', 'excused'], true)),
                'nullable',
                'string',
                'min:5',
                'max:1500',
            ],
        ]);

        $submission = DB::transaction(function () use ($request, $requirement, $application, $cycle, $validated): RecipientMonitoringSubmission {
            $now = now();
            $submission = RecipientMonitoringSubmission::query()->updateOrCreate([
                'recipient_monitoring_cycle_requirement_id' => $requirement->id,
                'scholarship_application_id' => $application->id,
            ], [
                'recipient_monitoring_cycle_id' => $cycle->id,
                'applicant_id' => $application->applicant_id,
                'submission_source' => 'provider_record',
                'applicant_note' => null,
                'original_name' => null,
                'path' => null,
                'mime_type' => null,
                'size' => 0,
                'ocr_status' => AcademicRecordOcrService::STATUS_NOT_REQUESTED,
                'ocr_provider' => null,
                'ocr_grade' => null,
                'ocr_grading_scale' => null,
                'ocr_label' => null,
                'ocr_message' => null,
                'ocr_processed_at' => null,
                'reported_grade' => null,
                'reported_grading_scale' => null,
                'grade_source' => null,
                'submitted_at' => $now,
                'review_status' => $validated['decision'],
                'review_notes' => $validated['notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => $now,
            ]);
            $submission->reviews()->create([
                'reviewed_by' => $request->user()->id,
                'decision' => $validated['decision'],
                'notes' => $validated['notes'] ?? null,
                'decided_at' => $now,
            ]);

            return $submission;
        });

        $decisionLabels = [
            'met' => 'Provider result confirmed',
            'not_met' => 'Provider follow-up required',
            'excused' => 'Monitoring exception approved',
        ];
        $decisionMessages = [
            'met' => "The provider confirmed {$requirement->title} for {$cycle->title}.",
            'not_met' => "The provider recorded that {$requirement->title} was not completed. Open monitoring to read the note.",
            'excused' => "The provider approved an exception for {$requirement->title}.",
        ];
        PortalNotification::query()->updateOrCreate([
            'deduplication_key' => "recipient-monitoring-review:{$submission->id}:{$validated['decision']}",
        ], [
            'user_id' => $application->applicant_id,
            'type' => 'recipient_monitoring_review',
            'title' => $decisionLabels[$validated['decision']],
            'message' => $decisionMessages[$validated['decision']],
            'action_url' => route('dashboard.monitoring.show', $application, false),
            'read_at' => null,
        ]);

        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_provider_result_recorded',
            "{$request->user()->name} recorded {$validated['decision']} for {$application->applicant?->name}'s {$requirement->title} requirement.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'application_id' => $application->id,
                'monitoring_cycle_id' => $cycle->id,
                'monitoring_requirement_id' => $requirement->id,
                'submission_id' => $submission->id,
                'decision' => $validated['decision'],
            ],
        );

        return response()->json([
            'message' => $decisionLabels[$validated['decision']].'.',
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->fresh()->load(['creator', 'requirements', 'submissions.requirement', 'submissions.reviewer', 'submissions.reviews.reviewer']),
                $this->selectedRecipientApplications($scholarship),
            ),
        ], $submission->wasRecentlyCreated ? 201 : 200);
    }

    public function decideRecipientMonitoringAdjustmentRequest(
        Request $request,
        RecipientMonitoringAdjustmentRequest $adjustment,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $adjustment->loadMissing([
            'cycle.scholarship',
            'requirement',
            'application.applicant',
        ]);
        $cycle = $adjustment->cycle;
        $scholarship = $cycle?->scholarship;
        abort_unless($cycle && $scholarship && $adjustment->requirement && $adjustment->application, 404);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        if ($adjustment->status !== 'pending') {
            throw ValidationException::withMessages([
                'decision' => 'This request already has a provider decision.',
            ]);
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'declined'])],
            'decision_notes' => [
                Rule::requiredIf($request->input('decision') === 'declined'),
                'nullable',
                'string',
                'min:5',
                'max:1500',
            ],
            'approved_due_at' => [
                Rule::requiredIf(
                    $request->input('decision') === 'approved'
                    && $adjustment->request_type === 'extension'
                ),
                'nullable',
                'date',
                'after:'.$cycle->due_at->format('Y-m-d'),
                'before_or_equal:'.$cycle->due_at->copy()->addDays(60)->format('Y-m-d'),
            ],
        ]);

        DB::transaction(function () use ($request, $adjustment, $validated, $cycle): void {
            $now = now();
            $adjustment->update([
                'status' => $validated['decision'],
                'decision_notes' => trim((string) ($validated['decision_notes'] ?? '')) ?: null,
                'approved_due_at' => $validated['decision'] === 'approved'
                    && $adjustment->request_type === 'extension'
                        ? $validated['approved_due_at']
                        : null,
                'decided_by' => $request->user()->id,
                'decided_at' => $now,
            ]);

            if ($validated['decision'] !== 'approved' || $adjustment->request_type !== 'exception') {
                return;
            }

            $submission = RecipientMonitoringSubmission::query()->firstOrNew([
                'recipient_monitoring_cycle_requirement_id' => $adjustment->recipient_monitoring_cycle_requirement_id,
                'scholarship_application_id' => $adjustment->scholarship_application_id,
            ]);
            if (! $submission->exists) {
                $submission->fill([
                    'recipient_monitoring_cycle_id' => $cycle->id,
                    'applicant_id' => $adjustment->applicant_id,
                    'submission_source' => 'provider_record',
                    'applicant_note' => null,
                    'original_name' => null,
                    'path' => null,
                    'mime_type' => null,
                    'size' => 0,
                    'ocr_status' => AcademicRecordOcrService::STATUS_NOT_REQUESTED,
                    'submitted_at' => $now,
                ]);
            }
            $submission->fill([
                'review_status' => 'excused',
                'review_notes' => trim((string) ($validated['decision_notes'] ?? ''))
                    ?: 'Exception approved from the recipient request.',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => $now,
            ])->save();
            $submission->reviews()->create([
                'reviewed_by' => $request->user()->id,
                'decision' => 'excused',
                'notes' => $submission->review_notes,
                'decided_at' => $now,
            ]);
        });

        $application = $adjustment->application;
        $approved = $validated['decision'] === 'approved';
        $title = $approved ? 'Monitoring request approved' : 'Monitoring request declined';
        $result = $approved
            ? ($adjustment->request_type === 'extension'
                ? 'Your monitoring extension was approved.'
                : 'Your monitoring exception was approved.')
            : 'Your monitoring request was not approved.';
        PortalNotification::query()->updateOrCreate([
            'deduplication_key' => "recipient-monitoring-adjustment-decision:{$adjustment->id}",
        ], [
            'user_id' => $application->applicant_id,
            'type' => 'recipient_monitoring_adjustment_decision',
            'title' => $title,
            'message' => "{$result} Open monitoring to review the provider note.",
            'action_url' => route('dashboard.monitoring.show', $application, false),
            'read_at' => null,
        ]);

        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_adjustment_decided',
            "{$request->user()->name} {$validated['decision']} a monitoring {$adjustment->request_type} request.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'application_id' => $application->id,
                'monitoring_cycle_id' => $cycle->id,
                'monitoring_requirement_id' => $adjustment->recipient_monitoring_cycle_requirement_id,
                'adjustment_request_id' => $adjustment->id,
                'decision' => $validated['decision'],
            ],
        );

        return response()->json([
            'message' => $title.'.',
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->fresh()->load([
                    'creator',
                    'requirements',
                    'submissions.requirement',
                    'submissions.reviewer',
                    'submissions.reviews.reviewer',
                    'adjustmentRequests.decider',
                    'interventions.creator',
                ]),
                $this->selectedRecipientApplications($scholarship),
            ),
        ]);
    }

    public function viewRecipientMonitoringAdjustmentAttachment(
        Request $request,
        RecipientMonitoringAdjustmentRequest $adjustment,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        $adjustment->loadMissing('cycle.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($adjustment->cycle?->scholarship), 403);
        abort_unless(filled($adjustment->attachment_path)
            && Storage::disk('local')->exists($adjustment->attachment_path), 404);

        return Storage::disk('local')->response(
            $adjustment->attachment_path,
            $adjustment->attachment_original_name,
            [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function storeRecipientMonitoringIntervention(
        Request $request,
        RecipientMonitoringCycleRequirement $requirement,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $requirement->loadMissing('cycle.scholarship');
        $cycle = $requirement->cycle;
        $scholarship = $cycle?->scholarship;
        abort_unless($cycle && $scholarship, 404);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);
        abort_unless($application->scholarship_id === $scholarship->id, 404);
        abort_unless($this->selectedRecipientApplications($scholarship)->contains('id', $application->id), 422);

        $validated = $request->validate([
            'type' => ['required', Rule::in(['reminder', 'consultation', 'support_plan', 'warning'])],
            'summary' => ['required', 'string', 'min:5', 'max:1000'],
            'action_required' => ['nullable', 'string', 'max:1000'],
            'follow_up_on' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $intervention = RecipientMonitoringIntervention::create([
            'recipient_monitoring_cycle_id' => $cycle->id,
            'recipient_monitoring_cycle_requirement_id' => $requirement->id,
            'scholarship_application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'created_by' => $request->user()->id,
            'type' => $validated['type'],
            'summary' => trim($validated['summary']),
            'action_required' => trim((string) ($validated['action_required'] ?? '')) ?: null,
            'follow_up_on' => $validated['follow_up_on'] ?? null,
            'status' => 'open',
        ]);

        PortalNotification::create([
            'user_id' => $application->applicant_id,
            'type' => 'recipient_monitoring_intervention',
            'title' => 'Provider monitoring follow-up',
            'message' => "The provider added a follow-up for {$requirement->title}.",
            'action_url' => route('dashboard.monitoring.show', $application, false),
        ]);
        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_intervention_created',
            "{$request->user()->name} added a {$validated['type']} intervention for {$application->applicant?->name}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'application_id' => $application->id,
                'monitoring_cycle_id' => $cycle->id,
                'monitoring_requirement_id' => $requirement->id,
                'intervention_id' => $intervention->id,
            ],
        );

        return response()->json([
            'message' => 'Follow-up recorded and shared with the recipient.',
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->fresh()->load([
                    'creator',
                    'requirements',
                    'submissions.requirement',
                    'submissions.reviewer',
                    'submissions.reviews.reviewer',
                    'adjustmentRequests.decider',
                    'interventions.creator',
                ]),
                $this->selectedRecipientApplications($scholarship),
            ),
        ], 201);
    }

    public function completeRecipientMonitoringIntervention(
        Request $request,
        RecipientMonitoringIntervention $intervention,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $intervention->loadMissing(['cycle.scholarship', 'requirement', 'application.applicant']);
        $cycle = $intervention->cycle;
        $scholarship = $cycle?->scholarship;
        abort_unless($cycle && $scholarship && $intervention->application, 404);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        if ($intervention->status !== 'open') {
            throw ValidationException::withMessages([
                'intervention' => 'This follow-up is already completed.',
            ]);
        }
        $validated = $request->validate([
            'completion_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $intervention->update([
            'status' => 'completed',
            'completion_notes' => trim((string) ($validated['completion_notes'] ?? '')) ?: null,
            'completed_at' => now(),
        ]);

        PortalNotification::create([
            'user_id' => $intervention->applicant_id,
            'type' => 'recipient_monitoring_intervention_completed',
            'title' => 'Monitoring follow-up completed',
            'message' => "The provider closed the follow-up for {$intervention->requirement?->title}.",
            'action_url' => route('dashboard.monitoring.show', $intervention->application, false),
        ]);
        ActivityLog::record(
            $request->user(),
            'recipient_monitoring_intervention_completed',
            "{$request->user()->name} completed a monitoring intervention.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'application_id' => $intervention->scholarship_application_id,
                'monitoring_cycle_id' => $cycle->id,
                'monitoring_requirement_id' => $intervention->recipient_monitoring_cycle_requirement_id,
                'intervention_id' => $intervention->id,
            ],
        );

        return response()->json([
            'message' => 'Follow-up marked complete.',
            'cycle' => $this->recipientMonitoringCyclePayload(
                $cycle->fresh()->load([
                    'creator',
                    'requirements',
                    'submissions.requirement',
                    'submissions.reviewer',
                    'submissions.reviews.reviewer',
                    'adjustmentRequests.decider',
                    'interventions.creator',
                ]),
                $this->selectedRecipientApplications($scholarship),
            ),
        ]);
    }

    public function storeRecipientBenefitRelease(Request $request, Scholarship $scholarship): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'release_at' => ['required', 'date', 'after_or_equal:today'],
            'benefit_description' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'release_method' => ['required', Rule::in(['in_person', 'bank_transfer', 'e_wallet', 'other'])],
            'location' => [Rule::requiredIf($request->input('release_method') === 'in_person'), 'nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'requires_original_verification' => ['required', 'boolean'],
            'recipient_ids' => ['required', 'array', 'min:1'],
            'recipient_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $releaseAt = CarbonImmutable::parse($validated['release_at']);
        $applications = $this->selectedRecipientApplications($scholarship);
        $selectedApplications = $applications->whereIn('id', $validated['recipient_ids'])->values();

        if ($selectedApplications->count() !== count($validated['recipient_ids'])) {
            throw ValidationException::withMessages([
                'recipient_ids' => 'One or more selected recipients are not available for this program.',
            ]);
        }

        $cycles = $scholarship->monitoringCycles()
            ->with('submissions')
            ->get();
        $blocked = $selectedApplications
            ->map(function (ScholarshipApplication $application) use ($cycles, $releaseAt): ?string {
                $eligibility = $this->recipientBenefitReleaseEligibility($application, $cycles, $releaseAt);

                return $eligibility['eligible']
                    ? null
                    : "{$application->applicant?->name}: {$eligibility['reason']}";
            })
            ->filter()
            ->values();

        if ($blocked->isNotEmpty()) {
            throw ValidationException::withMessages([
                'recipient_ids' => 'Resolve these recipient requirements first: '.$blocked->implode(' '),
            ]);
        }

        $release = DB::transaction(function () use ($request, $scholarship, $validated, $selectedApplications): RecipientBenefitRelease {
            $release = $scholarship->benefitReleases()->create([
                'created_by' => $request->user()->id,
                'title' => $validated['title'],
                'release_at' => $validated['release_at'],
                'benefit_description' => $validated['benefit_description'],
                'amount' => $validated['amount'] ?? null,
                'release_method' => $validated['release_method'],
                'location' => $validated['location'] ?? null,
                'instructions' => $validated['instructions'] ?? null,
                'requires_original_verification' => $validated['requires_original_verification'],
                'status' => 'scheduled',
                'published_at' => now(),
            ]);

            foreach ($selectedApplications as $application) {
                $release->records()->create([
                    'scholarship_application_id' => $application->id,
                    'applicant_id' => $application->applicant_id,
                    'status' => 'scheduled',
                ]);
                PortalNotification::query()->updateOrCreate([
                    'deduplication_key' => "recipient-benefit-release:{$release->id}:application:{$application->id}",
                ], [
                    'user_id' => $application->applicant_id,
                    'type' => 'recipient_benefit_release',
                    'title' => 'Benefit release scheduled',
                    'message' => "{$scholarship->title}: {$release->title} is scheduled for {$release->release_at->format('M d, Y h:i A')}.",
                    'action_url' => route('dashboard.monitoring.show', $application, false),
                    'read_at' => null,
                ]);
            }

            return $release;
        });

        ActivityLog::record(
            $request->user(),
            'recipient_benefit_release_scheduled',
            "{$request->user()->name} scheduled {$release->title} for {$scholarship->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'benefit_release_id' => $release->id,
                'recipient_count' => $selectedApplications->count(),
            ],
        );

        return response()->json([
            'message' => "Benefit release scheduled for {$selectedApplications->count()} recipient".($selectedApplications->count() === 1 ? '.' : 's.'),
            'release' => $this->recipientBenefitReleasePayload(
                $release->load(['creator', 'records.applicant', 'records.recorder']),
            ),
        ], 201);
    }

    public function recordRecipientBenefitRelease(
        Request $request,
        RecipientBenefitReleaseRecord $record,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $record->loadMissing(['release.scholarship', 'application.applicant', 'receiptResponse']);
        $release = $record->release;
        $scholarship = $release?->scholarship;
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        if ($record->status === 'released' || $record->receiptResponse) {
            throw ValidationException::withMessages([
                'status' => 'A released record is locked. Resolve any recipient issue through the receipt response instead.',
            ]);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['prepared', 'released', 'missed', 'withheld'])],
            'originals_verified' => ['nullable', 'boolean'],
            'notes' => [
                Rule::requiredIf(in_array($request->input('status'), ['missed', 'withheld'], true)),
                'nullable',
                'string',
                'min:5',
                'max:1500',
            ],
            'receipt_proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $status = $validated['status'];
        $originalsVerified = (bool) ($validated['originals_verified'] ?? false);

        if ($status === 'released' && $release->release_at?->isFuture()) {
            throw ValidationException::withMessages([
                'status' => 'The benefit cannot be marked released before its scheduled date and time.',
            ]);
        }

        if ($status === 'released' && $release->requires_original_verification && ! $originalsVerified) {
            throw ValidationException::withMessages([
                'originals_verified' => 'Confirm that the original documents were checked before recording this release.',
            ]);
        }

        $receipt = $request->file('receipt_proof');
        if ($status === 'released'
            && ! $receipt
            && blank($record->receipt_path)
            && blank($validated['notes'] ?? null)) {
            throw ValidationException::withMessages([
                'receipt_proof' => 'Upload acknowledgement proof or enter a note describing how receipt was confirmed.',
            ]);
        }

        $newReceiptPath = $receipt?->store("benefit-release-receipts/{$record->id}", 'local');
        $oldReceiptPath = $record->receipt_path;

        try {
            DB::transaction(function () use ($request, $record, $validated, $status, $originalsVerified, $receipt, $newReceiptPath): void {
                $record->update([
                    'status' => $status,
                    'originals_verified' => $originalsVerified,
                    'notes' => $validated['notes'] ?? null,
                    'receipt_original_name' => $receipt?->getClientOriginalName() ?? $record->receipt_original_name,
                    'receipt_path' => $newReceiptPath ?? $record->receipt_path,
                    'receipt_mime_type' => $receipt?->getMimeType() ?? $record->receipt_mime_type,
                    'receipt_size' => $receipt?->getSize() ?? $record->receipt_size,
                    'recorded_by' => $request->user()->id,
                    'recorded_at' => now(),
                    'released_at' => $status === 'released' ? now() : null,
                ]);

                $statuses = $record->release->records()->pluck('status');
                $terminal = ['released', 'missed', 'withheld'];
                $releaseStatus = $statuses->every(fn (string $value): bool => in_array($value, $terminal, true))
                    ? 'completed'
                    : ($statuses->contains(fn (string $value): bool => $value !== 'scheduled') ? 'in_progress' : 'scheduled');
                $record->release->update(['status' => $releaseStatus]);
            });
        } catch (Throwable $error) {
            if ($newReceiptPath) {
                Storage::disk('local')->delete($newReceiptPath);
            }

            throw $error;
        }

        if ($newReceiptPath && filled($oldReceiptPath) && $oldReceiptPath !== $newReceiptPath) {
            Storage::disk('local')->delete($oldReceiptPath);
        }

        $statusLabels = [
            'prepared' => 'Benefit prepared',
            'released' => 'Benefit received',
            'missed' => 'Release appointment missed',
            'withheld' => 'Benefit release withheld',
        ];
        PortalNotification::query()->updateOrCreate([
            'deduplication_key' => "recipient-benefit-release-result:{$record->id}:{$status}",
        ], [
            'user_id' => $record->applicant_id,
            'type' => 'recipient_benefit_release_result',
            'title' => $statusLabels[$status],
            'message' => "{$release->title}: the provider recorded your status as {$statusLabels[$status]}.",
            'action_url' => route('dashboard.monitoring.show', $record->application, false),
            'read_at' => null,
        ]);

        ActivityLog::record(
            $request->user(),
            'recipient_benefit_release_recorded',
            "{$request->user()->name} recorded {$status} for {$record->application?->applicant?->name} in {$release->title}.",
            $request,
            [
                'scholarship_id' => $scholarship->id,
                'benefit_release_id' => $release->id,
                'benefit_release_record_id' => $record->id,
                'application_id' => $record->scholarship_application_id,
                'status' => $status,
            ],
        );

        return response()->json([
            'message' => $statusLabels[$status].'.',
            'release' => $this->recipientBenefitReleasePayload(
                $release->fresh()->load(['creator', 'records.applicant', 'records.recorder', 'records.receiptResponse.resolver']),
            ),
        ]);
    }

    public function resolveRecipientBenefitReceiptResponse(
        Request $request,
        RecipientBenefitReceiptResponse $response,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $response->loadMissing(['releaseRecord.release.scholarship', 'application', 'applicant']);
        $record = $response->releaseRecord;
        $release = $record?->release;
        $scholarship = $release?->scholarship;
        abort_unless($request->user()->canAccessProviderProgram($scholarship), 403);

        if ($response->response_type !== 'issue' || $response->status !== 'open') {
            throw ValidationException::withMessages([
                'resolution_outcome' => 'This receipt response does not have an open issue to resolve.',
            ]);
        }

        $validated = $request->validate([
            'resolution_outcome' => ['required', Rule::in([
                'corrected_release',
                'replacement_scheduled',
                'delivery_confirmed',
                'no_change',
            ])],
            'resolution_notes' => ['required', 'string', 'min:5', 'max:1500'],
            'resolution_proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $proof = $request->file('resolution_proof');
        $proofPath = $proof?->store("benefit-receipt-responses/{$record->id}/resolution", 'local');

        try {
            $response->update([
                'status' => 'resolved',
                'resolution_outcome' => $validated['resolution_outcome'],
                'resolution_notes' => $validated['resolution_notes'],
                'resolution_proof_original_name' => $proof?->getClientOriginalName(),
                'resolution_proof_path' => $proofPath,
                'resolution_proof_mime_type' => $proof?->getMimeType(),
                'resolution_proof_size' => $proof?->getSize() ?: 0,
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
            ]);
        } catch (Throwable $error) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            throw $error;
        }

        PortalNotification::create([
            'user_id' => $response->applicant_id,
            'type' => 'benefit_receipt_issue_resolved',
            'title' => 'Benefit release issue updated',
            'message' => "The provider resolved your report for {$release?->title}.",
            'action_url' => route('dashboard.monitoring.show', $response->application, false),
        ]);

        ActivityLog::record(
            $request->user(),
            'benefit_receipt_issue_resolved',
            "{$request->user()->name} resolved a benefit release issue for {$response->applicant?->name}.",
            $request,
            [
                'scholarship_id' => $scholarship?->id,
                'application_id' => $response->scholarship_application_id,
                'benefit_release_record_id' => $record?->id,
                'benefit_receipt_response_id' => $response->id,
                'resolution_outcome' => $validated['resolution_outcome'],
            ],
        );

        return response()->json([
            'message' => 'Recipient issue marked resolved.',
            'release' => $this->recipientBenefitReleasePayload(
                $release->fresh()->load(['creator', 'records.applicant.studentProfile', 'records.recorder', 'records.receiptResponse.resolver']),
            ),
        ]);
    }

    public function viewRecipientBenefitReceiptResponseFile(
        Request $request,
        RecipientBenefitReceiptResponse $response,
        string $kind,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        $response->loadMissing('releaseRecord.release.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($response->releaseRecord?->release?->scholarship), 403);

        $isResolutionProof = $kind === 'resolution-proof';
        $path = $isResolutionProof ? $response->resolution_proof_path : $response->evidence_path;
        $name = $isResolutionProof ? $response->resolution_proof_original_name : $response->evidence_original_name;
        abort_unless(filled($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function viewRecipientBenefitReleaseReceipt(
        Request $request,
        RecipientBenefitReleaseRecord $record,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        $record->loadMissing('release.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($record->release?->scholarship), 403);
        abort_unless(filled($record->receipt_path) && Storage::disk('local')->exists($record->receipt_path), 404);

        return Storage::disk('local')->response($record->receipt_path, $record->receipt_original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function recipientSupportRecord(
        Request $request,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $application->load([
            'scholarship.provider.providerProfile',
            'scholarship.monitoringCycles.creator',
            'applicant.studentProfile',
            'monitoringSubmissions.cycle',
            'monitoringSubmissions.reviewer',
            'monitoringSubmissions.reviews.reviewer',
            'benefitReleaseRecords.release',
            'benefitReleaseRecords.recorder',
            'supportDecisions.decider',
        ]);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless(
            $application->final_outcome === 'selected'
                || in_array($application->status, [...self::AWARD_SLOT_STATUSES, 'benefits_terminated'], true),
            404,
        );

        return response()->json([
            'record' => $this->recipientSupportRecordPayload($application),
        ]);
    }

    public function recordRecipientSupportDecision(
        Request $request,
        ScholarshipApplication $application,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $application->loadMissing([
            'scholarship.monitoringCycles',
            'applicant',
            'monitoringSubmissions',
            'benefitReleaseRecords.release',
            'supportDecisions.decider',
            'supportDecisions.resolver',
        ]);
        abort_unless($request->user()->canAccessProviderProgram($application->scholarship), 403);
        abort_unless(
            $application->final_outcome === 'selected'
                || in_array($application->status, [...self::AWARD_SLOT_STATUSES, 'benefits_terminated'], true),
            422,
        );

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['renewed', 'completed', 'terminated'])],
            'reason_category' => ['required', Rule::in([
                'requirements_met',
                'support_period_extended',
                'continued_funding',
                'program_completed',
                'support_period_ended',
                'recipient_withdrew',
                'requirement_not_met',
                'document_noncompliance',
                'misrepresentation',
                'provider_funding_ended',
                'other',
            ])],
            'effective_on' => ['required', 'date', 'before_or_equal:today'],
            'support_ends_on' => [Rule::requiredIf($request->input('decision') === 'renewed'), 'nullable', 'date'],
            'next_review_on' => ['nullable', 'date'],
            'notice_given_on' => [Rule::requiredIf($request->input('decision') === 'terminated'), 'nullable', 'date', 'before_or_equal:today'],
            'reason' => [
                Rule::requiredIf(in_array($request->input('decision'), ['completed', 'terminated'], true)),
                'nullable',
                'string',
                'min:10',
                'max:2000',
            ],
            'next_period_terms' => [
                Rule::requiredIf($request->input('decision') === 'renewed'),
                'nullable',
                'string',
                'min:10',
                'max:2000',
            ],
            'decision_document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'confirmed' => ['accepted'],
        ]);

        $allowedCategories = match ($validated['decision']) {
            'renewed' => ['requirements_met', 'support_period_extended', 'continued_funding'],
            'completed' => ['program_completed', 'support_period_ended', 'recipient_withdrew'],
            default => [
                'requirement_not_met',
                'document_noncompliance',
                'misrepresentation',
                'recipient_withdrew',
                'provider_funding_ended',
                'other',
            ],
        };
        if (! in_array($validated['reason_category'], $allowedCategories, true)) {
            throw ValidationException::withMessages([
                'reason_category' => 'Choose a reason category that matches the selected outcome.',
            ]);
        }

        if ($application->student_response_status !== 'accepted') {
            throw ValidationException::withMessages([
                'decision' => 'The applicant must accept the recipient agreement before support can be renewed or closed.',
            ]);
        }

        $effectiveOn = CarbonImmutable::parse($validated['effective_on'])->startOfDay();
        $supportEndsOn = filled($validated['support_ends_on'] ?? null)
            ? CarbonImmutable::parse($validated['support_ends_on'])->startOfDay()
            : null;
        $nextReviewOn = filled($validated['next_review_on'] ?? null)
            ? CarbonImmutable::parse($validated['next_review_on'])->startOfDay()
            : null;
        $noticeGivenOn = filled($validated['notice_given_on'] ?? null)
            ? CarbonImmutable::parse($validated['notice_given_on'])->startOfDay()
            : null;

        if ($supportEndsOn && $supportEndsOn->isBefore($effectiveOn)) {
            throw ValidationException::withMessages([
                'support_ends_on' => 'The renewed support end date must be on or after the effective date.',
            ]);
        }

        if ($nextReviewOn && ($nextReviewOn->isBefore($effectiveOn) || ($supportEndsOn && $nextReviewOn->isAfter($supportEndsOn)))) {
            throw ValidationException::withMessages([
                'next_review_on' => 'The next review date must fall within the renewed support period.',
            ]);
        }

        if ($noticeGivenOn && $noticeGivenOn->isAfter($effectiveOn)) {
            throw ValidationException::withMessages([
                'notice_given_on' => 'The notice date cannot be after the effective date.',
            ]);
        }

        $latestDecision = $application->supportDecisions->first();
        if ($latestDecision && in_array($latestDecision->decision, ['completed', 'terminated'], true)) {
            throw ValidationException::withMessages([
                'decision' => 'This recipient support record is already closed and cannot receive another outcome.',
            ]);
        }

        if ($validated['decision'] === 'renewed') {
            $eligibility = $this->recipientSupportEligibility(
                $application,
                $application->scholarship->monitoringCycles,
                $effectiveOn,
            );

            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages([
                    'decision' => $eligibility['reason'],
                ]);
            }
        }

        if ($validated['decision'] === 'completed') {
            $pendingReleases = $application->benefitReleaseRecords
                ->filter(function (RecipientBenefitReleaseRecord $record) use ($effectiveOn): bool {
                    $releaseAt = $record->release?->release_at;

                    return $releaseAt !== null
                        && $releaseAt->startOfDay()->lte($effectiveOn)
                        && in_array($record->status, ['scheduled', 'prepared'], true);
                });

            if ($pendingReleases->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'decision' => 'Record all benefit releases due by the completion date before closing recipient support.',
                ]);
            }
        }

        $previousStatus = $application->status;
        $nextStatus = match ($validated['decision']) {
            'renewed' => 'renewed',
            'terminated' => 'benefits_terminated',
            default => $previousStatus,
        };
        $decisionDocument = $request->file('decision_document');
        $decisionDocumentPath = $decisionDocument?->store("support-decisions/{$application->id}/provider", 'local');

        try {
            $decision = DB::transaction(function () use (
                $request,
                $application,
                $validated,
                $previousStatus,
                $nextStatus,
                $decisionDocument,
                $decisionDocumentPath,
            ): RecipientSupportDecision {
                $currentStatus = ScholarshipApplication::query()
                    ->whereKey($application->id)
                    ->lockForUpdate()
                    ->value('status');

                if ($currentStatus !== $previousStatus) {
                    throw ValidationException::withMessages([
                        'decision' => 'This recipient record changed. Refresh the page before recording an outcome.',
                    ]);
                }

                $decision = $application->supportDecisions()->create([
                    'applicant_id' => $application->applicant_id,
                    'decision' => $validated['decision'],
                    'reason_category' => $validated['reason_category'],
                    'effective_on' => $validated['effective_on'],
                    'support_ends_on' => $validated['decision'] === 'renewed'
                        ? ($validated['support_ends_on'] ?? null)
                        : null,
                    'next_review_on' => $validated['decision'] === 'renewed'
                        ? ($validated['next_review_on'] ?? null)
                        : null,
                    'notice_given_on' => $validated['decision'] === 'terminated'
                        ? ($validated['notice_given_on'] ?? null)
                        : null,
                    'reason' => $validated['decision'] !== 'renewed'
                        ? ($validated['reason'] ?? null)
                        : null,
                    'next_period_terms' => $validated['decision'] === 'renewed'
                        ? ($validated['next_period_terms'] ?? null)
                        : null,
                    'decision_document_original_name' => $decisionDocument?->getClientOriginalName(),
                    'decision_document_path' => $decisionDocumentPath,
                    'decision_document_mime_type' => $decisionDocument?->getMimeType(),
                    'decision_document_size' => $decisionDocument?->getSize() ?: 0,
                    'decided_by' => $request->user()->id,
                    'decided_at' => now(),
                ]);

                if ($nextStatus !== $previousStatus) {
                    $application->update([
                        'status' => $nextStatus,
                        'outcome_notes' => $validated['reason'] ?? $application->outcome_notes,
                        'outcome_at' => now(),
                        'reviewed_by' => $request->user()->id,
                        'reviewed_at' => now(),
                    ]);
                    ApplicationStatusHistory::create([
                        'scholarship_application_id' => $application->id,
                        'changed_by' => $request->user()->id,
                        'from_status' => $previousStatus,
                        'to_status' => $nextStatus,
                        'review_notes' => $validated['reason'] ?? $validated['next_period_terms'] ?? null,
                        'changed_at' => now(),
                    ]);
                }

                return $decision;
            });
        } catch (Throwable $error) {
            if ($decisionDocumentPath) {
                Storage::disk('local')->delete($decisionDocumentPath);
            }

            throw $error;
        }

        $decisionLabels = [
            'renewed' => 'Support renewed',
            'completed' => 'Support completed',
            'terminated' => 'Support ended early',
        ];
        PortalNotification::create([
            'user_id' => $application->applicant_id,
            'type' => 'recipient_support_decision',
            'title' => $decisionLabels[$validated['decision']],
            'message' => "{$application->scholarship->title}: {$decisionLabels[$validated['decision']]} effective {$decision->effective_on->format('M d, Y')}.",
            'action_url' => route('dashboard.monitoring.show', $application, false),
            'deduplication_key' => "recipient-support-decision:{$decision->id}",
        ]);
        ActivityLog::record(
            $request->user(),
            'recipient_support_decision_recorded',
            "{$request->user()->name} recorded {$validated['decision']} support for {$application->applicant?->name}.",
            $request,
            [
                'scholarship_id' => $application->scholarship_id,
                'application_id' => $application->id,
                'recipient_support_decision_id' => $decision->id,
                'decision' => $validated['decision'],
            ],
        );

        $cycles = $application->scholarship->monitoringCycles()
            ->with('submissions')
            ->get();
        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'monitoringSubmissions',
            'benefitReleaseRecords.release',
            'supportDecisions.decider',
            'supportDecisions.resolver',
        ]);

        return response()->json([
            'message' => $decisionLabels[$validated['decision']].'.',
            'recipient' => $this->recipientSupportPayload($freshApplication, $cycles),
        ]);
    }

    public function resolveRecipientSupportDecisionResponse(
        Request $request,
        RecipientSupportDecision $decision,
    ): JsonResponse {
        abort_unless($request->user()?->isProvider(), 403);
        $decision->loadMissing(['application.scholarship', 'applicant']);
        $application = $decision->application;
        abort_unless($request->user()->canAccessProviderProgram($application?->scholarship), 403);

        if ($decision->response_status !== 'open') {
            throw ValidationException::withMessages([
                'resolution_outcome' => 'This support outcome does not have an open recipient request.',
            ]);
        }

        $validated = $request->validate([
            'resolution_outcome' => ['required', Rule::in(['clarified', 'decision_upheld', 'support_reinstated'])],
            'resolution_notes' => ['required', 'string', 'min:5', 'max:1500'],
            'resolution_proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'support_ends_on' => [Rule::requiredIf($request->input('resolution_outcome') === 'support_reinstated'), 'nullable', 'date', 'after_or_equal:today'],
            'next_review_on' => ['nullable', 'date', 'after_or_equal:today'],
            'next_period_terms' => [
                Rule::requiredIf($request->input('resolution_outcome') === 'support_reinstated'),
                'nullable',
                'string',
                'min:10',
                'max:2000',
            ],
        ]);

        $isReinstatement = $validated['resolution_outcome'] === 'support_reinstated';
        if ($isReinstatement && $decision->decision !== 'terminated') {
            throw ValidationException::withMessages([
                'resolution_outcome' => 'Only an early termination can be resolved by reinstating support.',
            ]);
        }

        $supportEndsOn = filled($validated['support_ends_on'] ?? null)
            ? CarbonImmutable::parse($validated['support_ends_on'])->startOfDay()
            : null;
        $nextReviewOn = filled($validated['next_review_on'] ?? null)
            ? CarbonImmutable::parse($validated['next_review_on'])->startOfDay()
            : null;
        if ($supportEndsOn && $nextReviewOn && $nextReviewOn->isAfter($supportEndsOn)) {
            throw ValidationException::withMessages([
                'next_review_on' => 'The next review date must fall within the reinstated support period.',
            ]);
        }

        $proof = $request->file('resolution_proof');
        $proofPath = $proof?->store("support-decisions/{$application->id}/resolution", 'local');

        try {
            DB::transaction(function () use (
                $request,
                $decision,
                $application,
                $validated,
                $proof,
                $proofPath,
                $isReinstatement,
            ): void {
                $lockedDecision = RecipientSupportDecision::query()
                    ->whereKey($decision->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedDecision->response_status !== 'open') {
                    throw ValidationException::withMessages([
                        'resolution_outcome' => 'This recipient request was already resolved.',
                    ]);
                }

                $lockedDecision->update([
                    'response_status' => 'resolved',
                    'resolution_outcome' => $validated['resolution_outcome'],
                    'resolution_notes' => $validated['resolution_notes'],
                    'resolution_proof_original_name' => $proof?->getClientOriginalName(),
                    'resolution_proof_path' => $proofPath,
                    'resolution_proof_mime_type' => $proof?->getMimeType(),
                    'resolution_proof_size' => $proof?->getSize() ?: 0,
                    'resolved_by' => $request->user()->id,
                    'resolved_at' => now(),
                ]);

                if (! $isReinstatement) {
                    return;
                }

                $currentStatus = ScholarshipApplication::query()
                    ->whereKey($application->id)
                    ->lockForUpdate()
                    ->value('status');
                if ($currentStatus !== 'benefits_terminated') {
                    throw ValidationException::withMessages([
                        'resolution_outcome' => 'The recipient record is no longer in an ended-support state.',
                    ]);
                }

                $application->supportDecisions()->create([
                    'applicant_id' => $application->applicant_id,
                    'decision' => 'renewed',
                    'reason_category' => 'support_reinstated',
                    'effective_on' => now()->toDateString(),
                    'support_ends_on' => $validated['support_ends_on'],
                    'next_review_on' => $validated['next_review_on'] ?? null,
                    'reason' => 'Support reinstated after the recipient request was reviewed.',
                    'next_period_terms' => $validated['next_period_terms'],
                    'decided_by' => $request->user()->id,
                    'decided_at' => now(),
                ]);
                $application->update([
                    'status' => 'renewed',
                    'outcome_notes' => $validated['resolution_notes'],
                    'outcome_at' => now(),
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);
                ApplicationStatusHistory::create([
                    'scholarship_application_id' => $application->id,
                    'changed_by' => $request->user()->id,
                    'from_status' => $currentStatus,
                    'to_status' => 'renewed',
                    'review_notes' => $validated['resolution_notes'],
                    'changed_at' => now(),
                ]);
            });
        } catch (Throwable $error) {
            if ($proofPath) {
                Storage::disk('local')->delete($proofPath);
            }

            throw $error;
        }

        PortalNotification::create([
            'user_id' => $decision->applicant_id,
            'type' => 'support_decision_request_resolved',
            'title' => $isReinstatement ? 'Scholarship support reinstated' : 'Support outcome request resolved',
            'message' => $isReinstatement
                ? "Your support for {$application->scholarship?->title} was reinstated."
                : "The provider responded to your support outcome request for {$application->scholarship?->title}.",
            'action_url' => route('dashboard.monitoring.show', $application, false),
        ]);

        ActivityLog::record(
            $request->user(),
            'recipient_support_decision_response_resolved',
            "{$request->user()->name} resolved a recipient support outcome request.",
            $request,
            [
                'application_id' => $application->id,
                'recipient_support_decision_id' => $decision->id,
                'resolution_outcome' => $validated['resolution_outcome'],
            ],
        );

        $cycles = $application->scholarship->monitoringCycles()
            ->with('submissions')
            ->get();
        $freshApplication = $application->fresh()->load([
            'applicant.studentProfile',
            'monitoringSubmissions',
            'benefitReleaseRecords.release',
            'supportDecisions.decider',
            'supportDecisions.resolver',
        ]);

        return response()->json([
            'message' => $isReinstatement ? 'Recipient support reinstated.' : 'Recipient request resolved.',
            'recipient' => $this->recipientSupportPayload($freshApplication, $cycles),
        ]);
    }

    public function viewRecipientSupportDecisionFile(
        Request $request,
        RecipientSupportDecision $decision,
        string $kind,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        $decision->loadMissing('application.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($decision->application?->scholarship), 403);

        [$path, $name] = match ($kind) {
            'applicant-response' => [$decision->applicant_response_path, $decision->applicant_response_original_name],
            'resolution-proof' => [$decision->resolution_proof_path, $decision->resolution_proof_original_name],
            default => [$decision->decision_document_path, $decision->decision_document_original_name],
        };
        abort_unless(filled($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, $name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function viewRecipientMonitoringSubmission(
        Request $request,
        RecipientMonitoringSubmission $submission,
    ) {
        abort_unless($request->user()?->isProvider(), 403);
        $submission->loadMissing('cycle.scholarship');
        abort_unless($request->user()->canAccessProviderProgram($submission->cycle?->scholarship), 403);
        abort_unless(filled($submission->path) && Storage::disk('local')->exists($submission->path), 404);

        return Storage::disk('local')->response($submission->path, $submission->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function selectedRecipientApplications(Scholarship $scholarship): EloquentCollection
    {
        return ScholarshipApplication::query()
            ->with([
                'applicant.studentProfile',
                'monitoringSubmissions.reviewer',
                'monitoringSubmissions.reviews.reviewer',
                'monitoringAdjustmentRequests.decider',
                'monitoringInterventions.creator',
                'supportDecisions.decider',
            ])
            ->where('scholarship_id', $scholarship->id)
            ->where(function (Builder $query): void {
                $query->where('final_outcome', 'selected')
                    ->orWhereIn('status', self::AWARD_SLOT_STATUSES);
            })
            ->where('status', '!=', 'benefits_terminated')
            ->where(function (Builder $query): void {
                $query->whereNull('student_response_status')
                    ->orWhere('student_response_status', '!=', 'declined');
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (ScholarshipApplication $application): bool => ! in_array(
                $application->supportDecisions->first()?->decision,
                ['completed', 'terminated'],
                true,
            ));
    }

    private function supportRecipientApplications(Scholarship $scholarship): EloquentCollection
    {
        return ScholarshipApplication::query()
            ->with([
                'applicant.studentProfile',
                'monitoringSubmissions',
                'benefitReleaseRecords.release',
                'supportDecisions.decider',
            ])
            ->where('scholarship_id', $scholarship->id)
            ->where(function (Builder $query): void {
                $query->where('final_outcome', 'selected')
                    ->orWhereIn('status', [...self::AWARD_SLOT_STATUSES, 'benefits_terminated']);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('student_response_status')
                    ->orWhere('student_response_status', '!=', 'declined');
            })
            ->orderBy('id')
            ->get();
    }

    private function recipientSupportPayload(
        ScholarshipApplication $application,
        EloquentCollection $cycles,
    ): array {
        $application->loadMissing([
            'applicant.studentProfile',
            'monitoringSubmissions',
            'benefitReleaseRecords.release',
            'supportDecisions.decider',
        ]);
        $latestDecision = $application->supportDecisions->first();
        $eligibility = $this->recipientSupportEligibility($application, $cycles, now());
        $releasedCount = $application->benefitReleaseRecords->where('status', 'released')->count();
        $agreement = RecipientAgreement::payload($application);
        $supportStatus = $latestDecision?->decision
            ?? ($application->status === 'benefits_terminated' ? 'terminated' : 'active');

        return [
            'application_id' => $application->id,
            'applicant_id' => $application->applicant_id,
            'name' => $application->applicant?->name ?? 'Applicant',
            'email' => $application->applicant?->email,
            'profile_photo_url' => $application->applicant?->studentProfile?->profile_photo_path
                ? route('provider.applications.profile-photo.view', $application, false)
                : null,
            'application_url' => route('provider.applications.show', $application, false),
            'agreement_status' => $agreement['status'] ?? 'pending',
            'agreement_status_label' => $agreement['status_label'] ?? 'Awaiting response',
            'support_status' => $supportStatus,
            'support_status_label' => match ($supportStatus) {
                'renewed' => 'Renewed support',
                'completed' => 'Program completed',
                'terminated' => 'Support ended early',
                default => 'Active recipient',
            },
            'is_closed' => in_array($supportStatus, ['completed', 'terminated'], true),
            'renewal_eligible' => $eligibility['eligible'],
            'renewal_eligibility_label' => $eligibility['label'],
            'renewal_eligibility_reason' => $eligibility['reason'],
            'requirements_met' => $eligibility['requirements_met'],
            'requirements_total' => $eligibility['requirements_total'],
            'release_count' => $application->benefitReleaseRecords->count(),
            'released_count' => $releasedCount,
            'latest_decision' => $latestDecision ? $this->recipientSupportDecisionPayload($latestDecision) : null,
            'decisions' => $application->supportDecisions
                ->map(fn (RecipientSupportDecision $decision): array => $this->recipientSupportDecisionPayload($decision))
                ->values(),
        ];
    }

    private function recipientProgramSummaryPayload(
        Collection $cycles,
        Collection $releases,
        Collection $recipients,
    ): array {
        $today = now()->startOfDay();
        $attention = collect();

        foreach ($recipients as $recipient) {
            if (data_get($recipient, 'latest_decision.response_status') === 'open') {
                $attention->push([
                    'type' => 'outcome',
                    'type_label' => 'Outcome review',
                    'title' => $recipient['name'],
                    'detail' => data_get($recipient, 'latest_decision.applicant_response_label').' needs a provider response.',
                    'status_label' => 'Review requested',
                    'application_url' => $recipient['application_url'],
                    'priority' => 0,
                ]);
            }

            if (! $recipient['is_closed'] && $recipient['agreement_status'] !== 'accepted') {
                $attention->push([
                    'type' => 'agreement',
                    'type_label' => 'Agreement',
                    'title' => $recipient['name'],
                    'detail' => 'Recipient agreement is awaiting acceptance.',
                    'status_label' => $recipient['agreement_status_label'],
                    'application_url' => $recipient['application_url'],
                    'priority' => 2,
                ]);
            }
        }

        foreach ($cycles as $cycle) {
            foreach ($cycle['recipients'] as $recipient) {
                if ($cycle['is_plan_check_in']) {
                    foreach ($recipient['requirements'] as $requirement) {
                        if (! $requirement['requires_file']) {
                            continue;
                        }

                        $submission = $requirement['submission'];
                        $reviewStatus = data_get($submission, 'review_status');
                        if ($submission && $reviewStatus === 'pending') {
                            $attention->push([
                                'type' => 'monitoring',
                                'type_label' => 'Checklist review',
                                'title' => $recipient['name'],
                                'detail' => $requirement['title'].' is waiting for review.',
                                'status_label' => 'Review needed',
                                'application_url' => $recipient['application_url'],
                                'priority' => 1,
                            ]);
                        } elseif ($submission && in_array($reviewStatus, ['not_met', 'needs_correction'], true)) {
                            $attention->push([
                                'type' => 'monitoring',
                                'type_label' => 'Checklist requirement',
                                'title' => $recipient['name'],
                                'detail' => $requirement['title'].': '.data_get($submission, 'review_status_label').'.',
                                'status_label' => data_get($submission, 'review_status_label'),
                                'application_url' => $recipient['application_url'],
                                'priority' => 1,
                            ]);
                        } elseif (! $submission && $requirement['required'] && $cycle['is_past_due']) {
                            $attention->push([
                                'type' => 'monitoring',
                                'type_label' => 'Overdue record',
                                'title' => $recipient['name'],
                                'detail' => $requirement['title'].' was not submitted for '.$cycle['title'].'.',
                                'status_label' => 'Overdue',
                                'application_url' => $recipient['application_url'],
                                'priority' => 1,
                            ]);
                        }
                    }

                    continue;
                }

                $submission = $recipient['submission'];
                $reviewStatus = data_get($submission, 'review_status');

                if ($submission && $reviewStatus === 'pending') {
                    $attention->push([
                        'type' => 'monitoring',
                        'type_label' => 'Academic review',
                        'title' => $recipient['name'],
                        'detail' => $cycle['title'].' has a submitted grade record waiting for review.',
                        'status_label' => 'Review needed',
                        'application_url' => $recipient['application_url'],
                        'priority' => 1,
                    ]);
                } elseif ($submission && in_array($reviewStatus, ['not_met', 'needs_correction'], true)) {
                    $attention->push([
                        'type' => 'monitoring',
                        'type_label' => 'Academic requirement',
                        'title' => $recipient['name'],
                        'detail' => $cycle['title'].': '.data_get($submission, 'review_status_label').'.',
                        'status_label' => data_get($submission, 'review_status_label'),
                        'application_url' => $recipient['application_url'],
                        'priority' => 1,
                    ]);
                } elseif (! $submission && $cycle['is_past_due']) {
                    $attention->push([
                        'type' => 'monitoring',
                        'type_label' => 'Overdue record',
                        'title' => $recipient['name'],
                        'detail' => $cycle['title'].' was due '.$cycle['due_label'].' and has no submission.',
                        'status_label' => 'Overdue',
                        'application_url' => $recipient['application_url'],
                        'priority' => 1,
                    ]);
                }
            }
        }

        foreach ($releases as $release) {
            $releaseIsDue = filled($release['release_date'])
                && CarbonImmutable::parse($release['release_date'])->startOfDay()->lte($today);

            foreach ($release['records'] as $record) {
                if (data_get($record, 'receipt_response.status') === 'open') {
                    $attention->push([
                        'type' => 'release',
                        'type_label' => 'Receipt issue',
                        'title' => $record['name'],
                        'detail' => $release['title'].': '.data_get($record, 'receipt_response.issue_type_label').'.',
                        'status_label' => 'Resolution needed',
                        'application_url' => $record['application_url'],
                        'priority' => 0,
                    ]);
                } elseif (in_array($record['status'], ['missed', 'withheld'], true)) {
                    $attention->push([
                        'type' => 'release',
                        'type_label' => 'Benefit release',
                        'title' => $record['name'],
                        'detail' => $release['title'].': '.$record['status_label'].'.',
                        'status_label' => $record['status_label'],
                        'application_url' => $record['application_url'],
                        'priority' => 1,
                    ]);
                } elseif ($releaseIsDue && in_array($record['status'], ['scheduled', 'prepared'], true)) {
                    $attention->push([
                        'type' => 'release',
                        'type_label' => 'Release result',
                        'title' => $record['name'],
                        'detail' => $release['title'].' is due and still needs a final release result.',
                        'status_label' => 'Result needed',
                        'application_url' => $record['application_url'],
                        'priority' => 2,
                    ]);
                }
            }
        }

        $upcoming = collect();
        foreach ($cycles as $cycle) {
            if ($cycle['status'] !== 'open' || blank($cycle['due_at'])) {
                continue;
            }

            $dueAt = CarbonImmutable::parse($cycle['due_at'])->startOfDay();
            if ($dueAt->lt($today)) {
                continue;
            }

            $upcoming->push([
                'type' => 'monitoring',
                'type_label' => 'Monitoring deadline',
                'title' => $cycle['title'],
                'detail' => $cycle['pending_count'].' awaiting upload; '.$cycle['submitted_count'].' received.',
                'date' => $cycle['due_at'],
                'date_label' => $cycle['due_label'],
            ]);
        }
        foreach ($releases as $release) {
            if (blank($release['release_date']) || $release['status'] === 'completed') {
                continue;
            }

            $releaseAt = CarbonImmutable::parse($release['release_date'])->startOfDay();
            if ($releaseAt->lt($today)) {
                continue;
            }

            $upcoming->push([
                'type' => 'release',
                'type_label' => 'Benefit release',
                'title' => $release['title'],
                'detail' => $release['pending_count'].' recipient records still pending.',
                'date' => $release['release_date'],
                'date_label' => $release['release_label'],
            ]);
        }

        $attention = $attention
            ->sortBy(fn (array $item): string => $item['priority'].'-'.$item['title'])
            ->values()
            ->map(function (array $item): array {
                unset($item['priority']);

                return $item;
            });
        $upcoming = $upcoming->sortBy('date')->values();

        return [
            'recipients' => [
                'total' => $recipients->count(),
                'active' => $recipients->where('is_closed', false)->count(),
                'agreement_pending' => $recipients
                    ->where('is_closed', false)
                    ->where('agreement_status', '!=', 'accepted')
                    ->count(),
                'renewal_ready' => $recipients
                    ->where('is_closed', false)
                    ->where('renewal_eligible', true)
                    ->count(),
            ],
            'outcomes' => [
                'active' => $recipients->where('support_status', 'active')->count(),
                'renewed' => $recipients->where('support_status', 'renewed')->count(),
                'completed' => $recipients->where('support_status', 'completed')->count(),
                'terminated' => $recipients->where('support_status', 'terminated')->count(),
            ],
            'monitoring' => [
                'periods' => $cycles->count(),
                'open_periods' => $cycles->where('status', 'open')->count(),
                'records_received' => $cycles->sum('submitted_count'),
                'records_reviewed' => $cycles->sum('reviewed_count'),
            ],
            'releases' => [
                'schedules' => $releases->count(),
                'released' => $releases->sum('released_count'),
                'pending' => $releases->sum('pending_count'),
                'exceptions' => $releases->sum('exception_count'),
                'confirmed' => $releases->sum('confirmed_count'),
                'open_issues' => $releases->sum('open_issue_count'),
            ],
            'attention_count' => $attention->count(),
            'upcoming_count' => $upcoming->count(),
            'attention' => $attention,
            'upcoming' => $upcoming,
        ];
    }

    private function recipientSupportRecordPayload(ScholarshipApplication $application): array
    {
        $application->loadMissing([
            'scholarship.provider.providerProfile',
            'scholarship.monitoringCycles.creator',
            'applicant.studentProfile',
            'monitoringSubmissions.cycle',
            'monitoringSubmissions.reviewer',
            'monitoringSubmissions.reviews.reviewer',
            'benefitReleaseRecords.release',
            'benefitReleaseRecords.recorder',
            'supportDecisions.decider',
        ]);

        $cycles = $application->scholarship->monitoringCycles;
        $support = $this->recipientSupportPayload($application, $cycles);
        $agreement = RecipientAgreement::payload($application);
        $monitoringRecords = $cycles->map(function (RecipientMonitoringCycle $cycle) use ($application): array {
            $submission = $application->monitoringSubmissions
                ->firstWhere('recipient_monitoring_cycle_id', $cycle->id);

            return [
                'id' => $cycle->id,
                'title' => $cycle->title,
                'period_label' => collect([$cycle->academic_period, $cycle->school_year])->filter()->implode(' - ')
                    ?: Str::headline($cycle->period_type),
                'requirement_label' => AcademicRequirement::requirementLabel($cycle->minimum_grade, $cycle->grading_scale),
                'due_label' => $cycle->due_at?->format('M d, Y'),
                'submission' => $submission
                    ? $this->recipientMonitoringSubmissionPayload($submission, $cycle)
                    : null,
            ];
        })->values();
        $releaseRecords = $application->benefitReleaseRecords
            ->sortByDesc(fn (RecipientBenefitReleaseRecord $record): int => $record->release?->release_at?->timestamp ?? 0)
            ->map(function (RecipientBenefitReleaseRecord $record): array {
                $release = $record->release;

                return [
                    'id' => $record->id,
                    'title' => $release?->title ?? 'Benefit release',
                    'benefit_description' => $release?->benefit_description,
                    'amount_label' => $release?->amount !== null
                        ? 'PHP '.number_format((float) $release->amount, 2)
                        : null,
                    'release_label' => $release?->release_at?->format('M d, Y h:i A'),
                    'method_label' => match ($release?->release_method) {
                        'bank_transfer' => 'Bank transfer',
                        'e_wallet' => 'E-wallet',
                        'other' => 'Other arrangement',
                        default => 'In person',
                    },
                    'location' => $release?->location,
                    'status' => $record->status,
                    'status_label' => match ($record->status) {
                        'released' => 'Released',
                        'prepared' => 'Prepared',
                        'missed' => 'Missed',
                        'withheld' => 'Withheld',
                        default => 'Scheduled',
                    },
                    'originals_verified' => $record->originals_verified,
                    'notes' => $record->notes,
                    'recorded_by' => $record->recorder?->name,
                    'recorded_at' => $record->recorded_at?->format('M d, Y h:i A'),
                    'receipt' => $record->receipt_path ? [
                        'id' => $record->id,
                        'original_name' => $record->receipt_original_name,
                        'size' => $record->receipt_size,
                        'view_url' => route('provider.benefit-release-records.receipt', $record, false),
                    ] : null,
                ];
            })
            ->values();

        $timeline = collect();
        if ($agreement) {
            $agreementDate = $application->student_responded_at
                ?? $application->outcome_at
                ?? $application->submitted_at;
            $timeline->push([
                'type' => 'agreement',
                'title' => $agreement['status'] === 'accepted' ? 'Recipient agreement accepted' : 'Recipient agreement issued',
                'description' => $agreement['response_note']
                    ?: ($agreement['status'] === 'accepted'
                        ? 'The recipient accepted the recorded support terms.'
                        : 'The recipient has not accepted the support terms yet.'),
                'status' => $agreement['status'],
                'status_label' => $agreement['status_label'],
                'occurred_label' => $agreement['responded_at'] ?? $application->outcome_at?->format('M d, Y h:i A'),
                'sort_at' => $agreementDate?->timestamp ?? 0,
                'file' => null,
            ]);
        }

        foreach ($monitoringRecords as $record) {
            $submission = $record['submission'];
            $submitted = $application->monitoringSubmissions
                ->firstWhere('recipient_monitoring_cycle_id', $record['id']);
            $eventDate = $submitted?->reviewed_at ?? $submitted?->submitted_at;
            $timeline->push([
                'type' => 'monitoring',
                'title' => $record['title'],
                'description' => $submission
                    ? collect([
                        $submission['grade_label'] ? 'Recorded result: '.$submission['grade_label'].'.' : null,
                        'Requirement: '.$record['requirement_label'].'.',
                        $submission['review_notes'],
                    ])->filter()->implode(' ')
                    : 'Academic record requested; due '.$record['due_label'].'.',
                'status' => $submission['review_status'] ?? 'pending',
                'status_label' => $submission['review_status_label'] ?? 'Awaiting submission',
                'occurred_label' => $submission['reviewed_at'] ?? $submission['submitted_at'] ?? $record['due_label'],
                'sort_at' => $eventDate?->timestamp ?? 0,
                'file' => $submission ? [
                    'id' => $submission['id'],
                    'original_name' => $submission['original_name'],
                    'size' => $submission['size'],
                    'view_url' => $submission['view_url'],
                ] : null,
            ]);
        }

        foreach ($application->benefitReleaseRecords as $record) {
            $release = $record->release;
            $eventDate = $record->recorded_at ?? $release?->release_at;
            $timeline->push([
                'type' => 'release',
                'title' => $release?->title ?? 'Benefit release',
                'description' => collect([
                    $release?->benefit_description,
                    $release?->amount !== null ? 'Value: PHP '.number_format((float) $release->amount, 2).'.' : null,
                    $record->notes,
                ])->filter()->implode(' '),
                'status' => $record->status,
                'status_label' => match ($record->status) {
                    'released' => 'Released',
                    'prepared' => 'Prepared',
                    'missed' => 'Missed',
                    'withheld' => 'Withheld',
                    default => 'Scheduled',
                },
                'occurred_label' => $eventDate?->format('M d, Y h:i A'),
                'sort_at' => $eventDate?->timestamp ?? 0,
                'file' => $record->receipt_path ? [
                    'id' => $record->id,
                    'original_name' => $record->receipt_original_name,
                    'size' => $record->receipt_size,
                    'view_url' => route('provider.benefit-release-records.receipt', $record, false),
                ] : null,
            ]);
        }

        foreach ($application->supportDecisions as $decision) {
            $payload = $this->recipientSupportDecisionPayload($decision);
            $timeline->push([
                'type' => 'decision',
                'title' => $payload['decision_label'],
                'description' => $payload['reason'] ?: $payload['next_period_terms'],
                'status' => $decision->decision,
                'status_label' => $payload['decision_label'],
                'occurred_label' => $payload['decided_at'],
                'sort_at' => $decision->decided_at?->timestamp ?? 0,
                'file' => $payload['decision_document'],
            ]);
        }

        $timeline = $timeline
            ->sortByDesc('sort_at')
            ->values()
            ->map(function (array $event): array {
                unset($event['sort_at']);

                return $event;
            });

        return [
            'recipient' => [
                'id' => $application->applicant_id,
                'name' => $application->applicant?->name ?? 'Applicant',
                'email' => $application->applicant?->email,
                'profile_photo_url' => $application->applicant?->studentProfile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $application, false)
                    : null,
                'application_url' => route('provider.applications.show', $application, false),
            ],
            'program' => [
                'id' => $application->scholarship_id,
                'title' => $application->scholarship?->title,
                'provider_name' => $application->scholarship?->provider?->provider_name
                    ?? $application->scholarship?->provider?->name,
            ],
            'support' => $support,
            'agreement' => $agreement,
            'monitoring_records' => $monitoringRecords,
            'benefit_releases' => $releaseRecords,
            'decisions' => $application->supportDecisions
                ->map(fn (RecipientSupportDecision $decision): array => $this->recipientSupportDecisionPayload($decision))
                ->values(),
            'summary' => [
                'monitoring_total' => $monitoringRecords->count(),
                'monitoring_confirmed' => $application->monitoringSubmissions
                    ->whereIn('review_status', ['met', 'excused'])
                    ->count(),
                'release_total' => $releaseRecords->count(),
                'released_total' => $application->benefitReleaseRecords->where('status', 'released')->count(),
                'decision_total' => $application->supportDecisions->count(),
            ],
            'timeline' => $timeline,
        ];
    }

    private function recipientSupportEligibility(
        ScholarshipApplication $application,
        EloquentCollection $cycles,
        mixed $effectiveOn,
    ): array {
        $latestDecision = $application->supportDecisions->first();
        if ($latestDecision && in_array($latestDecision->decision, ['completed', 'terminated'], true)) {
            return [
                'eligible' => false,
                'label' => 'Support closed',
                'reason' => 'This recipient support record is already closed.',
                'requirements_met' => 0,
                'requirements_total' => 0,
            ];
        }

        $monitoring = $this->recipientBenefitReleaseEligibility($application, $cycles, $effectiveOn);
        if (! $monitoring['eligible']) {
            return $monitoring;
        }

        $effectiveDate = CarbonImmutable::parse($effectiveOn)->endOfDay();
        $pendingReleases = $application->benefitReleaseRecords
            ->filter(function (RecipientBenefitReleaseRecord $record) use ($effectiveDate): bool {
                $releaseAt = $record->release?->release_at;

                return $releaseAt !== null
                    && $releaseAt->lte($effectiveDate)
                    && in_array($record->status, ['scheduled', 'prepared'], true);
            });

        if ($pendingReleases->isNotEmpty()) {
            return [
                'eligible' => false,
                'label' => 'Release record pending',
                'reason' => 'Record the result of all benefit releases due before renewing support.',
                'requirements_met' => $monitoring['requirements_met'],
                'requirements_total' => $monitoring['requirements_total'],
            ];
        }

        return [
            ...$monitoring,
            'label' => 'Ready for renewal',
            'reason' => $monitoring['requirements_total'] > 0
                ? 'Due monitoring requirements are confirmed and release records are up to date.'
                : 'No monitoring requirement is due and release records are up to date.',
        ];
    }

    private function recipientSupportDecisionPayload(RecipientSupportDecision $decision): array
    {
        $decision->loadMissing(['decider', 'resolver']);

        return [
            'id' => $decision->id,
            'decision' => $decision->decision,
            'decision_label' => match ($decision->decision) {
                'renewed' => 'Support renewed',
                'completed' => 'Program completed',
                'terminated' => 'Support ended early',
                default => Str::headline($decision->decision),
            },
            'reason_category' => $decision->reason_category,
            'reason_category_label' => $decision->reason_category ? Str::headline($decision->reason_category) : null,
            'effective_on' => $decision->effective_on?->format('Y-m-d'),
            'effective_label' => $decision->effective_on?->format('M d, Y'),
            'support_ends_on' => $decision->support_ends_on?->format('Y-m-d'),
            'support_ends_label' => $decision->support_ends_on?->format('M d, Y'),
            'next_review_on' => $decision->next_review_on?->format('Y-m-d'),
            'next_review_label' => $decision->next_review_on?->format('M d, Y'),
            'notice_given_on' => $decision->notice_given_on?->format('Y-m-d'),
            'notice_given_label' => $decision->notice_given_on?->format('M d, Y'),
            'reason' => $decision->reason,
            'next_period_terms' => $decision->next_period_terms,
            'decided_by' => $decision->decider?->name,
            'decided_at' => $decision->decided_at?->format('M d, Y h:i A'),
            'applicant_response_type' => $decision->applicant_response_type,
            'applicant_response_label' => match ($decision->applicant_response_type) {
                'acknowledged' => 'Acknowledged',
                'clarification_requested' => 'Clarification requested',
                'reconsideration_requested' => 'Reconsideration requested',
                default => null,
            },
            'applicant_response_message' => $decision->applicant_response_message,
            'applicant_responded_at' => $decision->applicant_responded_at?->format('M d, Y h:i A'),
            'response_status' => $decision->response_status,
            'response_status_label' => match ($decision->response_status) {
                'acknowledged' => 'Acknowledged',
                'open' => 'Review requested',
                'resolved' => 'Resolved',
                default => 'Waiting for recipient',
            },
            'resolution_outcome' => $decision->resolution_outcome,
            'resolution_outcome_label' => $decision->resolution_outcome ? Str::headline($decision->resolution_outcome) : null,
            'resolution_notes' => $decision->resolution_notes,
            'resolved_by' => $decision->resolver?->name,
            'resolved_at' => $decision->resolved_at?->format('M d, Y h:i A'),
            'decision_document' => $decision->decision_document_path ? [
                'original_name' => $decision->decision_document_original_name,
                'size' => $decision->decision_document_size,
                'view_url' => route('provider.support-decisions.file', [$decision, 'kind' => 'decision-document'], false),
            ] : null,
            'applicant_response_file' => $decision->applicant_response_path ? [
                'original_name' => $decision->applicant_response_original_name,
                'size' => $decision->applicant_response_size,
                'view_url' => route('provider.support-decisions.file', [$decision, 'kind' => 'applicant-response'], false),
            ] : null,
            'resolution_proof' => $decision->resolution_proof_path ? [
                'original_name' => $decision->resolution_proof_original_name,
                'size' => $decision->resolution_proof_size,
                'view_url' => route('provider.support-decisions.file', [$decision, 'kind' => 'resolution-proof'], false),
            ] : null,
        ];
    }

    private function recipientMonitoringPlanPayload(RecipientMonitoringPlan $plan): array
    {
        $plan->loadMissing(['requirements', 'creator', 'updater']);
        $definitions = RecipientMonitoringRequirementType::definitions();

        return [
            'id' => $plan->id,
            'frequency' => $plan->frequency,
            'frequency_label' => Str::headline($plan->frequency),
            'starts_on' => $plan->starts_on?->toDateString(),
            'starts_label' => $plan->starts_on?->format('M d, Y'),
            'ends_on' => $plan->ends_on?->toDateString(),
            'ends_label' => $plan->ends_on?->format('M d, Y'),
            'grace_period_days' => $plan->grace_period_days,
            'allow_exception_requests' => $plan->allow_exception_requests,
            'instructions' => $plan->instructions,
            'status' => $plan->status,
            'status_label' => $plan->status === 'active' ? 'Active plan' : 'Draft plan',
            'version' => $plan->version,
            'activated_at' => $plan->activated_at?->format('M d, Y h:i A'),
            'created_by' => $plan->creator?->name,
            'updated_by' => $plan->updater?->name,
            'updated_at' => $plan->updated_at?->format('M d, Y h:i A'),
            'requirement_count' => $plan->requirements->count(),
            'requirements' => $plan->requirements
                ->map(function (RecipientMonitoringRequirement $requirement) use ($definitions): array {
                    $definition = $definitions[$requirement->type] ?? [];

                    return [
                        'id' => $requirement->id,
                        'type' => $requirement->type,
                        'type_label' => $definition['label'] ?? Str::headline($requirement->type),
                        'icon' => $definition['icon'] ?? 'fa-solid fa-list-check',
                        'title' => $requirement->title,
                        'description' => $requirement->description,
                        'evidence_description' => $requirement->evidence_description,
                        'required' => $requirement->required,
                        'requires_file' => $requirement->requires_file,
                        'requires_original_verification' => $requirement->requires_original_verification,
                        'minimum_grade' => $requirement->minimum_grade !== null
                            ? (float) $requirement->minimum_grade
                            : null,
                        'grading_scale' => $requirement->grading_scale,
                        'sort_order' => $requirement->sort_order,
                    ];
                })
                ->values(),
        ];
    }

    private function recipientMonitoringCyclePayload(
        RecipientMonitoringCycle $cycle,
        EloquentCollection $applications,
    ): array {
        $cycle->loadMissing([
            'creator',
            'requirements',
            'submissions.requirement',
            'submissions.reviewer',
            'submissions.reviews.reviewer',
            'adjustmentRequests.decider',
            'interventions.creator',
        ]);
        $hasOpenPersonalExtension = $applications
            ->flatMap(fn (ScholarshipApplication $application): EloquentCollection => $application->monitoringAdjustmentRequests)
            ->filter(fn (RecipientMonitoringAdjustmentRequest $adjustment): bool => (
                $adjustment->recipient_monitoring_cycle_id === $cycle->id
                && $adjustment->request_type === 'extension'
                && $adjustment->status === 'approved'
                && $adjustment->approved_due_at?->greaterThanOrEqualTo(now()->startOfDay())
            ))
            ->isNotEmpty();
        $isPastDue = ($cycle->due_at?->isBefore(now()->startOfDay()) ?? false)
            && ! $hasOpenPersonalExtension;
        $isPlanCheckIn = $cycle->requirements->isNotEmpty();
        $recipients = $applications->map(function (ScholarshipApplication $application) use ($cycle): array {
            $cycleSubmissions = $application->monitoringSubmissions
                ->where('recipient_monitoring_cycle_id', $cycle->id);
            $submission = $cycleSubmissions
                ->first(fn (RecipientMonitoringSubmission $item): bool => $item->recipient_monitoring_cycle_requirement_id === null);
            $agreement = RecipientAgreement::payload($application);
            $requirements = $cycle->requirements->map(function (RecipientMonitoringCycleRequirement $requirement) use ($application, $cycleSubmissions, $cycle): array {
                $requirementSubmission = $cycleSubmissions
                    ->firstWhere('recipient_monitoring_cycle_requirement_id', $requirement->id);
                $adjustments = $application->monitoringAdjustmentRequests
                    ->where('recipient_monitoring_cycle_id', $cycle->id)
                    ->where('recipient_monitoring_cycle_requirement_id', $requirement->id)
                    ->sortByDesc('id');
                $adjustment = $adjustments->first();
                $effectiveDueAt = $adjustments
                    ->where('request_type', 'extension')
                    ->where('status', 'approved')
                    ->whereNotNull('approved_due_at')
                    ->sortByDesc('approved_due_at')
                    ->first()?->approved_due_at;
                $interventions = $application->monitoringInterventions
                    ->where('recipient_monitoring_cycle_id', $cycle->id)
                    ->where('recipient_monitoring_cycle_requirement_id', $requirement->id)
                    ->sortByDesc('id')
                    ->values();

                return $this->recipientMonitoringCycleRequirementPayload(
                    $requirement,
                    $requirementSubmission,
                    $cycle,
                    $adjustment,
                    $interventions,
                    $effectiveDueAt?->format('Y-m-d'),
                );
            })->values();
            $requiredUploads = $requirements->where('required', true)->where('requires_file', true);

            return [
                'application_id' => $application->id,
                'applicant_id' => $application->applicant_id,
                'name' => $application->applicant?->name ?? 'Applicant',
                'email' => $application->applicant?->email,
                'profile_photo_url' => $application->applicant?->studentProfile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $application, false)
                    : null,
                'agreement_status' => $agreement['status'] ?? 'pending',
                'benefits_active' => $application->status !== 'benefits_terminated',
                'application_url' => route('provider.applications.show', $application, false),
                'requirements' => $requirements,
                'required_upload_count' => $requiredUploads->count(),
                'submitted_item_count' => $requiredUploads->whereNotNull('submission')->count(),
                'required_review_count' => $requirements->where('required', true)->count(),
                'reviewed_item_count' => $requirements->where('required', true)
                    ->filter(fn (array $requirement): bool => filled(data_get($requirement, 'submission.reviewed_at')))
                    ->count(),
                'checklist_complete' => $requiredUploads->isEmpty()
                    || $requiredUploads->whereNotNull('submission')->count() === $requiredUploads->count(),
                'submission' => $submission
                    ? $this->recipientMonitoringSubmissionPayload($submission, $cycle)
                    : null,
            ];
        })->values();
        $requiredItemCount = $recipients->sum('required_upload_count');
        $submittedItemCount = $recipients->sum('submitted_item_count');
        $reviewedItemCount = $recipients
            ->flatMap(fn (array $recipient): Collection => collect($recipient['requirements']))
            ->filter(fn (array $requirement): bool => filled(data_get($requirement, 'submission.reviewed_at')))
            ->count();
        $pendingReviewItemCount = $recipients
            ->flatMap(fn (array $recipient): Collection => collect($recipient['requirements']))
            ->filter(fn (array $requirement): bool => data_get($requirement, 'submission.id')
                && blank(data_get($requirement, 'submission.reviewed_at')))
            ->count();
        $actionNeededItemCount = $recipients
            ->flatMap(fn (array $recipient): Collection => collect($recipient['requirements']))
            ->filter(fn (array $requirement): bool => in_array(
                data_get($requirement, 'submission.review_status'),
                ['not_met', 'needs_correction'],
                true,
            ))
            ->count();

        return [
            'id' => $cycle->id,
            'title' => $cycle->title,
            'period_type' => $cycle->period_type,
            'academic_period' => $cycle->academic_period,
            'school_year' => $cycle->school_year,
            'opens_at' => $cycle->opens_at?->format('Y-m-d'),
            'opens_label' => $cycle->opens_at?->format('M d, Y'),
            'due_at' => $cycle->due_at?->format('Y-m-d'),
            'due_label' => $cycle->due_at?->format('M d, Y'),
            'minimum_grade' => $cycle->minimum_grade,
            'grading_scale' => $cycle->grading_scale,
            'requirement_label' => $isPlanCheckIn
                ? $cycle->requirements->count().' checklist item'.($cycle->requirements->count() === 1 ? '' : 's')
                : AcademicRequirement::requirementLabel($cycle->minimum_grade, $cycle->grading_scale),
            'instructions' => $cycle->instructions,
            'is_plan_check_in' => $isPlanCheckIn,
            'monitoring_plan_version' => $cycle->monitoring_plan_version,
            'grace_period_days' => $cycle->grace_period_days,
            'allow_exception_requests' => $cycle->allow_exception_requests,
            'requirements' => $cycle->requirements
                ->map(fn (RecipientMonitoringCycleRequirement $requirement): array => (
                    $this->recipientMonitoringCycleRequirementPayload($requirement)
                ))
                ->values(),
            'status' => $isPastDue ? 'closed' : $cycle->status,
            'is_past_due' => $isPastDue,
            'published_at' => $cycle->published_at?->format('M d, Y h:i A'),
            'created_by' => $cycle->creator?->name,
            'recipient_count' => $recipients->count(),
            'submitted_count' => $isPlanCheckIn
                ? $recipients->where('checklist_complete', true)->count()
                : $recipients->whereNotNull('submission')->count(),
            'pending_count' => $isPlanCheckIn
                ? $recipients->where('checklist_complete', false)->count()
                : $recipients->whereNull('submission')->count(),
            'item_expected_count' => $requiredItemCount,
            'item_submitted_count' => $submittedItemCount,
            'item_pending_count' => max(0, $requiredItemCount - $submittedItemCount),
            'reviewed_count' => $isPlanCheckIn
                ? $reviewedItemCount
                : $recipients->filter(
                    fn (array $recipient): bool => filled(data_get($recipient, 'submission.reviewed_at')),
                )->count(),
            'pending_review_count' => $isPlanCheckIn
                ? $pendingReviewItemCount
                : $recipients->filter(
                    fn (array $recipient): bool => data_get($recipient, 'submission.id')
                        && blank(data_get($recipient, 'submission.reviewed_at')),
                )->count(),
            'action_needed_count' => $isPlanCheckIn
                ? $actionNeededItemCount
                : $recipients->filter(
                    fn (array $recipient): bool => in_array(
                        data_get($recipient, 'submission.review_status'),
                        ['not_met', 'needs_correction'],
                        true,
                    ),
                )->count(),
            'pending_adjustment_count' => $recipients
                ->flatMap(fn (array $recipient): Collection => collect($recipient['requirements']))
                ->where('adjustment_request.status', 'pending')
                ->count(),
            'open_intervention_count' => $recipients
                ->flatMap(fn (array $recipient): Collection => collect($recipient['requirements']))
                ->flatMap(fn (array $requirement): Collection => collect($requirement['interventions']))
                ->where('status', 'open')
                ->count(),
            'recipients' => $recipients,
        ];
    }

    private function recipientMonitoringCycleRequirementPayload(
        RecipientMonitoringCycleRequirement $requirement,
        ?RecipientMonitoringSubmission $submission = null,
        ?RecipientMonitoringCycle $cycle = null,
        ?RecipientMonitoringAdjustmentRequest $adjustment = null,
        ?Collection $interventions = null,
        ?string $effectiveDueAt = null,
    ): array {
        $definition = RecipientMonitoringRequirementType::definitions()[$requirement->type] ?? [];

        return [
            'id' => $requirement->id,
            'type' => $requirement->type,
            'type_label' => $definition['label'] ?? Str::headline($requirement->type),
            'icon' => $definition['icon'] ?? 'fa-solid fa-list-check',
            'title' => $requirement->title,
            'description' => $requirement->description,
            'evidence_description' => $requirement->evidence_description,
            'required' => $requirement->required,
            'requires_file' => $requirement->requires_file,
            'requires_original_verification' => $requirement->requires_original_verification,
            'minimum_grade' => $requirement->minimum_grade !== null
                ? (float) $requirement->minimum_grade
                : null,
            'grading_scale' => $requirement->grading_scale,
            'effective_due_at' => $effectiveDueAt ?? $cycle?->due_at?->format('Y-m-d'),
            'effective_due_label' => $effectiveDueAt
                ? CarbonImmutable::parse($effectiveDueAt)->format('M d, Y')
                : $cycle?->due_at?->format('M d, Y'),
            'adjustment_request' => $adjustment
                ? $this->recipientMonitoringAdjustmentPayload($adjustment)
                : null,
            'interventions' => ($interventions ?? collect())
                ->map(fn (RecipientMonitoringIntervention $intervention): array => (
                    $this->recipientMonitoringInterventionPayload($intervention)
                ))
                ->values(),
            'submission' => $submission && $cycle
                ? $this->recipientMonitoringSubmissionPayload($submission, $cycle)
                : null,
        ];
    }

    private function recipientMonitoringAdjustmentPayload(
        RecipientMonitoringAdjustmentRequest $adjustment,
    ): array {
        return [
            'id' => $adjustment->id,
            'request_type' => $adjustment->request_type,
            'request_type_label' => $adjustment->request_type === 'extension' ? 'Extension request' : 'Exception request',
            'reason_category' => $adjustment->reason_category,
            'reason_label' => Str::headline($adjustment->reason_category),
            'explanation' => $adjustment->explanation,
            'requested_due_at' => $adjustment->requested_due_at?->format('Y-m-d'),
            'requested_due_label' => $adjustment->requested_due_at?->format('M d, Y'),
            'status' => $adjustment->status,
            'status_label' => match ($adjustment->status) {
                'approved' => 'Approved',
                'declined' => 'Declined',
                default => 'Pending review',
            },
            'decision_notes' => $adjustment->decision_notes,
            'approved_due_at' => $adjustment->approved_due_at?->format('Y-m-d'),
            'approved_due_label' => $adjustment->approved_due_at?->format('M d, Y'),
            'decided_by' => $adjustment->decider?->name,
            'decided_at' => $adjustment->decided_at?->format('M d, Y h:i A'),
            'submitted_at' => $adjustment->created_at?->format('M d, Y h:i A'),
            'attachment' => filled($adjustment->attachment_path) ? [
                'original_name' => $adjustment->attachment_original_name,
                'size' => $adjustment->attachment_size,
                'view_url' => route('provider.monitoring-adjustment-requests.attachment', $adjustment, false),
            ] : null,
        ];
    }

    private function recipientMonitoringInterventionPayload(
        RecipientMonitoringIntervention $intervention,
    ): array {
        return [
            'id' => $intervention->id,
            'type' => $intervention->type,
            'type_label' => match ($intervention->type) {
                'reminder' => 'Reminder',
                'consultation' => 'Consultation',
                'support_plan' => 'Support plan',
                'warning' => 'Formal warning',
                default => Str::headline($intervention->type),
            },
            'summary' => $intervention->summary,
            'action_required' => $intervention->action_required,
            'follow_up_on' => $intervention->follow_up_on?->format('Y-m-d'),
            'follow_up_label' => $intervention->follow_up_on?->format('M d, Y'),
            'status' => $intervention->status,
            'status_label' => $intervention->status === 'completed' ? 'Completed' : 'Open',
            'completion_notes' => $intervention->completion_notes,
            'completed_at' => $intervention->completed_at?->format('M d, Y h:i A'),
            'created_by' => $intervention->creator?->name,
            'created_at' => $intervention->created_at?->format('M d, Y h:i A'),
        ];
    }

    private function recipientMonitoringSubmissionPayload(
        RecipientMonitoringSubmission $submission,
        RecipientMonitoringCycle $cycle,
    ): array {
        $submission->loadMissing(['requirement', 'reviewer', 'reviews.reviewer']);
        $requirement = $submission->requirement;
        $grade = $submission->grade_source === 'applicant_manual'
            ? $submission->reported_grade
            : ($submission->ocr_grade ?? $submission->reported_grade);
        $scale = $submission->grade_source === 'applicant_manual'
            ? $submission->reported_grading_scale
            : ($submission->ocr_grading_scale ?? $submission->reported_grading_scale);

        return [
            'id' => $submission->id,
            'requirement_id' => $submission->recipient_monitoring_cycle_requirement_id,
            'requirement_type' => $requirement?->type,
            'submission_source' => $submission->submission_source ?? 'applicant_upload',
            'has_file' => filled($submission->path),
            'original_name' => $submission->original_name,
            'size' => $submission->size,
            'submitted_at' => $submission->submitted_at?->format('M d, Y h:i A'),
            'ocr_status' => $submission->ocr_status,
            'ocr_provider' => $submission->ocr_provider,
            'ocr_grade' => $submission->ocr_grade,
            'ocr_grading_scale' => $submission->ocr_grading_scale,
            'ocr_label' => $submission->ocr_label,
            'ocr_message' => $submission->ocr_message,
            'grade' => $grade,
            'grading_scale' => $scale,
            'grade_source' => $submission->grade_source,
            'grade_label' => AcademicRequirement::studentLabel($grade, $scale),
            'review_status' => $submission->review_status ?? 'pending',
            'review_status_label' => match ($submission->review_status) {
                'met' => 'Requirement met',
                'not_met' => 'Requirement not met',
                'needs_correction' => 'Needs replacement',
                'excused' => 'Exception approved',
                default => 'Pending review',
            },
            'review_notes' => $submission->review_notes,
            'reviewed_by' => $submission->reviewer?->name,
            'reviewed_at' => $submission->reviewed_at?->format('M d, Y h:i A'),
            'reviews' => $submission->reviews->map(fn ($review): array => [
                'id' => $review->id,
                'decision' => $review->decision,
                'decision_label' => match ($review->decision) {
                    'met' => 'Requirement met',
                    'not_met' => 'Requirement not met',
                    'needs_correction' => 'Replacement requested',
                    'excused' => 'Exception approved',
                    default => Str::headline($review->decision),
                },
                'notes' => $review->notes,
                'reviewed_by' => $review->reviewer?->name,
                'decided_at' => $review->decided_at?->format('M d, Y h:i A'),
            ])->values(),
            'comparison' => ($requirement?->type ?? RecipientMonitoringRequirementType::ACADEMIC_PROGRESS)
                === RecipientMonitoringRequirementType::ACADEMIC_PROGRESS
                    ? AcademicRequirement::match(
                        $grade,
                        $scale,
                        $requirement?->minimum_grade ?? $cycle->minimum_grade,
                        $requirement?->grading_scale ?? $cycle->grading_scale,
                    )
                    : null,
            'view_url' => filled($submission->path)
                ? route('provider.monitoring-submissions.view', $submission, false)
                : null,
        ];
    }

    private function recipientReleaseCandidatesPayload(
        EloquentCollection $applications,
        EloquentCollection $cycles,
        mixed $releaseAt,
    ): Collection {
        return $applications->map(function (ScholarshipApplication $application) use ($cycles, $releaseAt): array {
            $eligibility = $this->recipientBenefitReleaseEligibility($application, $cycles, $releaseAt);

            return [
                'application_id' => $application->id,
                'applicant_id' => $application->applicant_id,
                'name' => $application->applicant?->name ?? 'Applicant',
                'email' => $application->applicant?->email,
                'eligible' => $eligibility['eligible'],
                'eligibility_label' => $eligibility['label'],
                'eligibility_reason' => $eligibility['reason'],
                'requirements_met' => $eligibility['requirements_met'],
                'requirements_total' => $eligibility['requirements_total'],
                'application_url' => route('provider.applications.show', $application, false),
            ];
        })->values();
    }

    private function recipientBenefitReleaseEligibility(
        ScholarshipApplication $application,
        EloquentCollection $cycles,
        mixed $releaseAt,
    ): array {
        if ($application->student_response_status !== 'accepted') {
            return [
                'eligible' => false,
                'label' => 'Agreement pending',
                'reason' => 'The recipient agreement has not been accepted.',
                'requirements_met' => 0,
                'requirements_total' => 0,
            ];
        }

        if ($application->status === 'benefits_terminated') {
            return [
                'eligible' => false,
                'label' => 'Benefits stopped',
                'reason' => 'This recipient is no longer receiving program benefits.',
                'requirements_met' => 0,
                'requirements_total' => 0,
            ];
        }

        $releaseDate = CarbonImmutable::parse($releaseAt)->startOfDay();
        $applicableCycles = $cycles->filter(
            fn (RecipientMonitoringCycle $cycle): bool => in_array($cycle->status, ['open', 'closed'], true)
                && $cycle->due_at !== null
                && ! $cycle->due_at->isAfter($releaseDate),
        );
        $met = 0;
        $total = 0;
        $unresolved = [];

        foreach ($applicableCycles as $cycle) {
            $cycle->loadMissing('requirements');

            if ($cycle->requirements->isNotEmpty()) {
                foreach ($cycle->requirements->where('required', true) as $requirement) {
                    $total++;
                    $submission = $application->monitoringSubmissions
                        ->firstWhere('recipient_monitoring_cycle_requirement_id', $requirement->id);
                    $reviewStatus = $submission?->review_status;

                    if (in_array($reviewStatus, ['met', 'excused'], true)) {
                        $met++;

                        continue;
                    }

                    $unresolved[] = match ($reviewStatus) {
                        'not_met' => "{$cycle->title}: {$requirement->title} was marked not met.",
                        'needs_correction' => "{$cycle->title}: {$requirement->title} needs a replacement.",
                        'pending' => "{$cycle->title}: {$requirement->title} is waiting for review.",
                        default => "{$cycle->title}: {$requirement->title} is not confirmed.",
                    };
                }

                continue;
            }

            $total++;
            $submission = $application->monitoringSubmissions
                ->firstWhere('recipient_monitoring_cycle_id', $cycle->id);
            $reviewStatus = $submission?->review_status;

            if (in_array($reviewStatus, ['met', 'excused'], true)) {
                $met++;

                continue;
            }

            $unresolved[] = match ($reviewStatus) {
                'not_met' => "{$cycle->title} was marked not met.",
                'needs_correction' => "{$cycle->title} needs a replacement record.",
                'pending' => "{$cycle->title} is waiting for provider review.",
                default => "{$cycle->title} has not been submitted.",
            };
        }

        $eligible = $unresolved === [];

        return [
            'eligible' => $eligible,
            'label' => $eligible ? 'Ready for release' : 'Monitoring action needed',
            'reason' => $eligible
                ? ($total > 0 ? 'All monitoring requirements due before this release are confirmed.' : 'No monitoring requirement is due before this release.')
                : implode(' ', $unresolved),
            'requirements_met' => $met,
            'requirements_total' => $total,
        ];
    }

    private function recipientBenefitReleasePayload(RecipientBenefitRelease $release): array
    {
        $release->loadMissing(['creator', 'records.applicant.studentProfile', 'records.recorder', 'records.receiptResponse.resolver']);
        $statusLabels = [
            'scheduled' => 'Scheduled',
            'prepared' => 'Prepared',
            'released' => 'Released',
            'missed' => 'Missed',
            'withheld' => 'Withheld',
        ];

        return [
            'id' => $release->id,
            'title' => $release->title,
            'release_at' => $release->release_at?->format('Y-m-d\TH:i'),
            'release_date' => $release->release_at?->format('Y-m-d'),
            'release_label' => $release->release_at?->format('M d, Y h:i A'),
            'benefit_description' => $release->benefit_description,
            'amount' => $release->amount,
            'amount_label' => $release->amount !== null ? 'PHP '.number_format((float) $release->amount, 2) : null,
            'release_method' => $release->release_method,
            'release_method_label' => match ($release->release_method) {
                'bank_transfer' => 'Bank transfer',
                'e_wallet' => 'E-wallet',
                'other' => 'Other arrangement',
                default => 'In person',
            },
            'location' => $release->location,
            'instructions' => $release->instructions,
            'requires_original_verification' => $release->requires_original_verification,
            'status' => $release->status,
            'status_label' => Str::headline($release->status),
            'published_at' => $release->published_at?->format('M d, Y h:i A'),
            'created_by' => $release->creator?->name,
            'recipient_count' => $release->records->count(),
            'released_count' => $release->records->where('status', 'released')->count(),
            'pending_count' => $release->records->whereIn('status', ['scheduled', 'prepared'])->count(),
            'exception_count' => $release->records->whereIn('status', ['missed', 'withheld'])->count(),
            'confirmed_count' => $release->records->filter(fn (RecipientBenefitReleaseRecord $record): bool => $record->receiptResponse?->status === 'confirmed')->count(),
            'open_issue_count' => $release->records->filter(fn (RecipientBenefitReleaseRecord $record): bool => $record->receiptResponse?->status === 'open')->count(),
            'records' => $release->records->map(fn (RecipientBenefitReleaseRecord $record): array => [
                'id' => $record->id,
                'application_id' => $record->scholarship_application_id,
                'applicant_id' => $record->applicant_id,
                'name' => $record->applicant?->name ?? 'Applicant',
                'email' => $record->applicant?->email,
                'profile_photo_url' => $record->applicant?->studentProfile?->profile_photo_path
                    ? route('provider.applications.profile-photo.view', $record->scholarship_application_id, false)
                    : null,
                'status' => $record->status,
                'status_label' => $statusLabels[$record->status] ?? Str::headline($record->status),
                'originals_verified' => $record->originals_verified,
                'notes' => $record->notes,
                'recorded_by' => $record->recorder?->name,
                'recorded_at' => $record->recorded_at?->format('M d, Y h:i A'),
                'released_at' => $record->released_at?->format('M d, Y h:i A'),
                'receipt_response' => $record->receiptResponse ? $this->providerBenefitReceiptResponsePayload($record->receiptResponse) : null,
                'application_url' => route('provider.applications.show', $record->scholarship_application_id, false),
                'receipt' => $record->receipt_path ? [
                    'id' => $record->id,
                    'original_name' => $record->receipt_original_name,
                    'size' => $record->receipt_size,
                    'view_url' => route('provider.benefit-release-records.receipt', $record, false),
                ] : null,
            ])->values(),
        ];
    }

    private function providerBenefitReceiptResponsePayload(RecipientBenefitReceiptResponse $response): array
    {
        return [
            'id' => $response->id,
            'response_type' => $response->response_type,
            'response_label' => $response->response_type === 'confirmed' ? 'Receipt confirmed' : 'Issue reported',
            'status' => $response->status,
            'status_label' => match ($response->status) {
                'confirmed' => 'Confirmed',
                'resolved' => 'Resolved',
                default => 'Issue open',
            },
            'received_label' => $response->received_on?->format('M d, Y'),
            'recipient_note' => $response->recipient_note,
            'issue_type' => $response->issue_type,
            'issue_type_label' => $response->issue_type ? Str::headline($response->issue_type) : null,
            'issue_details' => $response->issue_details,
            'responded_at' => $response->responded_at?->format('M d, Y h:i A'),
            'resolution_outcome' => $response->resolution_outcome,
            'resolution_outcome_label' => $response->resolution_outcome ? Str::headline($response->resolution_outcome) : null,
            'resolution_notes' => $response->resolution_notes,
            'resolved_by' => $response->resolver?->name,
            'resolved_at' => $response->resolved_at?->format('M d, Y h:i A'),
            'evidence' => $response->evidence_path ? [
                'original_name' => $response->evidence_original_name,
                'size' => $response->evidence_size,
                'view_url' => route('provider.benefit-receipt-responses.file', [$response, 'kind' => 'evidence'], false),
            ] : null,
            'resolution_proof' => $response->resolution_proof_path ? [
                'original_name' => $response->resolution_proof_original_name,
                'size' => $response->resolution_proof_size,
                'view_url' => route('provider.benefit-receipt-responses.file', [$response, 'kind' => 'resolution-proof'], false),
            ] : null,
        ];
    }
}
