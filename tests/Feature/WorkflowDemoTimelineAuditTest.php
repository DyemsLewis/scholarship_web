<?php

namespace Tests\Feature;

use App\Models\RecipientMonitoringCase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\FreshWorkflowDemoSeeder;
use Database\Seeders\Support\WorkflowDemoCatalog as Catalog;
use Database\Seeders\Support\WorkflowDemoTimelineAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkflowDemoTimelineAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_audit_is_read_only_and_reports_timeline_problems_before_a_demo_switch(): void
    {
        Storage::fake('local');
        $asOf = CarbonImmutable::parse('2026-11-10 01:00:00', 'Asia/Manila');
        $this->travelTo($asOf);
        $this->seed(FreshWorkflowDemoSeeder::class);

        DB::enableQueryLog();
        try {
            $this->artisan('demo:audit')->expectsOutput('Demo audit passed (Asia/Manila). No data was changed.')->assertSuccessful();
            $queries = collect(DB::getQueryLog())->pluck('query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertNotEmpty($queries);
        $this->assertTrue($queries->every(fn (string $query) => str_starts_with(strtolower(ltrim($query)), 'select')));

        $this->travelTo($asOf->addDays(4));
        $this->artisan('demo:audit')
            ->expectsOutput('The acknowledgement example is no longer upcoming: exam.confirm. Rebuild the candidate with a fresh timeline.')
            ->assertFailed();
        $this->travelTo($asOf);

        $exam = $this->application('exam.confirm')->stageAssignments()->with('session')->sole();
        $open = Scholarship::acceptingApplications()->firstOrFail();
        $sessionDuringIntake = $exam->session->replicate()->fill([
            'scholarship_id' => $open->id,
            'title' => 'Last intake day session',
            'starts_at' => $open->deadline->copy()->setTime(23, 0),
            'ends_at' => $open->deadline->copy()->setTime(23, 30),
        ]);
        $sessionDuringIntake->save();

        $duplicate = $exam->session->replicate()->fill([
            'title' => 'Conflicting exam booking', 'starts_at' => $exam->session->starts_at->copy()->addMinutes(15),
        ]);
        $duplicate->save();
        $duplicate->assignments()->save($exam->replicate());
        $expectedResults = $exam->session->scholarship->expected_results_at->copy()->addDay();
        $lateSession = $exam->session->replicate()->fill([
            'title' => 'Exam after expected results', 'starts_at' => $expectedResults->copy()->setTime(9, 0),
            'ends_at' => $expectedResults->copy()->setTime(10, 30),
        ]);
        $lateSession->save();

        $interview = $this->application('interview.confirm');
        $interview->update(['submitted_at' => $interview->scholarship->deadline->copy()->addDay()]);
        $pastExam = $interview->stageAssignments()->whereHas('session', fn ($query) => $query->where('stage_key', 'exam'))->sole()->session;
        $interview->stageProgresses()->where('stage_key', 'exam')->update(['decided_at' => $pastExam->starts_at]);
        $interviewSession = $interview->stageAssignments()->whereHas('session', fn ($query) => $query->where('stage_key', 'interview'))->sole()->session;
        $interviewSession->update(['starts_at' => $pastExam->starts_at, 'ends_at' => $pastExam->ends_at]);

        $checkIn = RecipientMonitoringCase::where('scholarship_application_id', $this->application('monitoring.appointment')->id)->sole();
        $checkIn->update(['attendance_status' => 'attended']);
        $checkIn->cycle->update(['handoff_at' => $checkIn->cycle->plan->ends_on->copy()->addDay()->setTime(14, 0)]);

        $receipt = $this->application('release.confirm')->benefitReleaseRecords()->sole();
        $receipt->release->update(['published_at' => $receipt->application->student_responded_at->copy()->subDay()]);
        $receipt->receiptResponse()->create([
            'applicant_id' => $receipt->applicant_id, 'scholarship_application_id' => $receipt->scholarship_application_id,
            'response_type' => 'confirmed', 'received_on' => $receipt->released_at->copy()->subDay()->toDateString(),
            'status' => 'confirmed', 'responded_at' => $receipt->released_at->copy()->subDay(),
        ]);

        $transfer = $this->application('payout.transfer')->benefitReleaseRecords()->sole();
        $transfer->update([
            'status' => 'released', 'released_at' => $transfer->release->release_at, 'recorded_at' => $transfer->release->release_at,
            'transfer_sent_on' => $transfer->payoutDetails->reviewed_at->copy()->subDay()->toDateString(),
        ]);

        $messages = implode("\n", WorkflowDemoTimelineAudit::inspect()['violations']);
        foreach ([
            'open or upcoming intake must not have published selection sessions',
            "Session {$sessionDuringIntake->id} (Last intake day session): scheduled before intake closed",
            "session {$lateSession->id} ends after its stage deadline",
            'Double-booked applicant '.$exam->application->applicant_id,
            'Double-booked staff member '.$exam->session->created_by,
            'Double-booked venue '.strtolower($exam->session->venue),
            'submitted after intake closed', 'exam result predates session completion', 'exam sessions overlap or follow the interview phase',
            'appointment is after the plan ends', 'attendance is recorded for a future appointment',
            'published before agreement acceptance', 'receipt response predates recorded delivery', 'transfer predates payout approval',
        ] as $message) {
            $this->assertStringContainsString($message, $messages);
        }
        $this->artisan('demo:audit')->assertFailed();
    }

    public function test_the_audit_refuses_an_unrelated_database_without_changing_it(): void
    {
        $existing = User::factory()->create();

        $this->artisan('demo:audit')->expectsOutput('This database does not contain the new demo catalog.')->assertFailed();

        $this->assertSame($existing->email, User::query()->sole()->email);
    }

    private function application(string $scenario): ScholarshipApplication
    {
        return ScholarshipApplication::query()->where('applicant_id', User::query()->where('username', Catalog::APPLICANT.'.'.$scenario)->sole()->id)->sole();
    }
}
