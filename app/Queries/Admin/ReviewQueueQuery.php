<?php

namespace App\Queries\Admin;

use App\Domain\Applications\ApplicationStageCatalog;
use App\Domain\Recipients\RecipientSupportCatalog;
use App\Models\ApplicantVerificationDocument;
use App\Models\ApplicationStageSession;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\RecipientMonitoringCase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class ReviewQueueQuery
{
    public function summary(): array
    {
        $providers = User::query()->where('role', 'provider')->whereNull('parent_account_id');
        $applicants = User::query()->where('role', 'applicant');

        return [
            'provider_pending' => (clone $providers)->where(function (Builder $query): void {
                $query->whereDoesntHave('providerProfile')
                    ->orWhereHas('providerProfile', fn (Builder $profile) => $profile->where('verification_status', 'pending'));
            })->count(),
            'program_pending' => Scholarship::query()->where('status', 'pending_review')->count(),
            'applicant_pending' => (clone $applicants)
                ->whereHas('applicantVerificationDocuments', fn (Builder $query) => $query->where('document_type', 'academic_record'))
                ->whereHas('studentProfile', fn (Builder $query) => $query->whereNotIn('verification_status', ['approved', 'rejected']))
                ->count(),
            'benefit_attention' => $this->benefitBaseQuery('attention')->count(),
            'monitoring_attention' => $this->monitoringBaseQuery('attention')->count(),
            'selection_activity_attention' => $this->selectionActivityBaseQuery('attention')->count(),
        ];
    }

    public function providers(array $filters): array
    {
        $status = $filters['status'] ?? 'pending';
        $query = User::query()
            ->with('providerProfile')
            ->withCount(['providerVerificationDocuments', 'providerScholarships'])
            ->where('role', 'provider')
            ->whereNull('parent_account_id');

        if ($status === 'pending') {
            $query->where(function (Builder $builder): void {
                $builder->whereDoesntHave('providerProfile')
                    ->orWhereHas('providerProfile', fn (Builder $profile) => $profile->where('verification_status', 'pending'));
            });
        } elseif ($status !== 'all') {
            $query->whereHas('providerProfile', fn (Builder $profile) => $profile->where('verification_status', $status));
        }

        $this->search($query, $filters['search'] ?? null, ['email', 'username'], [
            ['providerProfile', ['provider_name', 'legal_name', 'registration_number']],
        ]);

        return $this->page($query->latest('updated_at'), $filters, function (User $provider): array {
            $profile = $provider->providerProfile;

            return [
                'id' => $provider->id,
                'name' => $profile?->provider_name ?: $provider->name,
                'email' => $provider->email,
                'provider_type' => $profile?->provider_type,
                'legal_name' => $profile?->legal_name,
                'verification_status' => $profile?->verification_status ?: 'pending',
                'logo_url' => filled($profile?->logo_path) ? asset(ltrim($profile->logo_path, '/')) : null,
                'proof_count' => (int) $provider->provider_verification_documents_count,
                'program_count' => (int) $provider->provider_scholarships_count,
                'updated_at' => $provider->updated_at?->format('M d, Y'),
                'review_url' => route('admin.workspaces.reviews.providers.show', $provider),
            ];
        });
    }

    public function programs(array $filters): array
    {
        $status = $filters['status'] ?? 'pending_review';
        $query = Scholarship::query()
            ->with('provider.providerProfile')
            ->withCount('applications');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhereHas('provider.providerProfile', fn (Builder $profile) => $profile->where('provider_name', 'like', "%{$search}%"));
            });
        }

        return $this->page($query->latest('updated_at'), $filters, fn (Scholarship $program): array => [
            'id' => $program->id,
            'title' => $program->title,
            'provider' => $program->provider?->providerProfile?->provider_name ?: $program->provider?->name,
            'category' => $program->category,
            'status' => $program->status,
            'application_count' => (int) $program->applications_count,
            'deadline' => $program->deadline?->format('M d, Y'),
            'updated_at' => $program->updated_at?->format('M d, Y'),
            'image_url' => filled($program->image_path) ? asset(ltrim($program->image_path, '/')) : asset('uploads/scholarship-default.jpg'),
            'review_url' => route('admin.workspaces.reviews.programs.show', $program),
        ]);
    }

    public function applicants(array $filters): array
    {
        $status = $filters['status'] ?? 'pending';
        $query = User::query()
            ->with('studentProfile')
            ->withCount(['applicantVerificationDocuments as evidence_count' => fn (Builder $documents) => $documents->whereIn('document_type', ApplicantVerificationDocument::PROFILE_EVIDENCE_TYPES)])
            ->where('role', 'applicant');

        if ($status === 'unsubmitted') {
            $query->whereDoesntHave('applicantVerificationDocuments', fn (Builder $documents) => $documents->where('document_type', 'academic_record'));
        } elseif ($status === 'pending') {
            $query->whereHas('applicantVerificationDocuments', fn (Builder $documents) => $documents->where('document_type', 'academic_record'))
                ->whereHas('studentProfile', fn (Builder $profile) => $profile->whereNotIn('verification_status', ['approved', 'rejected']));
        } elseif (in_array($status, ['approved', 'rejected'], true)) {
            $query->whereHas('studentProfile', fn (Builder $profile) => $profile->where('verification_status', $status));
        }

        $this->search($query, $filters['search'] ?? null, ['email', 'username'], [
            ['studentProfile', ['first_name', 'last_name', 'school', 'learner_reference_number']],
        ]);

        return $this->page($query->latest('updated_at'), $filters, fn (User $applicant): array => [
            'id' => $applicant->id,
            'name' => $applicant->name,
            'email' => $applicant->email,
            'school' => $applicant->studentProfile?->school,
            'education' => collect([
                $applicant->studentProfile?->education_level,
                $applicant->studentProfile?->course_or_strand,
                $applicant->studentProfile?->year_level,
            ])->filter()->implode(' - '),
            'verification_status' => $applicant->applicantAcademicVerificationStatus(),
            'evidence_count' => (int) $applicant->evidence_count,
            'photo_url' => filled($applicant->studentProfile?->profile_photo_path)
                ? route('admin.applicants.profile-photo.view', $applicant)
                : null,
            'updated_at' => $applicant->updated_at?->format('M d, Y'),
            'review_url' => route('admin.workspaces.reviews.applicants.show', $applicant),
        ]);
    }

    public function benefits(array $filters): array
    {
        $status = $filters['status'] ?? 'attention';
        $query = $this->benefitBaseQuery($status)
            ->with([
                'release.scholarship.provider.providerProfile',
                'applicant.studentProfile',
                'receiptResponse',
            ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->whereHas('applicant', fn (Builder $user) => $user->where('email', 'like', "%{$search}%"))
                    ->orWhereHas('applicant.studentProfile', fn (Builder $profile) => $profile->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('release', fn (Builder $release) => $release->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('release.scholarship', fn (Builder $program) => $program->where('title', 'like', "%{$search}%"));
            });
        }

        return $this->page($query->latest('updated_at'), $filters, function (RecipientBenefitReleaseRecord $record): array {
            $release = $record->release;
            $program = $release?->scholarship;
            $flags = $this->benefitFlags($record);
            $hasReceipt = filled($record->receipt_path) && Storage::disk('local')->exists($record->receipt_path);

            return [
                'id' => $record->id,
                'recipient' => $record->applicant?->name,
                'program' => $program?->title,
                'provider' => $program?->provider?->providerProfile?->provider_name ?: $program?->provider?->name,
                'release' => $release?->title,
                'status' => $record->status,
                'oversight_status' => $flags ? 'attention' : ($hasReceipt ? 'documented' : 'scheduled'),
                'primary_flag' => $flags[0] ?? null,
                'scheduled_at' => $release?->release_at?->format('M d, Y'),
                'receipt_url' => $hasReceipt ? route('admin.benefit-release-records.receipt', $record) : null,
                'recipient_url' => $record->applicant ? route('admin.workspaces.reviews.applicants.show', $record->applicant) : null,
            ];
        });
    }

    public function monitoring(array $filters): array
    {
        $status = $filters['status'] ?? 'attention';
        $query = $this->monitoringBaseQuery($status)->with([
            'cycle',
            'application.scholarship.provider.providerProfile',
            'application.monitoringOversightReviews.reviewer',
            'applicant.studentProfile',
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->whereHas('applicant', fn (Builder $user) => $user->where('email', 'like', "%{$search}%"))
                    ->orWhereHas('applicant.studentProfile', fn (Builder $profile) => $profile->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))
                    ->orWhereHas('cycle', fn (Builder $cycle) => $cycle->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('application.scholarship', fn (Builder $program) => $program->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('application.scholarship.provider.providerProfile', fn (Builder $profile) => $profile->where('provider_name', 'like', "%{$search}%"));
            });
        }

        return $this->page($query
            ->orderByRaw("CASE WHEN due_status = 'overdue' THEN 0 WHEN next_action_role = 'provider' THEN 1 ELSE 2 END")
            ->orderBy('effective_due_at')
            ->latest('updated_at'), $filters, function (RecipientMonitoringCase $case): array {
                $application = $case->application;
                $state = $this->monitoringCaseState($case);
                $latestReview = $application?->monitoringOversightReviews
                    ->first(fn ($review): bool => (int) data_get($review->flags_snapshot, 'case_id') === $case->id);
                $reviewIsCurrent = $latestReview?->reviewed_at
                    && $latestReview->reviewed_at->greaterThanOrEqualTo($case->updated_at);
                $oversightStatus = match (true) {
                    $reviewIsCurrent && $latestReview->outcome === 'reviewed' => 'reviewed',
                    $case->isClosed() => 'closed',
                    $state['needs_attention'] => 'attention',
                    default => 'open',
                };

                return [
                    'id' => $case->id,
                    'case_id' => $case->id,
                    'application_id' => $application->id,
                    'recipient' => $case->applicant?->name,
                    'photo_url' => filled($case->applicant?->studentProfile?->profile_photo_path)
                        ? route('admin.applicants.profile-photo.view', $case->applicant)
                        : null,
                    'program' => $application?->scholarship?->title,
                    'provider' => $application?->scholarship?->provider?->providerProfile?->provider_name
                        ?: $application?->scholarship?->provider?->name,
                    'cycle_title' => $case->cycle?->title,
                    'due_on' => $case->effective_due_at?->format('M d, Y'),
                    'case_status' => $case->status,
                    'case_status_label' => $this->monitoringCaseStatusLabel($case->status),
                    'due_status' => $case->due_status,
                    'oversight_status' => $oversightStatus,
                    'primary_flag' => $state['issue'],
                    'severity' => $state['severity'],
                    'wait_label' => $state['wait_label'],
                    'next_action_owner' => $case->due_status === 'overdue' ? 'Provider' : match ($case->next_action_role) {
                        'provider' => 'Provider',
                        'applicant' => 'Recipient',
                        default => 'No action',
                    },
                    'next_action_label' => $case->due_status === 'overdue'
                        ? 'Resolve overdue check-in'
                        : $this->monitoringNextActionLabel($case->next_action),
                    'last_reviewed_at' => $latestReview?->reviewed_at?->format('M d, Y'),
                    'review_url' => route('admin.workspaces.reviews.monitoring.show', [
                        'application' => $application,
                        'case' => $case->id,
                    ]),
                ];
            });
    }

    public function selectionActivities(array $filters): array
    {
        $status = $filters['status'] ?? 'attention';
        $query = $this->selectionActivityBaseQuery($status)
            ->with(['scholarship.provider.providerProfile'])
            ->withCount([
                'assignments as active_assignments_count' => fn (Builder $assignments) => $assignments
                    ->whereIn('status', ['assigned', 'acknowledged']),
                'assignments as acknowledged_assignments_count' => fn (Builder $assignments) => $assignments
                    ->where('status', 'acknowledged'),
                'assignments as pending_attendance_count' => fn (Builder $assignments) => $assignments
                    ->whereIn('status', ['assigned', 'acknowledged'])
                    ->where(fn (Builder $attendance) => $attendance
                        ->whereDoesntHave('attendance')
                        ->orWhereHas('attendance', fn (Builder $record) => $record->where('status', 'pending'))),
                'evidence as current_evidence_count' => fn (Builder $evidence) => $evidence
                    ->where('is_current', true),
            ]);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('stage_key', 'like', "%{$search}%")
                    ->orWhereHas('scholarship', fn (Builder $program) => $program->where('title', 'like', "%{$search}%"))
                    ->orWhereHas('scholarship.provider', fn (Builder $provider) => $provider->where('email', 'like', "%{$search}%"))
                    ->orWhereHas('scholarship.provider.providerProfile', fn (Builder $profile) => $profile->where('provider_name', 'like', "%{$search}%"));
            });
        }

        return $this->page($query->latest('starts_at'), $filters, function (ApplicationStageSession $session): array {
            $state = $this->selectionActivityState($session);
            $program = $session->scholarship;

            return [
                'id' => (int) $session->id,
                'title' => $session->title,
                'program' => $program?->title,
                'provider' => $program?->provider?->providerProfile?->provider_name ?: $program?->provider?->name,
                'image_url' => filled($program?->image_path)
                    ? asset(ltrim($program->image_path, '/'))
                    : asset('uploads/scholarship-default.jpg'),
                'stage_key' => $session->stage_key,
                'stage_label' => $this->stageLabel($session->stage_key),
                'mode' => str($session->mode)->headline()->toString(),
                'schedule' => $session->starts_at?->format('M d, Y · g:i A'),
                'status' => $session->status,
                'oversight_status' => $state['status'],
                'primary_flag' => $state['flag'],
                'active_assignments_count' => (int) $session->active_assignments_count,
                'acknowledged_assignments_count' => (int) $session->acknowledged_assignments_count,
                'pending_attendance_count' => (int) $session->pending_attendance_count,
                'current_evidence_count' => (int) $session->current_evidence_count,
                'proof_required' => (bool) $session->proof_required,
                'review_url' => route('admin.workspaces.reviews.selection-activities.show', $session),
            ];
        });
    }

    public function selectionActivityRecord(ApplicationStageSession $session): array
    {
        $session->load([
            'scholarship.provider.providerProfile',
            'assignments.application.applicant.studentProfile',
            'assignments.attendance',
            'assignments.evidence.uploader',
            'evidence.uploader',
            'evidence.assignment.application.applicant.studentProfile',
            'auditEvents.actor',
        ])->loadCount([
            'assignments as active_assignments_count' => fn (Builder $assignments) => $assignments
                ->whereIn('status', ['assigned', 'acknowledged']),
            'assignments as acknowledged_assignments_count' => fn (Builder $assignments) => $assignments
                ->where('status', 'acknowledged'),
            'assignments as pending_attendance_count' => fn (Builder $assignments) => $assignments
                ->whereIn('status', ['assigned', 'acknowledged'])
                ->where(fn (Builder $attendance) => $attendance
                    ->whereDoesntHave('attendance')
                    ->orWhereHas('attendance', fn (Builder $record) => $record->where('status', 'pending'))),
            'evidence as current_evidence_count' => fn (Builder $evidence) => $evidence
                ->where('is_current', true),
        ]);

        $state = $this->selectionActivityState($session);
        $program = $session->scholarship;
        $activeAssignments = $session->assignments->whereIn('status', ['assigned', 'acknowledged']);

        return [
            'id' => (int) $session->id,
            'title' => $session->title,
            'program' => $program?->title,
            'provider' => $program?->provider?->providerProfile?->provider_name ?: $program?->provider?->name,
            'stage_key' => $session->stage_key,
            'stage_label' => $this->stageLabel($session->stage_key),
            'status' => $session->status,
            'status_label' => str($session->status)->headline()->toString(),
            'oversight_status' => $state['status'],
            'attention_message' => $state['flag'],
            'starts_at' => $session->starts_at?->format('M d, Y · g:i A'),
            'ends_at' => $session->ends_at?->format('M d, Y · g:i A'),
            'timezone' => $session->timezone,
            'mode' => str($session->mode)->headline()->toString(),
            'venue' => $session->venue,
            'location_address' => $session->location_address,
            'instructions' => $session->instructions,
            'capacity' => $session->capacity === null ? null : (int) $session->capacity,
            'proof_required' => (bool) $session->proof_required,
            'published_at' => $session->published_at?->format('M d, Y · g:i A'),
            'completed_at' => $session->completed_at?->format('M d, Y · g:i A'),
            'cancelled_at' => $session->cancelled_at?->format('M d, Y · g:i A'),
            'counts' => [
                'participants' => (int) $session->active_assignments_count,
                'acknowledged' => (int) $session->acknowledged_assignments_count,
                'pending_attendance' => (int) $session->pending_attendance_count,
                'proof' => (int) $session->current_evidence_count,
            ],
            'participants' => $activeAssignments->map(function ($assignment): array {
                $applicant = $assignment->application?->applicant;
                $attendance = $assignment->attendance;

                return [
                    'assignment_id' => (int) $assignment->id,
                    'name' => $applicant?->name ?: 'Applicant record',
                    'photo_url' => filled($applicant?->studentProfile?->profile_photo_path)
                        ? route('admin.applicants.profile-photo.view', $applicant)
                        : null,
                    'assignment_status' => $assignment->status,
                    'assignment_status_label' => str($assignment->status)->headline()->toString(),
                    'assigned_at' => $assignment->assigned_at?->format('M d, Y · g:i A'),
                    'acknowledged_at' => $assignment->acknowledged_at?->format('M d, Y · g:i A'),
                    'attendance_status' => $attendance?->status ?: 'pending',
                    'attendance_label' => str($attendance?->status ?: 'pending')->headline()->toString(),
                    'attendance_recorded_at' => $attendance?->recorded_at?->format('M d, Y · g:i A'),
                    'proof_count' => $assignment->evidence->where('is_current', true)->count(),
                ];
            })->values(),
            'evidence' => $session->evidence
                ->where('is_current', true)
                ->sortByDesc('uploaded_at')
                ->map(fn ($evidence): array => [
                    'id' => (int) $evidence->id,
                    'name' => $evidence->original_name,
                    'category' => str($evidence->category)->headline()->toString(),
                    'scope' => $evidence->scope,
                    'scope_label' => $evidence->scope === 'applicant'
                        ? ($evidence->assignment?->application?->applicant?->name ?: 'Applicant proof')
                        : 'Session proof',
                    'event_date' => $evidence->event_date?->format('M d, Y'),
                    'uploaded_at' => $evidence->uploaded_at?->format('M d, Y · g:i A'),
                    'uploaded_by' => $evidence->uploader?->name,
                    'size' => $this->formatBytes((int) $evidence->size),
                    'view_url' => route('admin.workspaces.reviews.selection-activities.evidence.view', $evidence),
                ])->values(),
            'history' => $session->auditEvents
                ->sortByDesc('occurred_at')
                ->map(fn ($event): array => [
                    'id' => (int) $event->id,
                    'event' => str($event->event_type)->headline()->toString(),
                    'status_change' => collect([$event->from_status, $event->to_status])->filter()->implode(' → '),
                    'actor' => $event->actor?->name ?: 'System',
                    'occurred_at' => $event->occurred_at?->format('M d, Y · g:i A'),
                ])->values(),
        ];
    }

    private function benefitBaseQuery(string $status): Builder
    {
        $query = RecipientBenefitReleaseRecord::query();

        if ($status === 'attention') {
            $query->where(function (Builder $builder): void {
                $builder->whereIn('status', ['missed', 'withheld'])
                    ->orWhereHas('receiptResponse', fn (Builder $response) => $response->where('status', 'open'))
                    ->orWhere(function (Builder $record): void {
                        $record->where('status', 'released')
                            ->where(function (Builder $evidence): void {
                                $evidence->whereNull('receipt_path')->orWhere('receipt_path', '');
                            });
                    })
                    ->orWhere(function (Builder $record): void {
                        $record->whereIn('status', ['scheduled', 'prepared'])
                            ->whereHas('release', fn (Builder $release) => $release->where('release_at', '<', now()));
                    });
            });
        } elseif ($status === 'documented') {
            $query->where('status', 'released')->whereNotNull('receipt_path')->where('receipt_path', '!=', '');
        } elseif ($status === 'released') {
            $query->where('status', 'released');
        } elseif ($status === 'scheduled') {
            $query->whereIn('status', ['scheduled', 'prepared']);
        }

        return $query;
    }

    private function monitoringBaseQuery(string $status): Builder
    {
        $closedStatuses = [
            RecipientSupportCatalog::CASE_CLOSED,
            RecipientSupportCatalog::CASE_WAIVED,
            RecipientSupportCatalog::CASE_CANCELLED,
        ];
        $query = RecipientMonitoringCase::query();

        if ($status === 'attention') {
            $query->whereNotIn('status', $closedStatuses)
                ->where(function (Builder $builder): void {
                    $builder->where('due_status', 'overdue')
                        ->orWhereIn('status', [
                            RecipientSupportCatalog::CASE_EXTENSION_PENDING,
                            RecipientSupportCatalog::CASE_FOLLOW_UP,
                        ])
                        ->orWhere(function (Builder $providerWork): void {
                            $providerWork->where('next_action_role', 'provider')
                                ->where('updated_at', '<=', now()->subDays(2));
                        });
                });
        } elseif ($status === 'closed') {
            $query->whereIn('status', $closedStatuses);
        } elseif ($status === 'reviewed') {
            $query->whereHas('application.monitoringOversightReviews', fn (Builder $review) => $review->where('outcome', 'reviewed'));
        } elseif ($status === 'open') {
            $query->whereNotIn('status', $closedStatuses);
        }

        return $query;
    }

    private function monitoringCaseState(RecipientMonitoringCase $case): array
    {
        $age = (int) ($case->updated_at?->copy()->startOfDay()->diffInDays(now()->startOfDay()) ?? 0);
        $overdueDays = (int) ($case->effective_due_at?->copy()->startOfDay()->diffInDays(now()->startOfDay()) ?? 0);
        $providerWaiting = $case->next_action_role === 'provider';
        $issue = match (true) {
            $case->due_status === 'overdue' => 'Check-in is past due',
            $case->status === RecipientSupportCatalog::CASE_EXTENSION_PENDING => 'Recipient request needs a decision',
            $case->status === RecipientSupportCatalog::CASE_FOLLOW_UP => 'Provider follow-up remains open',
            $case->status === RecipientSupportCatalog::CASE_READY_TO_CLOSE => 'Completed check-in needs closure',
            $providerWaiting => 'Provider action is waiting',
            default => null,
        };

        return [
            'needs_attention' => $issue !== null,
            'issue' => $issue,
            'severity' => $case->due_status === 'overdue' && $overdueDays >= 7 ? 'high' : 'medium',
            'wait_label' => $case->due_status === 'overdue'
                ? $overdueDays.' day'.($overdueDays === 1 ? '' : 's').' overdue'
                : ($providerWaiting && $age > 0 ? 'Waiting '.$age.' day'.($age === 1 ? '' : 's') : null),
        ];
    }

    private function monitoringCaseStatusLabel(string $status): string
    {
        return match ($status) {
            RecipientSupportCatalog::CASE_AWAITING_AGREEMENT => 'Agreement needed',
            RecipientSupportCatalog::CASE_AWAITING_SUBMISSION => 'Waiting for records',
            RecipientSupportCatalog::CASE_SUBMITTED => 'Ready for review',
            RecipientSupportCatalog::CASE_UNDER_REVIEW => 'Review in progress',
            RecipientSupportCatalog::CASE_CORRECTION_REQUESTED => 'Replacement requested',
            RecipientSupportCatalog::CASE_EXTENSION_PENDING => 'Request pending',
            RecipientSupportCatalog::CASE_FOLLOW_UP => 'Follow-up needed',
            RecipientSupportCatalog::CASE_READY_TO_CLOSE => 'Ready to close',
            RecipientSupportCatalog::CASE_CLOSED => 'Closed',
            RecipientSupportCatalog::CASE_WAIVED => 'Waived',
            default => 'Cancelled',
        };
    }

    private function monitoringNextActionLabel(?string $action): ?string
    {
        return match ($action) {
            'accept_agreement' => 'Accept agreement',
            'submit_requirements' => 'Submit records',
            'record_requirement' => 'Record provider item',
            'decide_adjustment' => 'Decide request',
            'review_requirements' => 'Review records',
            'replace_record' => 'Replace record',
            'complete_follow_up', 'manage_follow_up' => 'Complete follow-up',
            'request_adjustment' => 'Request adjustment',
            'resolve_overdue' => 'Resolve overdue check-in',
            'close_check_in' => 'Close check-in',
            'wait_for_opening' => 'Wait for opening',
            default => null,
        };
    }

    private function selectionActivityBaseQuery(string $status): Builder
    {
        $query = ApplicationStageSession::query();

        if ($status === 'attention') {
            $this->applySelectionActivityAttention($query);
        } elseif ($status === 'active') {
            $query->whereIn('status', ['scheduled', 'ongoing']);
        } elseif (in_array($status, ['draft', 'completed', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        return $query;
    }

    private function applySelectionActivityAttention(Builder $query): void
    {
        $query->where(function (Builder $attention): void {
            $attention
                ->where(function (Builder $attendance): void {
                    $attendance->where(function (Builder $session): void {
                        $session->whereIn('status', ['scheduled', 'ongoing'])
                            ->where(function (Builder $schedule): void {
                                $schedule->where('ends_at', '<', now())
                                    ->orWhere(fn (Builder $fallback) => $fallback
                                        ->whereNull('ends_at')
                                        ->where('starts_at', '<', now()));
                            });
                    })->whereHas('assignments', fn (Builder $assignments) => $assignments
                        ->whereIn('status', ['assigned', 'acknowledged'])
                        ->where(fn (Builder $record) => $record
                            ->whereDoesntHave('attendance')
                            ->orWhereHas('attendance', fn (Builder $saved) => $saved->where('status', 'pending'))));
                })
                ->orWhere(function (Builder $completedAttendance): void {
                    $completedAttendance->where('status', 'completed')
                        ->whereHas('assignments', fn (Builder $assignments) => $assignments
                            ->whereIn('status', ['assigned', 'acknowledged'])
                            ->where(fn (Builder $record) => $record
                                ->whereDoesntHave('attendance')
                                ->orWhereHas('attendance', fn (Builder $saved) => $saved->where('status', 'pending'))));
                })
                ->orWhere(function (Builder $proof): void {
                    $proof->where('status', 'completed')
                        ->where('proof_required', true)
                        ->whereDoesntHave('evidence', fn (Builder $evidence) => $evidence->where('is_current', true));
                })
                ->orWhere(function (Builder $cancelled): void {
                    $cancelled->where('status', 'cancelled')
                        ->whereHas('assignments', fn (Builder $assignments) => $assignments
                            ->whereIn('status', ['assigned', 'acknowledged']));
                });
        });
    }

    private function selectionActivityState(ApplicationStageSession $session): array
    {
        $pendingAttendance = (int) ($session->pending_attendance_count ?? 0);
        $activeAssignments = (int) ($session->active_assignments_count ?? 0);
        $proofCount = (int) ($session->current_evidence_count ?? 0);
        $ended = ($session->ends_at ?: $session->starts_at)?->isPast() ?? false;

        if ($session->status === 'cancelled' && $activeAssignments > 0) {
            return ['status' => 'attention', 'flag' => 'Cancelled session still has active applicants.'];
        }
        if (in_array($session->status, ['scheduled', 'ongoing'], true) && $ended && $pendingAttendance > 0) {
            return ['status' => 'attention', 'flag' => "Attendance is overdue for {$pendingAttendance} applicant(s)."];
        }
        if ($session->status === 'completed' && $pendingAttendance > 0) {
            return ['status' => 'attention', 'flag' => "Attendance is incomplete for {$pendingAttendance} applicant(s)."];
        }
        if ($session->status === 'completed' && $session->proof_required && $proofCount === 0) {
            return ['status' => 'attention', 'flag' => 'Required session proof is missing.'];
        }

        return ['status' => $session->status === 'scheduled' || $session->status === 'ongoing' ? 'active' : $session->status, 'flag' => null];
    }

    private function stageLabel(string $stage): string
    {
        return ApplicationStageCatalog::definitions()[$stage]['label'] ?? str($stage)->headline()->toString();
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).' KB';
        }

        return round($bytes / 1048576, 1).' MB';
    }

    private function benefitFlags(RecipientBenefitReleaseRecord $record): array
    {
        $flags = [];
        if ($record->receiptResponse?->status === 'open') {
            $flags[] = 'Recipient reported an unresolved release issue.';
        }
        if (in_array($record->status, ['missed', 'withheld'], true)) {
            $flags[] = $record->status === 'withheld' ? 'Benefit was withheld.' : 'Recipient missed the release.';
        }
        if ($record->status === 'released' && blank($record->receipt_path)) {
            $flags[] = 'No receipt file was uploaded.';
        }
        if (in_array($record->status, ['scheduled', 'prepared'], true) && $record->release?->release_at?->isPast()) {
            $flags[] = 'Release date passed without a completed result.';
        }

        return $flags;
    }

    private function monitoringState(ScholarshipApplication $application): array
    {
        if ($application->monitoringSubmissions->contains(fn ($submission) => blank($submission->review_status) || in_array($submission->review_status, ['needs_replacement', 'not_met'], true))) {
            return ['status' => 'attention', 'flag' => 'A monitoring submission needs provider action.'];
        }
        if ($application->monitoringAdjustmentRequests->contains('status', 'pending')) {
            return ['status' => 'attention', 'flag' => 'An adjustment request is pending.'];
        }
        if ($application->monitoringInterventions->contains('status', 'open')) {
            return ['status' => 'attention', 'flag' => 'A recipient intervention remains open.'];
        }
        if ($application->benefitReleaseRecords->contains(fn ($record) => in_array($record->status, ['missed', 'withheld'], true) || $record->receiptResponse?->status === 'open')) {
            return ['status' => 'attention', 'flag' => 'A benefit release needs follow-up.'];
        }
        if ($application->supportDecisions->contains('response_status', 'open')) {
            return ['status' => 'attention', 'flag' => 'A support outcome response is unresolved.'];
        }
        if ($application->status === 'benefits_terminated' || $application->supportDecisions->contains(fn ($decision) => in_array($decision->decision, ['completed', 'terminated'], true))) {
            return ['status' => 'closed', 'flag' => null];
        }
        if ($application->monitoringOversightReviews->first()?->outcome === 'reviewed') {
            return ['status' => 'reviewed', 'flag' => null];
        }

        return ['status' => 'stable', 'flag' => null];
    }

    private function search(Builder $query, ?string $search, array $columns, array $relations): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $builder) use ($search, $columns, $relations): void {
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($column, 'like', "%{$search}%");
            }
            foreach ($relations as [$relation, $relationColumns]) {
                $builder->orWhereHas($relation, function (Builder $related) use ($search, $relationColumns): void {
                    $related->where(function (Builder $fields) use ($search, $relationColumns): void {
                        foreach ($relationColumns as $index => $column) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $fields->{$method}($column, 'like', "%{$search}%");
                        }
                    });
                });
            }
        });
    }

    private function page(Builder $query, array $filters, callable $map): array
    {
        $paginator = $query->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
        $items = collect($paginator->items())->map($map)->values();

        return [
            'items' => $items,
            'pagination' => $this->pagination($paginator),
        ];
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
