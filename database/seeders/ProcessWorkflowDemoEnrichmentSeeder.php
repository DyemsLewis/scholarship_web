<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\ApplicationStageSession;
use App\Models\PortalNotification;
use App\Models\RecipientMonitoringPlan;
use App\Models\RecipientSupportDecision;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use App\Services\RecipientBenefitEntitlementService;
use App\Services\RecipientBenefitFollowUpService;
use App\Services\RecipientMonitoringCaseService;
use App\Services\RecipientSupportSettlementService;
use App\Services\SelectionEvaluationService;
use Carbon\Carbon;
use Database\Seeders\Support\WorkflowDemoTimeline;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Additive fixtures only: existing decisions, profiles and applications are never rewritten. */
class ProcessWorkflowDemoEnrichmentSeeder extends FreshWorkflowDemoSeeder
{
    protected bool $seedStageScores = false;
    public const MARKER = 'workflow_process_enrichment_20261113';
    public const PREFIX = 'cohort.process.';
    private array $staggeredSessions = [];
    public const SCENARIOS = [
        'dss.first' => ['Nerali', 'Salindaw', 'Review the highest evaluated candidate; no final decision recorded.'],
        'dss.second' => ['Veylan', 'Marahuyo', 'Review the second evaluated candidate; two award slots are available.'],
        'dss.third' => ['Amirel', 'Talahir', 'Review the remaining candidate; the recommendation is not an official waitlist decision.'],
        'dss.score' => ['Sorevi', 'Lindawen', 'Record the missing interview evaluation before the final comparison.'],
        'support.appointment' => ['Iralen', 'Dumaloy', 'Attend the upcoming original-document appointment.'],
        'support.review' => ['Nivara', 'Kalumay', 'Provider checks original grades and enrollment after attendance.'],
        'support.close' => ['Elvian', 'Silaraw', 'All required records are met; provider can close the check-in.'],
        'support.decision' => ['Calira', 'Maranaw', 'Check-in closed and benefits received; provider can renew or complete support.'],
        'support.history' => ['Revian', 'Talihim', 'View the successful renewal decision and its acknowledgment action.'],
        'award.receipt' => ['Avelis', 'Dalisaw', 'Confirm receipt of the recorded one-time award.'],
        'award.complete' => ['Lenavi', 'Salumay', 'Receipt confirmed; provider can complete the one-time scholarship.'],
        'award.followup' => ['Process', 'Learner 12', 'Attend the upcoming collection appointment; the tester records its outcome.'],
    ];

    public function run(): void
    {
        if (app()->environment('production') && getenv('WORKFLOW_PROCESS_DATA_CONFIRM') !== self::MARKER) {
            throw new RuntimeException('Production enrichment requires explicit confirmation and a verified backup.');
        }
        if (ActivityLog::where('action', self::MARKER)->exists()) {
            if (User::whereIn('username', $this->usernames())->count() !== count(self::SCENARIOS)) {
                throw new RuntimeException('The enrichment marker exists but its account set is incomplete. No repairs were guessed.');
            }
            return;
        }
        if (User::whereIn('username', $this->usernames())->exists()) {
            throw new RuntimeException('Reserved process accounts already exist without a completion marker.');
        }
        $this->timeline = new WorkflowDemoTimeline;
        foreach (['nuvareya', 'nuvareya.reviewer', 'nuvareya.selection', 'nuvareya.decisions', 'nuvareya.recipients', 'nuvareya.monitoring', 'nuvareya.releases', 'novacore.reviews'] as $username) {
            $this->staff[$username] = User::where('username', $username)->sole();
            if (! $this->staff[$username]->isActive()) throw new RuntimeException('Required demo staff is inactive: '.$username);
        }
        if ($this->staff['nuvareya']->providerProfile?->verification_status !== 'approved') {
            throw new RuntimeException('The organization must already be approved. This patch does not bypass verification.');
        }
        $mailer = config('mail.default');
        config()->set('mail.default', 'array');
        try {
            DB::transaction(function (): void {
                $provider = $this->staff['nuvareya'];
                $this->programs['decision'] = $this->program($provider, 'decision', 'Nuvareya Scholar Selection Round', 'TSE', 1, 'published');
                $this->programs['decision']->update(['slots_available' => 2]);
                $scoring = $this->program($provider, 'decision', 'Nuvareya Assessment Completion Round', 'TAC', 2, 'published');
                $this->programs['support'] = $this->program($provider, 'support', 'Nuvareya Learning Continuity Grant', 'TLC', 3, 'published');
                $this->programs['one_time'] = $this->program($provider, 'one_time', 'Nuvareya Study Essentials Award', 'TSA', 0, 'published');
                $programIds = [...array_map(fn ($p) => $p->id, $this->programs), $scoring->id];
                foreach (User::where('parent_account_id', $provider->id)->get() as $staff) {
                    if ($staff->assigned_program_ids !== null) {
                        $staff->update(['assigned_program_ids' => array_values(array_unique([...$staff->assigned_program_ids, ...$programIds]))]);
                    }
                }
                foreach ($this->staff as $staff) $staff->refresh();
                $number = 500;
                foreach (self::SCENARIOS as $key => [$first, $last, $next]) {
                    $first = 'Process';
                    $last = 'Learner '.sprintf('%02d', $number - 499);
                    $learner = $this->applicant(['username' => self::PREFIX.$key, 'scenario' => 'process', 'first_name' => $first,
                        'last_name' => $last, 'next_action' => $next, 'actor' => 'the appropriate workspace'], ++$number, $this->staff['novacore.reviews']);
                    PortalNotification::where('user_id', $learner->id)->delete();
                    $round = str_starts_with($key, 'dss.') ? 'decision' : (str_starts_with($key, 'award.') ? 'one_time' : 'support');
                    $selectionProgram = $this->programs['decision'];
                    if ($key === 'dss.score') $this->programs['decision'] = $scoring;
                    // Stop at final review so all scored prerequisites can be saved before any award.
                    $application = $this->application($learner, $round, 'decision');
                    $this->programs['decision'] = $selectionProgram;
                    foreach ($round === 'one_time' ? [] : ['exam', 'interview'] as $stage) {
                        if ($key === 'dss.score' && $stage === 'interview') continue;
                        $score = match ($key) { 'dss.first' => 96, 'dss.second' => 90, 'dss.third' => 82, default => 93 };
                        $progress = $application->stageProgresses()->where('stage_key', $stage)->sole();
                        Carbon::withTestNow($progress->completed_at->copy()->addMinute(), fn () => app(SelectionEvaluationService::class)->record(
                            $application->fresh(), $this->staff['nuvareya.selection'], $stage, $score, 100,
                            (int) $progress->evaluation_revision, 0, 'Assessment recorded from the completed session.',
                        ));
                    }
                    if (! str_starts_with($key, 'dss.')) $application = $this->acceptAward($application, $round);
                    $this->applications[$key] = $application->fresh();
                    PortalNotification::create(['user_id' => $learner->id, 'type' => 'process_next_action', 'title' => 'Your next step',
                        'message' => $next, 'action_url' => str_starts_with($key, 'dss.') ? '/dashboard/applications' : '/dashboard/monitoring',
                        'deduplication_key' => self::MARKER.'-'.$key]);
                }
                $this->prepareSupport();
                foreach (['award.receipt', 'award.complete', 'award.followup'] as $key) {
                    $this->release($this->applications[$key], $key !== 'award.followup');
                }
                $this->confirmReceipt($this->applications['award.complete']);
                $record = $this->applications['award.followup']->benefitReleaseRecords()->sole();
                $record->release->update(['release_at' => $this->timeline->at(5, 10), 'instructions' => 'Bring your school ID to collect the study-start award. Confirm receipt only after receiving it.']);
                ActivityLog::record($this->staff['novacore.reviews'], self::MARKER, 'Prepared additional selection and recipient handoffs without changing previous outcomes.',
                    metadata: ['usernames' => $this->usernames(), 'program_ids' => $programIds]);
            });
        } finally {
            config()->set('mail.default', $mailer);
        }
    }

    private function acceptAward(ScholarshipApplication $application, string $round): ScholarshipApplication
    {
        [, , , $decision, $accepted] = $this->timeline->progression($round);
        $application = Carbon::withTestNow($this->timeline->at($decision), fn () => app(ApplicationWorkflowService::class)->recordFinalOutcome(
            $application->fresh(), 'selected', $this->staff['nuvareya.decisions'], 'Selection completed before the recipient support period.', 'approved_for_award',
            ['selection_rationale' => 'All required stages and recorded assessments meet the program requirements.'],
        ));
        $at = $this->timeline->at($accepted);
        $application->update(['awarded_amount' => $application->scholarship->award_amount, 'student_response_status' => 'accepted',
            'student_responded_at' => $at, 'student_response_terms_accepted_at' => $at,
            'student_response_terms_version' => $application->provider_contract_terms_version,
            'provider_contract_terms_accepted_at' => $at, 'provider_contract_acceptance_ip' => '127.0.0.1']);
        Carbon::withTestNow($at, fn () => app(RecipientBenefitEntitlementService::class)->activateForAcceptedApplication($application->fresh()));
        return $application->fresh();
    }

    private function prepareSupport(): void
    {
        $actor = $this->staff['nuvareya.monitoring'];
        $program = $this->programs['support'];
        $plan = RecipientMonitoringPlan::create(['scholarship_id' => $program->id, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            'frequency' => 'semester', 'starts_on' => $program->support_starts_at, 'ends_on' => $program->support_ends_at,
            'grace_period_days' => 7, 'allow_exception_requests' => true, 'status' => 'active', 'version' => 1,
            'activated_at' => $this->timeline->at(-28), 'instructions' => 'Bring original grades and enrollment proof. The provider checks them at the appointment; do not upload substitutes.']);
        foreach (['academic_progress' => 'Current academic record', 'enrollment' => 'Current enrollment certificate'] as $type => $title) {
            $plan->requirements()->create(['type' => $type, 'title' => $title, 'evidence_description' => $title, 'required' => true,
                'requires_file' => false, 'requires_original_verification' => true, 'minimum_grade' => $type === 'academic_progress' ? 85 : null,
                'grading_scale' => $type === 'academic_progress' ? 'percentage' : null, 'sort_order' => $type === 'academic_progress' ? 1 : 2, 'active' => true]);
        }
        // The base builder uses these names to prepare the attended and reviewed prerequisite states.
        foreach (['appointment', 'review', 'close'] as $suffix) $this->applications['monitoring.'.$suffix] = $this->applications['support.'.$suffix];
        $this->checkIn($plan, $actor, 5, ['monitoring.appointment']);
        $this->checkIn($plan, $actor, -3, ['monitoring.review', 'monitoring.close', 'support.decision']);
        $this->applications['monitoring.close'] = $this->applications['support.history'];
        $this->checkIn($plan, $actor, -5, ['monitoring.close']);
        $history = $this->applications['support.history'];
        Carbon::withTestNow($this->timeline->at(-5, 19), fn () => app(RecipientMonitoringCaseService::class)->close(
            $history->monitoringCases()->sole(), $actor, 'completed', 'All original documents were checked.',
        ));
        foreach (['support.decision', 'support.history'] as $key) {
            $application = $this->applications[$key];
            // Both promised benefits are delivered, not only the cash allowance.
            foreach (['in_person', 'other'] as $method) {
                $this->release($application, true, $method);
                $record = $application->benefitReleaseRecords()->latest('id')->firstOrFail();
                if ($method === 'other') {
                    $at = $this->timeline->at(-1, $key === 'support.history' ? 20 : 19);
                    $record->release->update(['release_at' => $at->subHour()]);
                    $record->update(['released_at' => $at, 'recorded_at' => $at]);
                }
                $this->confirmReceipt($application, $record->id);
            }
        }
        RecipientSupportDecision::create(['scholarship_application_id' => $history->id, 'applicant_id' => $history->applicant_id,
            'decision' => 'renewed', 'reason_category' => 'requirements_met', 'effective_on' => $this->timeline->at(-1)->toDateString(),
            'support_ends_on' => $program->support_ends_at, 'next_review_on' => $this->timeline->at(60)->toDateString(),
            'reason' => 'Original records meet the required academic and enrollment conditions.',
            'next_period_terms' => 'Remain enrolled and attend the next scheduled document review.',
            'decided_by' => $this->staff['nuvareya.recipients']->id, 'decided_at' => $this->timeline->at(-1, 22),
            'closeout_snapshot' => app(RecipientSupportSettlementService::class)->closeoutSnapshot($history->fresh(), true)]);
    }

    private function confirmReceipt(ScholarshipApplication $application, ?int $recordId = null): void
    {
        $record = $recordId ? $application->benefitReleaseRecords()->findOrFail($recordId) : $application->benefitReleaseRecords()->sole();
        $response = $record->receiptResponse()->create(['scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id,
            'response_type' => 'confirmed', 'status' => 'confirmed', 'received_on' => $record->released_at->toDateString(),
            'recipient_note' => 'I received the stated benefit.', 'responded_at' => $record->released_at->copy()->addHour()]);
        app(RecipientBenefitEntitlementService::class)->syncRecipientResponse($response);
    }

    protected function session(ScholarshipApplication $application, string $round, string $stage, string $scenario): ApplicationStageSession
    {
        $session = parent::session($application, $round, $stage, $scenario);
        if (! isset($this->staggeredSessions[$session->id])) {
            $hours = $application->scholarship->title === 'Nuvareya Assessment Completion Round' ? 4 : 2;
            $session->update(['starts_at' => $session->starts_at->copy()->addHours($hours), 'ends_at' => $session->ends_at->copy()->addHours($hours),
                'completed_at' => $session->completed_at->copy()->addHours($hours)]);
            foreach ($session->evidence as $proof) $proof->update(['uploaded_at' => $proof->uploaded_at->copy()->addHours($hours)]);
            $this->staggeredSessions[$session->id] = true;
        }
        foreach ($session->assignments()->with('attendance')->get() as $assignment) {
            $assignment->attendance->update(['recorded_at' => $session->ends_at->copy()->addMinutes(15)]);
        }
        return $session->fresh();
    }

    protected function checkIn(RecipientMonitoringPlan $plan, User $actor, int $day, array $scenarios): void
    {
        parent::checkIn($plan, $actor, $day, $scenarios);
        $cycle = $plan->cycles()->reorder('id', 'desc')->firstOrFail();
        $cycle->update(['handoff_at' => $cycle->handoff_at->copy()->addHours(3),
            'handoff_location' => $plan->scholarship->location_name.', Antipolo City, Rizal']);
        foreach ($cycle->cases as $case) {
            $changes = [];
            foreach (['submitted_at', 'review_started_at', 'ready_to_close_at', 'closed_at'] as $field) {
                if ($case->{$field}) $changes[$field] = $case->{$field}->copy()->addHours(3);
            }
            if ($changes) $case->update($changes);
        }
        foreach ($cycle->submissions as $submission) {
            $submission->update(['submitted_at' => $submission->submitted_at->copy()->addHours(3), 'reviewed_at' => $submission->reviewed_at->copy()->addHours(3)]);
            foreach ($submission->reviews as $review) $review->update(['decided_at' => $review->decided_at->copy()->addHours(3)]);
        }
    }

    protected function release(ScholarshipApplication $application, bool $released, string $method = 'in_person', ?string $payoutStatus = null): void
    {
        parent::release($application, $released, $method, $payoutStatus);
        if ($method !== 'in_person') return;
        $record = $application->benefitReleaseRecords()->latest('id')->firstOrFail();
        $record->release->update(['release_at' => $record->release->release_at->copy()->addMinutes(330)]);
        if ($released) $record->update(['released_at' => $record->released_at->copy()->addMinutes(330), 'recorded_at' => $record->recorded_at->copy()->addMinutes(330)]);
    }

    public function usernames(): array
    {
        return array_map(fn ($key) => self::PREFIX.$key, array_keys(self::SCENARIOS));
    }
}
