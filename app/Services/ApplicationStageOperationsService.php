<?php

namespace App\Services;

use App\Domain\Applications\ApplicationStageCatalog;
use App\Models\ApplicationStageAssignment;
use App\Models\ApplicationStageAttendance;
use App\Models\ApplicationStageAuditEvent;
use App\Models\ApplicationStageEvidence;
use App\Models\ApplicationStageSession;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Support\ScholarshipSelectionPlan;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplicationStageOperationsService
{
    private const ACTIVE_ASSIGNMENT_STATUSES = ['assigned', 'acknowledged'];

    private const OPEN_SESSION_STATUSES = ['draft', 'scheduled', 'ongoing'];

    public function createSession(Scholarship $scholarship, User $actor, array $data): ApplicationStageSession
    {
        $this->assertCanManage($actor, $scholarship);
        $this->assertProgramUsesStage($scholarship, $data['stage_key']);

        return DB::transaction(function () use ($scholarship, $actor, $data): ApplicationStageSession {
            $session = $scholarship->stageSessions()->create([
                ...$this->sessionAttributes($data),
                'status' => 'draft',
                'proof_required' => $data['proof_required']
                    ?? ApplicationStageCatalog::requiresProofByDefault($data['stage_key']),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->audit($session, $actor, 'session_created', null, 'draft');

            return $session->fresh();
        });
    }

    public function updateSession(
        Scholarship $scholarship,
        ApplicationStageSession $session,
        User $actor,
        array $data,
    ): ApplicationStageSession {
        $this->assertCanManage($actor, $scholarship);
        $this->assertSessionBelongsToProgram($session, $scholarship);

        return DB::transaction(function () use ($session, $actor, $data): ApplicationStageSession {
            $locked = ApplicationStageSession::query()->lockForUpdate()->findOrFail($session->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'session' => 'Only draft sessions can be edited. Cancel and replace a published session instead.',
                ]);
            }

            $locked->update([
                ...$this->sessionAttributes([
                    ...$locked->only([
                        'stage_key',
                        'title',
                        'starts_at',
                        'ends_at',
                        'timezone',
                        'mode',
                        'venue',
                        'location_address',
                        'latitude',
                        'longitude',
                        'meeting_url',
                        'capacity',
                        'instructions',
                    ]),
                    ...$data,
                ]),
                'proof_required' => $data['proof_required'] ?? $locked->proof_required,
                'updated_by' => $actor->id,
            ]);

            $this->audit($locked, $actor, 'session_updated', 'draft', 'draft');

            return $locked->fresh();
        });
    }

    public function publishSession(
        Scholarship $scholarship,
        ApplicationStageSession $session,
        User $actor,
    ): ApplicationStageSession {
        $this->assertCanManage($actor, $scholarship);
        $this->assertSessionBelongsToProgram($session, $scholarship);

        return DB::transaction(function () use ($session, $actor): ApplicationStageSession {
            $locked = ApplicationStageSession::query()
                ->with(['assignments.application'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'session' => 'Only a draft session can be published.',
                ]);
            }

            $this->assertSessionReadyToPublish($locked);
            $now = now();
            $locked->update([
                'status' => 'scheduled',
                'published_at' => $now,
                'updated_by' => $actor->id,
            ]);
            $this->audit($locked, $actor, 'session_published', 'draft', 'scheduled', [
                'assignment_count' => $locked->assignments->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)->count(),
            ]);

            $locked->assignments
                ->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)
                ->each(fn (ApplicationStageAssignment $assignment) => $this->notifyAssignment($locked, $assignment));

            return $locked->fresh();
        });
    }

    public function assignApplications(
        Scholarship $scholarship,
        ApplicationStageSession $session,
        User $actor,
        array $applicationIds,
        ?string $notes = null,
    ): Collection {
        $this->assertCanManage($actor, $scholarship);
        $this->assertSessionBelongsToProgram($session, $scholarship);

        return DB::transaction(function () use ($scholarship, $session, $actor, $applicationIds, $notes): Collection {
            $lockedSession = ApplicationStageSession::query()->lockForUpdate()->findOrFail($session->id);

            if (! in_array($lockedSession->status, ['draft', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'session' => 'Applicants can only be assigned to a draft or scheduled session.',
                ]);
            }

            $ids = collect($applicationIds)->map(fn ($id): int => (int) $id)->unique()->values();
            $applications = ScholarshipApplication::query()
                ->with('stageProgresses')
                ->where('scholarship_id', $scholarship->id)
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($applications->count() !== $ids->count()) {
                throw ValidationException::withMessages([
                    'application_ids' => 'One or more applicants do not belong to this program.',
                ]);
            }

            $existingIds = ApplicationStageAssignment::query()
                ->where('application_stage_session_id', $lockedSession->id)
                ->whereIn('scholarship_application_id', $ids)
                ->pluck('scholarship_application_id');
            $applications = $applications->whereNotIn('id', $existingIds);
            $this->assertCapacity($lockedSession, $applications->count());
            $assignments = collect();

            foreach ($applications as $application) {
                $this->assertApplicationAtStage($application, $lockedSession->stage_key);

                $hasOpenAssignment = ApplicationStageAssignment::query()
                    ->where('scholarship_application_id', $application->id)
                    ->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)
                    ->whereHas('session', fn ($query) => $query
                        ->where('stage_key', $lockedSession->stage_key)
                        ->whereIn('status', self::OPEN_SESSION_STATUSES))
                    ->exists();

                if ($hasOpenAssignment) {
                    throw ValidationException::withMessages([
                        'application_ids' => 'An applicant already has an active session. Use reschedule instead.',
                    ]);
                }

                $assignment = $lockedSession->assignments()->create([
                    'scholarship_application_id' => $application->id,
                    'status' => 'assigned',
                    'notes' => $notes,
                    'assigned_by' => $actor->id,
                    'assigned_at' => now(),
                ]);
                $assignment->attendance()->create(['status' => 'pending']);
                $this->updateProgress($application, $lockedSession, 'scheduled');
                $this->audit($lockedSession, $actor, 'applicant_assigned', null, 'assigned', [
                    'application_id' => $application->id,
                ], $assignment);

                if ($lockedSession->status === 'scheduled') {
                    $this->notifyAssignment($lockedSession, $assignment);
                }

                $assignments->push($assignment->fresh(['attendance', 'application.applicant']));
            }

            return $assignments;
        });
    }

    public function rescheduleAssignment(
        Scholarship $scholarship,
        ApplicationStageSession $sourceSession,
        ApplicationStageAssignment $assignment,
        ApplicationStageSession $targetSession,
        User $actor,
        ?string $notes = null,
    ): ApplicationStageAssignment {
        $this->assertCanManage($actor, $scholarship);
        $this->assertSessionBelongsToProgram($sourceSession, $scholarship);
        $this->assertSessionBelongsToProgram($targetSession, $scholarship);
        $this->assertAssignmentBelongsToSession($assignment, $sourceSession);

        return DB::transaction(function () use (
            $assignment,
            $targetSession,
            $actor,
            $notes,
        ): ApplicationStageAssignment {
            $lockedAssignment = ApplicationStageAssignment::query()
                ->with(['application.stageProgresses', 'session'])
                ->lockForUpdate()
                ->findOrFail($assignment->id);
            $lockedTarget = ApplicationStageSession::query()->lockForUpdate()->findOrFail($targetSession->id);

            if (! in_array($lockedAssignment->status, self::ACTIVE_ASSIGNMENT_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'assignment' => 'Only an active assignment can be rescheduled.',
                ]);
            }

            if ($lockedAssignment->session->scholarship_id !== $lockedTarget->scholarship_id
                || $lockedAssignment->session->stage_key !== $lockedTarget->stage_key) {
                throw ValidationException::withMessages([
                    'target_session_id' => 'Choose another session for the same program and stage.',
                ]);
            }

            if (! in_array($lockedTarget->status, ['draft', 'scheduled'], true)) {
                throw ValidationException::withMessages([
                    'target_session_id' => 'Choose a draft or scheduled replacement session.',
                ]);
            }

            if ($lockedTarget->id === $lockedAssignment->application_stage_session_id) {
                throw ValidationException::withMessages([
                    'target_session_id' => 'Choose a different session.',
                ]);
            }

            $this->assertApplicationAtStage($lockedAssignment->application, $lockedTarget->stage_key);
            $this->assertCapacity($lockedTarget, 1);

            if ($lockedTarget->assignments()->where('scholarship_application_id', $lockedAssignment->scholarship_application_id)->exists()) {
                throw ValidationException::withMessages([
                    'target_session_id' => 'This applicant is already assigned to the selected session.',
                ]);
            }

            $lockedAssignment->update([
                'status' => 'rescheduled',
                'notes' => $notes ?: $lockedAssignment->notes,
            ]);
            $replacement = $lockedTarget->assignments()->create([
                'scholarship_application_id' => $lockedAssignment->scholarship_application_id,
                'previous_assignment_id' => $lockedAssignment->id,
                'status' => 'assigned',
                'notes' => $notes,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);
            $replacement->attendance()->create(['status' => 'pending']);
            $this->updateProgress($lockedAssignment->application, $lockedTarget, 'scheduled');
            $this->audit($lockedTarget, $actor, 'applicant_rescheduled', 'rescheduled', 'assigned', [
                'application_id' => $lockedAssignment->scholarship_application_id,
                'previous_assignment_id' => $lockedAssignment->id,
                'previous_session_id' => $lockedAssignment->application_stage_session_id,
            ], $replacement);

            if ($lockedTarget->status === 'scheduled') {
                $this->notifyAssignment($lockedTarget, $replacement);
            }

            return $replacement->fresh(['attendance', 'application.applicant', 'previousAssignment']);
        });
    }

    public function acknowledgeAssignment(
        ApplicationStageAssignment $assignment,
        User $applicant,
    ): ApplicationStageAssignment {
        return DB::transaction(function () use ($assignment, $applicant): ApplicationStageAssignment {
            $locked = ApplicationStageAssignment::query()
                ->with(['application', 'session', 'attendance'])
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            if (! $applicant->isApplicant() || $locked->application?->applicant_id !== $applicant->id) {
                throw new AuthorizationException('You cannot acknowledge this activity.');
            }

            if ($locked->status === 'acknowledged') {
                return $locked;
            }

            if ($locked->status !== 'assigned'
                || ! in_array($locked->session?->status, ['scheduled', 'ongoing'], true)) {
                throw ValidationException::withMessages([
                    'assignment' => 'Only your current published activity can be acknowledged.',
                ]);
            }

            $locked->update([
                'status' => 'acknowledged',
                'acknowledged_at' => now(),
            ]);
            $this->audit(
                $locked->session,
                $applicant,
                'applicant_acknowledged',
                'assigned',
                'acknowledged',
                assignment: $locked,
            );

            return $locked->fresh(['application', 'session', 'attendance']);
        });
    }

    public function recordAttendance(
        Scholarship $scholarship,
        ApplicationStageSession $session,
        ApplicationStageAssignment $assignment,
        User $actor,
        string $status,
        ?string $notes = null,
    ): ApplicationStageAttendance {
        $this->assertCanManage($actor, $scholarship);
        $this->assertSessionBelongsToProgram($session, $scholarship);
        $this->assertAssignmentBelongsToSession($assignment, $session);

        return DB::transaction(function () use ($session, $assignment, $actor, $status, $notes): ApplicationStageAttendance {
            $lockedSession = ApplicationStageSession::query()->lockForUpdate()->findOrFail($session->id);
            $lockedAssignment = ApplicationStageAssignment::query()
                ->with(['application.stageProgresses', 'attendance'])
                ->lockForUpdate()
                ->findOrFail($assignment->id);

            if (! in_array($lockedSession->status, ['scheduled', 'ongoing'], true)) {
                throw ValidationException::withMessages([
                    'session' => 'Attendance can only be recorded for a published session.',
                ]);
            }

            if ($lockedSession->starts_at->isFuture()) {
                throw ValidationException::withMessages([
                    'attendance_status' => 'Attendance cannot be recorded before the session starts.',
                ]);
            }

            if (! in_array($lockedAssignment->status, self::ACTIVE_ASSIGNMENT_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'assignment' => 'Attendance cannot be changed for an inactive assignment.',
                ]);
            }

            $previous = $lockedAssignment->attendance?->status ?? 'pending';
            $attendance = $lockedAssignment->attendance()->updateOrCreate([], [
                'status' => $status,
                'notes' => $notes,
                'recorded_by' => $actor->id,
                'recorded_at' => now(),
            ]);

            if ($lockedSession->status === 'scheduled') {
                $lockedSession->update(['status' => 'ongoing', 'updated_by' => $actor->id]);
            }

            $hasProof = ! $lockedSession->proof_required
                || $lockedSession->evidence()->where('is_current', true)->exists()
                || $lockedAssignment->evidence()->where('is_current', true)->exists();
            $this->updateProgress(
                $lockedAssignment->application,
                $lockedSession,
                $hasProof ? 'ready_for_review' : 'needs_action',
            );
            $this->audit($lockedSession, $actor, 'attendance_recorded', $previous, $status, [
                'application_id' => $lockedAssignment->scholarship_application_id,
                'attendance_id' => $attendance->id,
            ], $lockedAssignment);

            return $attendance->fresh('recorder');
        });
    }

    public function completeSession(
        Scholarship $scholarship,
        ApplicationStageSession $session,
        User $actor,
    ): ApplicationStageSession {
        $this->assertCanManage($actor, $scholarship);
        $this->assertSessionBelongsToProgram($session, $scholarship);

        return DB::transaction(function () use ($session, $actor): ApplicationStageSession {
            $locked = ApplicationStageSession::query()
                ->with(['assignments.attendance', 'assignments.application.stageProgresses'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if (! in_array($locked->status, ['scheduled', 'ongoing'], true)) {
                throw ValidationException::withMessages([
                    'session' => 'Only a published session can be completed.',
                ]);
            }

            if ($locked->starts_at->isFuture()) {
                throw ValidationException::withMessages([
                    'session' => 'This session cannot be completed before it starts.',
                ]);
            }

            $activeAssignments = $locked->assignments->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES);

            if ($activeAssignments->isEmpty()) {
                throw ValidationException::withMessages([
                    'session' => 'Assign at least one applicant before completing this session.',
                ]);
            }

            if ($activeAssignments->contains(fn (ApplicationStageAssignment $item): bool => ($item->attendance?->status ?? 'pending') === 'pending')) {
                throw ValidationException::withMessages([
                    'attendance' => 'Record attendance for every assigned applicant first.',
                ]);
            }

            if ($locked->proof_required && ! $locked->evidence()->where('is_current', true)->exists()) {
                throw ValidationException::withMessages([
                    'evidence' => 'Upload session proof before marking this activity complete.',
                ]);
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'updated_by' => $actor->id,
            ]);

            foreach ($activeAssignments as $assignment) {
                $this->updateProgress($assignment->application, $locked, 'ready_for_review');
            }

            $this->audit($locked, $actor, 'session_completed', $session->status, 'completed', [
                'assignment_count' => $activeAssignments->count(),
                'application_stage_unchanged' => true,
            ]);

            return $locked->fresh();
        });
    }

    public function cancelSession(
        Scholarship $scholarship,
        ApplicationStageSession $session,
        User $actor,
        string $reason,
    ): ApplicationStageSession {
        $this->assertCanManage($actor, $scholarship);
        $this->assertSessionBelongsToProgram($session, $scholarship);

        return DB::transaction(function () use ($session, $actor, $reason): ApplicationStageSession {
            $locked = ApplicationStageSession::query()
                ->with(['assignments.application.stageProgresses'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if (in_array($locked->status, ['completed', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'session' => 'A completed or cancelled session cannot be cancelled again.',
                ]);
            }

            $previous = $locked->status;
            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'updated_by' => $actor->id,
            ]);

            foreach ($locked->assignments->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES) as $assignment) {
                $assignment->update(['status' => 'cancelled', 'notes' => $reason]);
                $this->updateProgress(
                    $assignment->application,
                    $locked,
                    $locked->stage_key === 'formal_application' ? 'ready_for_review' : 'waiting_for_schedule',
                );
                $this->notifyCancellation($locked, $assignment, $reason);
            }

            $this->audit($locked, $actor, 'session_cancelled', $previous, 'cancelled', [
                'reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    public function storeEvidence(
        Scholarship $scholarship,
        ApplicationStageSession $session,
        User $actor,
        UploadedFile $file,
        array $data,
        ?ApplicationStageAssignment $assignment = null,
    ): ApplicationStageEvidence {
        $this->assertCanManage($actor, $scholarship);
        $this->assertSessionBelongsToProgram($session, $scholarship);

        if ($assignment) {
            $this->assertAssignmentBelongsToSession($assignment, $session);
        }

        if ($session->status === 'cancelled') {
            throw ValidationException::withMessages([
                'session' => 'Proof cannot be added to a cancelled session.',
            ]);
        }

        $disk = 'local';
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $filename = Str::uuid().'.'.$extension;
        $directory = "application-stage-evidence/{$scholarship->id}/{$session->stage_key}/{$session->id}";
        $path = $file->storeAs($directory, $filename, $disk);

        if (! is_string($path)) {
            throw ValidationException::withMessages([
                'evidence_file' => 'The proof file could not be stored. Please try again.',
            ]);
        }

        try {
            return DB::transaction(function () use (
                $scholarship,
                $session,
                $assignment,
                $actor,
                $file,
                $data,
                $disk,
                $path,
            ): ApplicationStageEvidence {
                $query = ApplicationStageEvidence::query()
                    ->where('application_stage_session_id', $session->id)
                    ->where('scope', $assignment ? 'applicant' : 'session')
                    ->where('category', $data['category'])
                    ->where('is_current', true);

                $assignment
                    ? $query->where('application_stage_assignment_id', $assignment->id)
                    : $query->whereNull('application_stage_assignment_id');

                $previous = $query->lockForUpdate()->latest('id')->first();

                if ($previous) {
                    $previous->update(['is_current' => false]);
                }

                $evidence = ApplicationStageEvidence::create([
                    'scholarship_id' => $scholarship->id,
                    'scholarship_application_id' => $assignment?->scholarship_application_id,
                    'application_stage_session_id' => $session->id,
                    'application_stage_assignment_id' => $assignment?->id,
                    'supersedes_evidence_id' => $previous?->id,
                    'stage_key' => $session->stage_key,
                    'scope' => $assignment ? 'applicant' : 'session',
                    'category' => $data['category'],
                    'original_name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'disk' => $disk,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'size' => $file->getSize() ?: 0,
                    'event_date' => $data['event_date'] ?? null,
                    'note' => $data['note'] ?? null,
                    'visibility' => $data['visibility'] ?? 'provider_private',
                    'is_current' => true,
                    'uploaded_by' => $actor->id,
                    'uploaded_at' => now(),
                ]);

                $this->audit($session, $actor, 'evidence_uploaded', null, 'current', [
                    'evidence_id' => $evidence->id,
                    'category' => $evidence->category,
                    'scope' => $evidence->scope,
                    'supersedes_evidence_id' => $previous?->id,
                ], $assignment);
                $this->refreshProgressAfterEvidence($session, $assignment);

                return $evidence->fresh('uploader');
            });
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    private function sessionAttributes(array $data): array
    {
        $mode = $data['mode'];
        $usesVenue = in_array($mode, ['onsite', 'hybrid'], true);
        $usesMeetingLink = in_array($mode, ['online', 'hybrid'], true);

        return [
            'stage_key' => $data['stage_key'],
            'title' => trim($data['title']),
            'starts_at' => CarbonImmutable::parse($data['starts_at']),
            'ends_at' => filled($data['ends_at'] ?? null) ? CarbonImmutable::parse($data['ends_at']) : null,
            'timezone' => $data['timezone'] ?? 'Asia/Manila',
            'mode' => $mode,
            'venue' => $usesVenue ? ($data['venue'] ?? null) : null,
            'location_address' => $usesVenue ? ($data['location_address'] ?? null) : null,
            'latitude' => $usesVenue ? ($data['latitude'] ?? null) : null,
            'longitude' => $usesVenue ? ($data['longitude'] ?? null) : null,
            'meeting_url' => $usesMeetingLink ? ($data['meeting_url'] ?? null) : null,
            'capacity' => $data['capacity'] ?? null,
            'instructions' => $data['instructions'] ?? null,
        ];
    }

    private function assertSessionReadyToPublish(ApplicationStageSession $session): void
    {
        if ($session->starts_at->isPast()) {
            throw ValidationException::withMessages([
                'starts_at' => 'Choose a future date and time before publishing.',
            ]);
        }

        if ($session->ends_at && $session->ends_at->lessThanOrEqualTo($session->starts_at)) {
            throw ValidationException::withMessages([
                'ends_at' => 'The end must be after the session starts.',
            ]);
        }

        if (in_array($session->mode, ['onsite', 'hybrid'], true) && blank($session->venue)) {
            throw ValidationException::withMessages([
                'venue' => 'Add the venue before publishing this session.',
            ]);
        }

        if (in_array($session->mode, ['online', 'hybrid'], true) && blank($session->meeting_url)) {
            throw ValidationException::withMessages([
                'meeting_url' => 'Add the meeting link before publishing this session.',
            ]);
        }

        $this->assertCapacity($session, 0);
    }

    private function assertCapacity(ApplicationStageSession $session, int $additional): void
    {
        if ($session->capacity === null) {
            return;
        }

        $activeCount = $session->assignments()
            ->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)
            ->count();

        if ($activeCount + $additional > $session->capacity) {
            throw ValidationException::withMessages([
                'application_ids' => 'This session does not have enough remaining capacity.',
            ]);
        }
    }

    private function assertApplicationAtStage(ScholarshipApplication $application, string $stage): void
    {
        if (in_array($application->application_state, ['closed', 'withdrawn'], true)
            || $application->workflow_stage !== $stage) {
            throw ValidationException::withMessages([
                'application_ids' => 'One or more applicants are no longer waiting at this stage.',
            ]);
        }

        $progress = $application->stageProgresses->firstWhere('stage_key', $stage);

        if (! $progress || $progress->status !== 'current') {
            throw ValidationException::withMessages([
                'application_ids' => 'One or more applicant stage records are not ready for scheduling.',
            ]);
        }
    }

    private function updateProgress(
        ScholarshipApplication $application,
        ApplicationStageSession $session,
        string $status,
    ): void {
        if ($application->workflow_stage !== $session->stage_key) {
            return;
        }

        $application->stageProgresses()
            ->where('stage_key', $session->stage_key)
            ->where('status', 'current')
            ->update([
                'operational_status' => $status,
                'proof_required' => $session->proof_required,
                'updated_at' => now(),
            ]);
    }

    private function refreshProgressAfterEvidence(
        ApplicationStageSession $session,
        ?ApplicationStageAssignment $assignment,
    ): void {
        $assignments = $assignment
            ? collect([$assignment->loadMissing(['application.stageProgresses', 'attendance'])])
            : $session->assignments()
                ->with(['application.stageProgresses', 'attendance'])
                ->whereIn('status', self::ACTIVE_ASSIGNMENT_STATUSES)
                ->get();

        foreach ($assignments as $item) {
            if (($item->attendance?->status ?? 'pending') !== 'pending') {
                $this->updateProgress($item->application, $session, 'ready_for_review');
            }
        }
    }

    private function audit(
        ApplicationStageSession $session,
        User $actor,
        string $event,
        ?string $from,
        ?string $to,
        array $metadata = [],
        ?ApplicationStageAssignment $assignment = null,
    ): ApplicationStageAuditEvent {
        return ApplicationStageAuditEvent::create([
            'scholarship_id' => $session->scholarship_id,
            'scholarship_application_id' => $assignment?->scholarship_application_id,
            'application_stage_session_id' => $session->id,
            'application_stage_assignment_id' => $assignment?->id,
            'stage_key' => $session->stage_key,
            'event_type' => $event,
            'from_status' => $from,
            'to_status' => $to,
            'metadata' => $metadata ?: null,
            'actor_id' => $actor->id,
            'occurred_at' => now(),
        ]);
    }

    private function notifyAssignment(
        ApplicationStageSession $session,
        ApplicationStageAssignment $assignment,
    ): void {
        $assignment->loadMissing('application');
        $application = $assignment->application;

        if (! $application) {
            return;
        }

        PortalNotification::updateOrCreate([
            'deduplication_key' => "stage-session-assignment-{$assignment->id}-scheduled",
        ], [
            'user_id' => $application->applicant_id,
            'type' => 'application_schedule',
            'title' => $session->title,
            'message' => 'A '.ScholarshipSelectionPlan::label($session->stage_key).' session has been scheduled. Open your application for the date and instructions.',
            'action_url' => route('dashboard.applications.show', $application, false),
            'read_at' => null,
        ]);
    }

    private function notifyCancellation(
        ApplicationStageSession $session,
        ApplicationStageAssignment $assignment,
        string $reason,
    ): void {
        $assignment->loadMissing('application');
        $application = $assignment->application;

        if (! $application) {
            return;
        }

        PortalNotification::updateOrCreate([
            'deduplication_key' => "stage-session-assignment-{$assignment->id}-cancelled",
        ], [
            'user_id' => $application->applicant_id,
            'type' => 'application_schedule',
            'title' => $session->title.' cancelled',
            'message' => "The provider cancelled this session: {$reason}",
            'action_url' => route('dashboard.applications.show', $application, false),
            'read_at' => null,
        ]);
    }

    private function assertCanManage(User $actor, Scholarship $scholarship): void
    {
        if (! $actor->isProvider()
            || ! $actor->hasPortalPermission('manage_selection_activities')
            || (int) $scholarship->provider_id !== $actor->providerOrganizationId()
            || ! $actor->canAccessProviderProgram($scholarship)) {
            throw new AuthorizationException('You cannot manage selection activities for this program.');
        }
    }

    private function assertProgramUsesStage(Scholarship $scholarship, string $stage): void
    {
        if (! ApplicationStageCatalog::supportsSessions($stage)
            || ! in_array($stage, ScholarshipSelectionPlan::normalize($scholarship->selection_stages), true)) {
            throw ValidationException::withMessages([
                'stage_key' => 'Choose a scheduled stage included in this program workflow.',
            ]);
        }
    }

    private function assertSessionBelongsToProgram(
        ApplicationStageSession $session,
        Scholarship $scholarship,
    ): void {
        abort_unless((int) $session->scholarship_id === (int) $scholarship->id, 404);
    }

    private function assertAssignmentBelongsToSession(
        ApplicationStageAssignment $assignment,
        ApplicationStageSession $session,
    ): void {
        abort_unless((int) $assignment->application_stage_session_id === (int) $session->id, 404);
    }
}
