<?php

use App\Models\RecipientMonitoringSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RecipientMonitoringChecklistTest;

class MonitoringProcessAuditTest extends RecipientMonitoringChecklistTest
{
    private function recipientFixture(): array
    {
        return (new ReflectionMethod(RecipientMonitoringChecklistTest::class, 'selectedRecipientWithPlan'))->invoke($this);
    }

    private function publishAppointment($provider, $program)
    {
        return $this->actingAs($provider)->postJson("/provider/scholarships/{$program->id}/monitoring-check-ins", [
            'title' => 'Semester document appointment',
            'handoff_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'),
            'handoff_location' => 'Learning Center',
        ])->assertCreated();
    }

    private function scheduleRelease($provider, $program, $application)
    {
        return $this->actingAs($provider)->postJson("/provider/scholarships/{$program->id}/benefit-releases", [
            'title' => 'Semester benefit release',
            'release_at' => now()->addDays(2)->setTime(11, 0)->format('Y-m-d H:i:s'),
            'benefit_description' => 'Learning materials',
            'release_method' => 'in_person',
            'location' => 'Learning Center',
            'requires_original_verification' => false,
            'recipient_ids' => [$application->id],
        ]);
    }

    public function test_audit_reproduces_scheduled_release_proceeding_after_new_required_check_in(): void
    {
        [$provider, , $program, $application] = $this->recipientFixture();
        $record = $this->scheduleRelease($provider, $program, $application)->assertCreated()->json('release.records.0.id');
        $this->publishAppointment($provider, $program);
        $this->scheduleRelease($provider, $program, $application)->assertUnprocessable()->assertJsonValidationErrors('recipient_ids');
        $this->travel(3)->days();
        $this->actingAs($provider)->postJson("/provider/benefit-release-records/{$record}/result", [
            'status' => 'released',
            'notes' => 'Recorded delivery by the provider.',
        ])->assertOk()->assertJsonPath('release.records.0.status', 'released');
    }

    public function test_audit_reproduces_case_level_excusal_not_unblocking_release(): void
    {
        [$provider, , $program, $application] = $this->recipientFixture();
        $case = $this->publishAppointment($provider, $program)->json('cycle.recipients.0.monitoring_case.id');
        $this->actingAs($provider)->patchJson("/provider/monitoring-cases/{$case}/waive", [
            'outcome' => 'excused',
            'reason' => 'Approved leave for the appointment.',
        ])->assertOk()->assertJsonPath('cycle.status', 'closed');
        $response = $this->scheduleRelease($provider, $program, $application)->assertUnprocessable();
        $this->assertStringContainsString('is not confirmed', $response->json('errors.recipient_ids.0'));
    }

    public function test_audit_reproduces_applicant_directory_labeling_an_incomplete_case_as_completed(): void
    {
        [$provider, $applicant, $program, $application] = $this->recipientFixture();
        $case = $this->publishAppointment($provider, $program)->json('cycle.recipients.0.monitoring_case.id');
        $this->travel(12)->days();
        $this->actingAs($provider)->patchJson("/provider/monitoring-cases/{$case}/close", [
            'outcome' => 'incomplete',
            'reason' => 'Recipient missed the handoff and no records were presented.',
        ])->assertOk()->assertJsonPath('cycle.recipients.0.monitoring_case.outcome', 'incomplete');
        $this->actingAs($applicant)->getJson("/dashboard/monitoring/{$application->id}/data?view=check-ins")
            ->assertOk()
            ->assertJsonPath('detail.content.completed.0.monitoring_case.outcome', 'incomplete')
            ->assertJsonPath('detail.content.completed.0.state_label', 'Completed');
    }

    public function test_audit_reproduces_overwriting_accepted_online_evidence_without_correction_request(): void
    {
        [$provider, $applicant, $program, $application] = $this->recipientFixture();
        $cycle = (new ReflectionMethod(RecipientMonitoringChecklistTest::class, 'existingUploadCheckIn'))->invoke(
            $this,
            $provider,
            $program,
            ['title' => 'Legacy online check-in', 'opens_at' => now()->toDateString(), 'due_at' => now()->addWeek()->toDateString()],
        );
        $requirement = $cycle->json('cycle.requirements.1.id');
        $url = "/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement}/submission";
        $submit = fn (string $filename) => $this->actingAs($applicant)->postJson($url, [
            'supporting_record' => UploadedFile::fake()->create($filename, 10, 'application/pdf'),
            'terms_accepted' => true,
        ]);
        $submit('accepted-original.pdf')->assertSuccessful();
        $submission = RecipientMonitoringSubmission::where('recipient_monitoring_cycle_requirement_id', $requirement)->firstOrFail();
        $oldPath = $submission->path;
        $this->actingAs($provider)->patchJson("/provider/monitoring-submissions/{$submission->id}/review", [
            'decision' => 'met',
        ])->assertOk();
        $this->assertSame('met', $submission->fresh()->review_status);
        $submit('unsolicited-replacement.pdf')->assertSuccessful();
        $this->assertSame('pending', $submission->fresh()->review_status);
        Storage::disk('local')->assertMissing($oldPath);
    }
}
