<?php

namespace Database\Seeders\Support;

use App\Models\ApplicationSchedule;
use App\Models\ApplicationStageSession;
use App\Models\RecipientBenefitRelease;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringPlan;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Services\RecipientBenefitPayoutService;
use App\Support\ScholarshipSelectionPlan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

final class WorkflowDemoTimelineAudit
{
    // Check-ins and in-person distributions store a start, not a duration.
    private const DEMO_APPOINTMENT_MINUTES = 30;

    private array $violations = [];

    private array $bookings = [];

    private readonly CarbonImmutable $now;

    private function __construct()
    {
        $this->now = CarbonImmutable::now(WorkflowDemoTimeline::TIMEZONE);
    }

    public static function inspect(): array
    {
        $audit = new self;
        $programs = Scholarship::with(['stageSessions.assignments.application', 'stageSessions.assignments.attendance', 'stageSessions.evidence'])->get();
        $applications = ScholarshipApplication::with(['scholarship', 'stageProgresses', 'stageAssignments.session'])->get();
        $plans = RecipientMonitoringPlan::with('scholarship')->get();
        $cycles = RecipientMonitoringCycle::with(['plan', 'cases.application', 'submissions.reviews'])->get();
        $releases = RecipientBenefitRelease::with(['scholarship', 'records.application', 'records.receiptResponse', 'records.payoutDetails:id,release_record_id,method,submitted_at,review_status,reviewed_at'])->get();

        $audit->check($programs->isNotEmpty(), 'No scholarship rounds to audit.');
        $audit->check(ApplicationSchedule::query()->doesntExist(), 'Legacy schedules are not part of the fresh demo. Use stage sessions so every activity can be audited.');
        foreach ($programs as $program) $audit->program($program);
        foreach ($applications as $application) $audit->application($application);
        foreach ($plans as $plan) $audit->plan($plan);
        foreach ($cycles as $cycle) $audit->checkIn($cycle);
        foreach ($releases as $release) $audit->release($release);
        $audit->checkOverlaps();

        return [
            'timezone' => WorkflowDemoTimeline::TIMEZONE,
            'rounds' => $programs->count(), 'applications' => $applications->count(),
            'sessions' => $programs->sum(fn (Scholarship $program) => $program->stageSessions->count()),
            'check_ins' => $cycles->count(), 'releases' => $releases->count(),
            'violations' => $audit->violations,
        ];
    }

    public static function assertValid(): array
    {
        $report = self::inspect();
        if ($report['violations'] !== []) {
            throw new RuntimeException("Demo timeline audit failed:\n- ".implode("\n- ", $report['violations']));
        }

        return $report;
    }

    private function program(Scholarship $program): void
    {
        $label = 'Round '.$program->title;
        $close = $this->day($program->deadline);
        $this->ordered($this->day($program->application_opens_at), $close, "$label: intake must close after it opens.", strict: true);
        $this->ordered($close, $this->day($program->handoff_deadline), "$label: formal handoff deadline must follow intake.", strict: true);
        $this->ordered($this->day($program->handoff_deadline), $this->day($program->expected_results_at), "$label: expected results must follow the handoff deadline.", strict: true);
        $this->ordered($this->day($program->expected_results_at), $this->day($program->support_starts_at), "$label: support must start after expected results.", strict: true);
        $this->ordered($this->day($program->support_starts_at), $this->day($program->support_ends_at), "$label: support must end after it starts.", strict: true);

        $sessions = $program->stageSessions->whereNotIn('status', ['cancelled', 'draft']);
        if ($sessions->isNotEmpty()) {
            $this->ordered($close?->endOfDay(), $this->now, "$label: open or upcoming intake must not have published selection sessions.", strict: true);
        }
        $stagePlan = ScholarshipSelectionPlan::providerStages($program->selection_stages);
        foreach ($sessions as $session) {
            $this->check(in_array($session->stage_key, $stagePlan, true), "$label: session {$session->id} is not in its configured stages.");
            $deadline = $session->stage_key === 'formal_application' ? $program->handoff_deadline : $program->expected_results_at;
            $this->ordered($session->ends_at, $this->day($deadline)?->endOfDay(), "$label: session {$session->id} ends after its stage deadline.");
            $this->session($session, $close?->endOfDay());
        }

        // Rounds in this dataset have separate selection phases, even when participants differ.
        $previous = null;
        foreach ($stagePlan as $stage) {
            $group = $sessions->where('stage_key', $stage);
            if ($group->isEmpty()) continue;
            if ($previous !== null) {
                $this->ordered($previous->max('ends_at'), $group->min('starts_at'), "$label: {$previous->first()->stage_key} sessions overlap or follow the $stage phase.");
            }
            $previous = $group;
        }
    }

    private function session(ApplicationStageSession $session, ?CarbonImmutable $close): void
    {
        $label = "Session {$session->id} ({$session->title})";
        $this->check($session->timezone === WorkflowDemoTimeline::TIMEZONE, "$label: use Manila time.");
        $this->ordered($close, $session->published_at, "$label: published before intake closed.", strict: true);
        $this->ordered($close, $session->starts_at, "$label: scheduled before intake closed.", strict: true);
        $this->ordered($session->published_at, $session->starts_at, "$label: starts before publication.", strict: true);
        $this->ordered($session->starts_at, $session->ends_at, "$label: end must follow its start.", strict: true);
        $this->ordered($session->published_at, $this->now, "$label: publication is in the future.");
        if ($session->status === 'completed' || $session->completed_at) {
            $this->ordered($session->ends_at, $session->completed_at, "$label: completed before it ended.");
            $this->ordered($session->completed_at, $this->now, "$label: completion is in the future.");
        }
        foreach ($session->evidence as $proof) {
            $this->ordered($session->starts_at, $proof->uploaded_at, "$label: proof was uploaded before the activity.");
            $this->ordered($proof->uploaded_at, $session->completed_at ?? $this->now, "$label: proof is dated after session completion or the audit time.");
            $this->check($proof->event_date?->toDateString() === $this->day($session->starts_at)?->toDateString(), "$label: proof event date does not match the session date.");
        }
        $assignments = $session->assignments->whereIn('status', ['assigned', 'acknowledged']);
        foreach ($assignments as $assignment) {
            $this->check($assignment->application->scholarship_id === $session->scholarship_id, "$label: applicant is assigned from another round.");
            $this->ordered($assignment->application->submitted_at, $assignment->assigned_at, "$label: assignment predates the application.");
            $this->ordered($assignment->assigned_at, $session->starts_at, "$label: assignment is later than the session start.");
            $this->ordered($assignment->assigned_at, $this->now, "$label: assignment is dated in the future.");
            if ($assignment->status === 'acknowledged' || $assignment->acknowledged_at) {
                $this->ordered($assignment->assigned_at, $assignment->acknowledged_at, "$label: acknowledgement predates assignment.");
                $this->ordered($assignment->acknowledged_at, $this->now, "$label: acknowledgement is in the future.");
            }
            if ($attendance = $assignment->attendance) {
                if ($attendance->recorded_at || in_array($attendance->status, ['present', 'absent'], true)) {
                    $this->ordered($session->starts_at, $attendance->recorded_at, "$label: attendance was recorded before the session started.");
                    $this->ordered($attendance->recorded_at, $session->completed_at ?? $this->now, "$label: attendance is dated after session completion or the audit time.");
                }
            }
        }
        $this->book('session:'.$session->id, $label, $session->starts_at, $session->ends_at, $session->created_by,
            $session->mode === 'online' ? null : $session->venue, $assignments->pluck('application.applicant_id'));
    }

    private function application(ScholarshipApplication $application): void
    {
        $program = $application->scholarship;
        $label = "Application {$application->id} ({$program->title})";
        $close = $this->day($program->deadline)?->endOfDay();
        $this->ordered($this->day($program->application_opens_at), $application->submitted_at, "$label: submitted before intake opened.");
        $this->ordered($application->submitted_at, $close, "$label: submitted after intake closed.");
        $this->ordered($application->submitted_at, $this->now, "$label: submission is in the future.");
        $plan = ScholarshipSelectionPlan::normalize($program->selection_stages);
        $progresses = $application->stageProgresses->keyBy('stage_key');
        $assignments = $application->stageAssignments->whereIn('status', ['assigned', 'acknowledged'])->groupBy('session.stage_key');
        $previous = $application->submitted_at;
        foreach ($plan as $position => $stage) {
            $progress = $progresses->get($stage);
            $activities = $assignments->get($stage, collect());
            $this->check($progress && $progress->position === $position, "$label: $stage is missing or out of order in the configured plan.");
            if (! $progress) continue;
            if ($progress->status !== 'pending' || $progress->started_at || $progress->decided_at || $activities->isNotEmpty()) {
                $this->ordered($previous, $progress->started_at, "$label: $stage started before its prerequisite stage was decided.");
                $this->ordered($progress->started_at, $this->now, "$label: $stage is marked started in the future.");
            }
            foreach ($activities as $assignment) {
                $this->ordered($progress->started_at, $assignment->assigned_at, "$label: $stage assignment predates the application's stage.");
                if ($progress->result !== null && in_array($stage, ['exam', 'interview'], true)) {
                    $this->ordered($assignment->session->completed_at, $progress->decided_at, "$label: $stage result predates session completion.");
                }
            }
            if ($progress->decided_at || $progress->result !== null || $progress->completed_at) {
                if (in_array($stage, ['exam', 'interview'], true)) {
                    $this->check($activities->isNotEmpty(), "$label: $stage result is missing a session.");
                }
                $this->ordered($close, $progress->decided_at, "$label: $stage result was recorded before intake closed.", strict: true);
                $this->ordered($progress->started_at, $progress->decided_at, "$label: $stage result predates the stage start.");
                $this->ordered($progress->decided_at, $this->now, "$label: $stage result is in the future.");
                $this->ordered($progress->started_at, $progress->completed_at, "$label: $stage completion predates the stage start.");
                $this->ordered($progress->completed_at, $this->now, "$label: $stage completion is in the future.");
            }
            $previous = $progress->decided_at;
        }
        if ($application->final_outcome === 'selected' || $application->outcome_at) {
            $this->ordered($progresses->get('decision')?->decided_at, $application->outcome_at, "$label: award predates its final decision.");
            $this->ordered($application->outcome_at, $this->now, "$label: award is dated in the future.");
        }
        if ($application->student_response_status === 'accepted') {
            $this->check($application->final_outcome === 'selected', "$label: agreement accepted without a selected outcome.");
            $this->ordered($application->outcome_at, $application->student_responded_at, "$label: agreement accepted before award selection.");
            $this->ordered($application->student_responded_at, $this->now, "$label: agreement acceptance is in the future.");
        }
    }

    private function plan(RecipientMonitoringPlan $plan): void
    {
        $label = "Check-in plan {$plan->id} ({$plan->scholarship->title})";
        $this->check($plan->scholarship->monitoring_policy !== 'none', "$label: one-time support must not have a recurring plan.");
        $this->ordered($this->day($plan->scholarship->support_starts_at), $this->day($plan->starts_on), "$label: starts before program support.");
        $this->ordered($this->day($plan->starts_on), $this->day($plan->ends_on), "$label: ends before it starts.", strict: true);
        $this->ordered($this->day($plan->ends_on), $this->day($plan->scholarship->support_ends_at), "$label: ends after program support.");
    }

    private function checkIn(RecipientMonitoringCycle $cycle): void
    {
        $label = "Check-in {$cycle->id} ({$cycle->title})";
        $this->check($cycle->plan?->scholarship_id === $cycle->scholarship_id, "$label: missing a matching check-in plan.");
        $this->ordered($cycle->plan?->activated_at, $cycle->published_at, "$label: published before its plan was activated.");
        $this->ordered($cycle->published_at, $this->now, "$label: publication is in the future.");
        $this->ordered($this->day($cycle->plan?->starts_on), $this->day($cycle->opens_at), "$label: opens outside its plan.");
        $this->ordered($this->day($cycle->opens_at), $this->day($cycle->due_at), "$label: due date predates opening.");
        $this->ordered($this->day($cycle->due_at), $this->day($cycle->plan?->ends_on), "$label: due date is after the plan ends.");
        $submissions = $cycle->submissions->groupBy('scholarship_application_id');
        foreach ($cycle->cases as $case) {
            $record = "$label, application {$case->scholarship_application_id}";
            $this->check($case->application->student_response_status === 'accepted' && $case->application->scholarship_id === $cycle->scholarship_id,
                "$record: needs an accepted agreement for this round.");
            $this->ordered($case->application->student_responded_at, $case->opened_at, "$record: opened before agreement acceptance.");
            $this->ordered($cycle->published_at, $case->opened_at, "$record: opened before check-in publication.");
            $appointment = $cycle->handoffTime($case);
            if ($appointment) {
                $this->ordered($case->opened_at, $appointment, "$record: appointment is before the case opened.");
                $this->ordered($this->day($cycle->plan?->starts_on), $this->day($appointment), "$record: appointment is before the plan starts.");
                $this->ordered($this->day($appointment), $this->day($cycle->plan?->ends_on), "$record: appointment is after the plan ends.");
                if ($case->attendance_status) $this->ordered($appointment, $this->now, "$record: attendance is recorded for a future appointment.");
                $venue = $case->appointment_location ?? $cycle->handoff_location;
                $this->book('check-in:'.$cycle->id.':'.$appointment->timestamp.':'.$venue, $label, $appointment, null, $cycle->created_by, $venue, collect([$case->applicant_id]));
            }
            $minimum = $appointment ?? $this->day($cycle->opens_at);
            foreach ($submissions->get($case->scholarship_application_id, collect()) as $submission) {
                if ($appointment) $this->check($case->attendance_status === 'attended', "$record: document results require recorded attendance.");
                $this->ordered($minimum, $submission->submitted_at, "$record: evidence predates the appointment or opening.");
                $this->ordered($submission->submitted_at, $this->now, "$record: evidence is dated in the future.");
                foreach ($submission->reviews as $review) {
                    $this->ordered($submission->submitted_at, $review->decided_at, "$record: document review predates evidence.");
                    $this->ordered($review->decided_at, $case->closed_at ?? $this->now, "$record: document review is after case closure or the audit time.");
                }
            }
            if ($case->closed_at || $case->status === 'closed') {
                $this->ordered($case->ready_to_close_at, $case->closed_at, "$record: case closed before it was ready.");
                $this->ordered($case->closed_at, $this->now, "$record: closure is in the future.");
            }
        }
    }

    private function release(RecipientBenefitRelease $release): void
    {
        $label = "Benefit release {$release->id} ({$release->title})";
        $program = $release->scholarship;
        $digital = app(RecipientBenefitPayoutService::class)->isDigital($release->release_method);
        $this->ordered($release->published_at, $release->release_at, "$label: scheduled before publication.");
        $this->ordered($release->published_at, $this->now, "$label: publication is in the future.");
        $this->ordered($this->day($program->support_starts_at), $this->day($release->release_at), "$label: scheduled before program support starts.");
        $this->ordered($this->day($release->release_at), $this->day($program->support_ends_at), "$label: scheduled after program support ends.");
        foreach ($release->records as $record) {
            $this->releaseRecord($record, $release, $digital, "$label, application {$record->scholarship_application_id}");
        }
        if (! $digital && $release->status !== 'cancelled') {
            $this->book('release:'.$release->id, $label, $release->release_at, null, $release->created_by, $release->location, $release->records->pluck('applicant_id'));
        }
    }

    private function releaseRecord(RecipientBenefitReleaseRecord $record, RecipientBenefitRelease $release, bool $digital, string $label): void
    {
        $application = $record->application;
        $this->check($application->student_response_status === 'accepted' && $application->scholarship_id === $release->scholarship_id,
            "$label: needs an accepted agreement for this round.");
        $this->ordered($application->student_responded_at, $release->published_at, "$label: published before agreement acceptance.");
        if ($record->status === 'released' || $record->released_at) {
            $this->ordered($release->release_at, $record->released_at, "$label: recorded as delivered before the scheduled release.");
            $this->ordered($record->released_at, $record->recorded_at, "$label: recorded before delivery occurred.");
            $this->ordered($record->recorded_at, $this->now, "$label: recorded delivery is in the future.");
        }
        if ($payout = $record->payoutDetails) {
            $this->check($digital && $payout->method === $release->release_method, "$label: payout method does not match the release.");
            $this->ordered($release->published_at, $payout->submitted_at, "$label: payout details predate publication.");
            $this->ordered($payout->submitted_at, $this->now, "$label: payout submission is in the future.");
            if ($payout->reviewed_at || $payout->review_status === 'approved') {
                $this->ordered($payout->submitted_at, $payout->reviewed_at, "$label: payout review predates submission.");
                $this->ordered($payout->reviewed_at, $this->now, "$label: payout review is in the future.");
            }
        }
        if ($record->transfer_sent_on || ($digital && $record->released_at)) {
            $this->check($digital && $payout?->review_status === 'approved', "$label: a recorded transfer requires approved payout details.");
            $this->check($record->released_at !== null, "$label: transfer is missing a recorded delivery.");
            $this->ordered($this->day($payout?->reviewed_at), $this->day($record->transfer_sent_on), "$label: transfer predates payout approval.");
            $this->ordered($this->day($record->transfer_sent_on), $this->day($record->recorded_at), "$label: transfer date is after the recorded delivery.");
        }
        if ($receipt = $record->receiptResponse) {
            $this->ordered($record->released_at, $receipt->responded_at, "$label: receipt response predates recorded delivery.");
            $this->ordered($receipt->responded_at, $this->now, "$label: receipt response is in the future.");
            if ($receipt->response_type === 'confirmed') {
                $this->ordered($this->day($record->transfer_sent_on ?? $record->released_at), $this->day($receipt->received_on), "$label: receipt date predates delivery.");
                $this->ordered($this->day($receipt->received_on), $this->day($receipt->responded_at), "$label: receipt date is after the response.");
            }
        }
    }

    private function book(string $key, string $label, ?CarbonInterface $start, ?CarbonInterface $end, ?int $staff, ?string $venue, Collection $applicants): void
    {
        if (! $start || ($end && ! $start->lt($end))) return;
        $resources = $applicants->filter()->map(fn ($id) => 'applicant '.$id)->all();
        if ($staff) $resources[] = 'staff member '.$staff;
        if (filled($venue)) $resources[] = 'venue '.Str::lower(Str::squish($venue));
        foreach ($resources as $resource) {
            // Shared sessions and group handoffs count as one booking for staff and venue.
            $this->bookings[$resource][$key] = ['label' => $label, 'start' => $start->timestamp,
                'end' => $end?->timestamp ?? $start->timestamp + self::DEMO_APPOINTMENT_MINUTES * 60];
        }
    }

    private function checkOverlaps(): void
    {
        foreach ($this->bookings as $resource => $bookings) {
            usort($bookings, fn (array $left, array $right) => $left['start'] <=> $right['start']);
            $latest = null;
            foreach ($bookings as $booking) {
                if ($latest && $booking['start'] < $latest['end']) {
                    $this->violations[] = "Double-booked $resource: {$latest['label']} overlaps {$booking['label']}.";
                }
                if (! $latest || $booking['end'] > $latest['end']) $latest = $booking;
            }
        }
    }

    private function day(?CarbonInterface $date): ?CarbonImmutable
    {
        if ($date === null) return null;

        return CarbonImmutable::parse($date->toDateString(), WorkflowDemoTimeline::TIMEZONE)->startOfDay();
    }

    private function ordered(?CarbonInterface $earlier, ?CarbonInterface $later, string $message, bool $strict = false): void
    {
        $this->check($earlier && $later && ($strict ? $earlier->lt($later) : $earlier->lte($later)), $message);
    }

    private function check(bool $valid, string $message): void
    {
        if (! $valid) $this->violations[] = $message;
    }
}
