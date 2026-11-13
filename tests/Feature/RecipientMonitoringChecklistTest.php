<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\RecipientMonitoringAdjustmentRequest;
use App\Models\RecipientMonitoringCase;
use App\Models\RecipientMonitoringCaseEvent;
use App\Models\RecipientMonitoringCycle;
use App\Models\RecipientMonitoringCycleRequirement;
use App\Models\RecipientMonitoringIntervention;
use App\Models\RecipientMonitoringPlan;
use App\Models\RecipientMonitoringSubmission;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecipientMonitoringChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config()->set('services.academic_ocr.enabled', true);
        config()->set('services.academic_ocr.endpoint', 'https://api.ocr.space/parse/image');
        config()->set('services.academic_ocr.key', 'test-key');
        config()->set('services.academic_ocr.max_file_size_kb', 1024);
    }

    public function test_a_scheduled_release_is_rechecked_when_the_provider_records_delivery(): void
    {
        [$provider, , $scholarship, $application] = $this->selectedRecipientWithPlan();
        $release = $this->scheduleMonitoringBenefit($provider, $scholarship, $application)
            ->assertCreated()->json('release');
        $cycleId = $this->publishMonitoringAppointment($provider, $scholarship, $application)->json('cycle.id');
        $this->travel(3)->days();

        $this->actingAs($provider)
            ->getJson("/provider/workspaces/releases/distributions/{$release['id']}/data")
            ->assertOk()
            ->assertJsonPath('release.records.0.release_readiness.eligible', false)
            ->assertJsonPath('release.records.0.release_readiness.blocked_cycle_id', $cycleId)
            ->assertJsonPath('release.records.0.release_readiness.hold_summary', function (string $summary): bool {
                return substr_count($summary, 'Semester document appointment') === 1
                    && str_contains($summary, '2 other items need attention.');
            });

        $this->recordMonitoringBenefit($provider, $release['records'][0]['id'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('recipient_benefit_release_records', [
            'id' => $release['records'][0]['id'],
            'status' => 'scheduled',
        ]);
    }

    public function test_an_excused_check_in_satisfies_the_release_gate_until_reopened(): void
    {
        [$provider, , $scholarship, $application] = $this->selectedRecipientWithPlan();
        $caseId = $this->publishMonitoringAppointment($provider, $scholarship, $application)
            ->json('cycle.recipients.0.monitoring_case.id');
        $waive = fn () => $this->actingAs($provider)->patchJson("/provider/monitoring-cases/{$caseId}/waive", [
            'outcome' => 'excused',
            'reason' => 'Approved leave for the appointment.',
        ])->assertOk()->assertJsonPath('cycle.recipients.0.monitoring_case.status', 'waived');
        $waive();

        $release = $this->scheduleMonitoringBenefit($provider, $scholarship, $application)
            ->assertCreated()->json('release');
        $detailUrl = "/provider/workspaces/releases/distributions/{$release['id']}/data";
        $this->getJson($detailUrl)->assertOk()
            ->assertJsonPath('release.records.0.release_readiness.eligible', true);

        $this->patchJson("/provider/monitoring-cases/{$caseId}/reopen", [
            'reason' => 'The provider needs to recheck the approved leave.',
        ])->assertOk();
        $this->travel(3)->days();
        $this->recordMonitoringBenefit($provider, $release['records'][0]['id'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $waive();
        $this->recordMonitoringBenefit($provider, $release['records'][0]['id'])
            ->assertOk()->assertJsonPath('release.records.0.status', 'released');
    }

    public function test_reviewed_online_evidence_can_only_be_replaced_after_a_correction_request(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $requirementId = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'Existing online check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addWeek()->toDateString(),
        ])->json('cycle.requirements.1.id');
        $upload = fn (string $name) => $this->actingAs($applicant)->postJson(
            "/dashboard/applications/{$application->id}/monitoring-requirements/{$requirementId}/submission",
            ['supporting_record' => UploadedFile::fake()->create($name, 10, 'application/pdf'), 'terms_accepted' => true],
        );
        $upload('original.pdf')->assertCreated();
        $submission = RecipientMonitoringSubmission::where('recipient_monitoring_cycle_requirement_id', $requirementId)
            ->firstOrFail();
        $originalPath = $submission->path;
        $reviewUrl = "/provider/monitoring-submissions/{$submission->id}/review";
        $this->actingAs($provider)->patchJson($reviewUrl, ['decision' => 'met'])->assertOk();
        $review = $submission->reviews()->firstOrFail();

        $upload('unauthorized.pdf')->assertUnprocessable()->assertJsonValidationErrors('supporting_record');
        $this->assertSame('met', $submission->fresh()->review_status);
        $this->assertSame($originalPath, $submission->fresh()->path);

        $this->actingAs($provider)->patchJson($reviewUrl, [
            'decision' => 'needs_correction',
            'notes' => 'Please provide the updated enrollment certificate.',
        ])->assertOk();
        $upload('replacement.pdf')->assertOk();
        $this->assertSame('pending', $submission->fresh()->review_status);
        $this->assertNotSame($originalPath, $submission->fresh()->path);
        $this->assertSame($originalPath, $review->fresh()->evidence_snapshot['path']);
        Storage::disk('local')->assertExists($originalPath);
        $this->actingAs($provider)->get(route('provider.monitoring-submissions.view', [
            'submission' => $submission->id,
            'review' => $review->id,
        ]))->assertOk();
    }

    public function test_published_roster_excludes_uninvited_and_later_accepted_recipients(): void
    {
        [$provider, , $scholarship, $invited] = $this->selectedRecipientWithPlan();
        $notInvited = $this->createAppointmentRecipient($scholarship);
        $later = $this->createAppointmentRecipient($scholarship, 'pending');
        $url = "/provider/scholarships/{$scholarship->id}/monitoring-check-ins";
        $payload = [
            'title' => 'Enrollment document handoff',
            'handoff_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'handoff_location' => 'Learning Center',
        ];
        $this->actingAs($provider)->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('recipient_ids');
        $this->postJson($url, $payload + ['recipient_ids' => [$later->id]])->assertUnprocessable()->assertJsonValidationErrors('recipient_ids');
        $checkIn = $this->postJson($url, $payload + ['recipient_ids' => [$invited->id]])
            ->assertCreated()->assertJsonCount(1, 'cycle.recipients')->json('cycle');
        $later->update(['student_response_status' => 'accepted']);
        app(\App\Services\RecipientMonitoringCaseService::class)->activateForAcceptedApplication($later);
        $this->getJson("/provider/workspaces/monitoring/check-ins/{$checkIn['id']}/data")
            ->assertOk()->assertJsonCount(1, 'cycle.recipients');

        foreach ([$notInvited, $later] as $recipient) {
            $this->actingAs($recipient->applicant)->getJson("/dashboard/applications/{$recipient->id}/data")
                ->assertOk()->assertJsonCount(0, 'application.recipient_monitoring.check_ins');
            $this->getJson('/dashboard/overview-data')
                ->assertOk()->assertJsonMissing(['title' => 'Enrollment document handoff']);
            $this->assertDatabaseMissing('recipient_monitoring_cases', ['scholarship_application_id' => $recipient->id]);
            $this->assertDatabaseMissing('portal_notifications', ['user_id' => $recipient->applicant_id, 'type' => 'recipient_monitoring_request']);
        }
        $this->scheduleMonitoringBenefit($provider, $scholarship, $notInvited)->assertCreated();
    }

    public function test_only_the_missed_recipient_is_rescheduled_and_a_due_release_remains_on_hold(): void
    {
        [$provider, $applicant, $scholarship, $first] = $this->selectedRecipientWithPlan();
        $second = $this->createAppointmentRecipient($scholarship);
        $release = $this->scheduleMonitoringBenefit($provider, $scholarship, $first)->assertCreated()->json('release');
        $time = now()->addDay()->setTime(9, 0);
        $cycle = $this->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
            'title' => 'Enrollment document handoff',
            'handoff_at' => $time->format('Y-m-d\TH:i'),
            'handoff_location' => 'Learning Center',
            'recipient_ids' => [$first->id, $second->id],
        ])->assertCreated()->json('cycle');
        $caseId = $cycle['recipients'][0]['monitoring_case']['id'];
        $url = "/provider/monitoring-cases/{$caseId}/appointment";
        $this->patchJson($url, ['action' => 'attendance', 'attendance_status' => 'no_show'])
            ->assertUnprocessable()->assertJsonValidationErrors('attendance_status');

        $this->travelTo($time->copy()->addHour());
        $this->patchJson($url, ['action' => 'attendance', 'attendance_status' => 'no_show'])
            ->assertOk()->assertJsonPath('cycle.recipients.0.monitoring_case.next_action.key', 'resolve_missed_appointment');
        $this->actingAs($applicant)->getJson("/dashboard/monitoring/{$first->id}/data")
            ->assertOk()
            ->assertJsonPath('detail.decision.title', 'Missed appointment')
            ->assertJsonPath('detail.content.next_check_in.state_label', 'Missed appointment');
        $this->getJson("/dashboard/monitoring/{$first->id}/data?view=check-ins")
            ->assertOk()
            ->assertJsonPath('detail.content.directory.next_appointment', null)
            ->assertJsonPath('detail.content.directory.active.0.next_actor_label', 'Scholarship provider');
        $replacement = now()->addWeek()->setTime(14, 0);
        $update = [
            'action' => 'reschedule',
            'appointment_at' => $replacement->format('Y-m-d\TH:i'),
            'appointment_location' => 'Main office',
            'reason' => 'The recipient could not attend the original appointment.',
        ];
        $this->actingAs(User::factory()->create(['role' => 'provider', 'account_status' => 'active']))
            ->patchJson($url, $update)->assertForbidden();
        $this->actingAs($provider)->patchJson($url, $update)
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.handoff.location', 'Main office')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.attendance_status', null)
            ->assertJsonPath('cycle.recipients.0.monitoring_case.next_action.key', 'attend_handoff')
            ->assertJsonPath('cycle.recipients.1.handoff.location', 'Learning Center');
        $this->assertDatabaseHas('recipient_monitoring_case_events', ['recipient_monitoring_case_id' => $caseId, 'action' => 'appointment_rescheduled']);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id, 'title' => 'In-person check-in rescheduled']);
        $this->assertDatabaseMissing('portal_notifications', ['user_id' => $second->applicant_id, 'title' => 'In-person check-in rescheduled']);

        $cycleId = $cycle['id'];
        $this->actingAs($applicant)->getJson("/dashboard/monitoring/{$first->id}/data?view=check-ins&record=check-in-{$cycleId}")
            ->assertOk()
            ->assertJsonPath('detail.content.selected.handoff.location', 'Main office')
            ->assertJsonPath('detail.content.selected.handoff.rescheduled', true)
            ->assertJsonPath('detail.content.selected.state_label', 'Rescheduled')
            ->assertJsonPath('detail.content.directory.next_appointment.handoff.location', 'Main office')
            ->assertJsonPath('detail.content.directory.next_appointment.state_label', 'Rescheduled')
            ->assertJsonPath('detail.content.selected.due_at', $replacement->toDateString());
        $this->getJson('/dashboard/overview-data')->assertOk()
            ->assertJsonFragment(['detail' => 'Rescheduled check-in', 'date_label' => $replacement->format('M d, Y g:i A').' (Philippine time)']);
        $this->travel(2)->days();
        $this->recordMonitoringBenefit($provider, $release['records'][0]['id'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    private function createAppointmentRecipient(Scholarship $scholarship, string $response = 'accepted'): ScholarshipApplication
    {
        return ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'awarded',
            'final_outcome' => 'selected',
            'student_response_status' => $response,
        ]);
    }

    private function publishMonitoringAppointment(User $provider, Scholarship $scholarship, ScholarshipApplication $application): TestResponse
    {
        return $this->actingAs($provider)->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
            'title' => 'Semester document appointment',
            'handoff_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'),
            'handoff_location' => 'Learning Center',
            'recipient_ids' => [$application->id],
        ])->assertCreated();
    }

    private function scheduleMonitoringBenefit(User $provider, Scholarship $scholarship, ScholarshipApplication $application): TestResponse
    {
        return $this->actingAs($provider)->postJson("/provider/scholarships/{$scholarship->id}/benefit-releases", [
            'title' => 'Semester benefit release',
            'release_at' => now()->addDays(2)->setTime(11, 0)->toDateTimeString(),
            'benefit_description' => 'Learning materials',
            'release_method' => 'in_person',
            'location' => 'Learning Center',
            'requires_original_verification' => false,
            'recipient_ids' => [$application->id],
        ]);
    }

    private function recordMonitoringBenefit(User $provider, int $recordId): TestResponse
    {
        return $this->actingAs($provider)->postJson("/provider/benefit-release-records/{$recordId}/result", [
            'status' => 'released',
            'notes' => 'Recorded delivery by the provider.',
        ]);
    }

    public function test_check_in_and_reschedule_preserve_structured_addresses_and_their_own_pins(): void
    {
        [$provider, , $scholarship, $application] = $this->selectedRecipientWithPlan();
        $address = ['venue' => 'Learning Office', 'region' => 'Region IV-A', 'province' => 'Rizal', 'city' => 'Antipolo City', 'barangay' => 'San Isidro', 'street' => 'School Road', 'latitude' => 14.5861, 'longitude' => 121.1756];
        $response = $this->actingAs($provider)->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
            'title' => 'Structured venue appointment', 'handoff_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i'),
            'address_details' => $address, 'recipient_ids' => [$application->id],
        ])->assertCreated();
        $cycle = RecipientMonitoringCycle::findOrFail($response->json('cycle.id'));
        $case = $cycle->cases()->firstOrFail();
        $this->assertSame('Antipolo City', $cycle->address_details['city']);
        $this->assertSame(14.5861, $cycle->handoffDetails($case)['latitude']);
        $this->assertStringContainsString('School Road, San Isidro, Antipolo City', $cycle->handoff_location);
        $address['venue'] = 'Registrar Office';
        $address['latitude'] = null; $address['longitude'] = null;
        $this->patchJson("/provider/monitoring-cases/{$case->id}/appointment", [
            'action' => 'reschedule', 'appointment_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'address_details' => $address, 'reason' => 'Move the document check to the registrar office.',
        ])->assertOk();
        $updated = $case->fresh();
        $this->assertSame('Registrar Office', $updated->address_details['venue']);
        $this->assertNull($cycle->handoffDetails($updated)['latitude']);
        $this->assertStringStartsWith('Registrar Office,', $updated->appointment_location);
    }

    public function test_in_person_benefit_release_formats_and_saves_the_structured_venue(): void
    {
        [$provider, , $scholarship, $application] = $this->selectedRecipientWithPlan();
        $this->actingAs($provider)->postJson("/provider/scholarships/{$scholarship->id}/benefit-releases", [
            'title' => 'Learning session', 'release_at' => now()->addDay()->toDateTimeString(), 'benefit_description' => 'Learning materials',
            'release_method' => 'in_person', 'requires_original_verification' => false, 'recipient_ids' => [$application->id],
            'address_details' => ['venue' => 'Student Services', 'region' => 'NCR', 'province' => 'Metro Manila', 'city' => 'Quezon City', 'street' => 'School Road'],
        ])->assertCreated()->assertJsonPath('release.address_details.city', 'Quezon City')
            ->assertJsonPath('release.location', 'Student Services, School Road, Quezon City, Metro Manila, NCR');
    }

    public function test_check_in_requires_an_appointment_and_a_venue(): void
    {
        [$provider, , $scholarship] = $this->selectedRecipientWithPlan();
        $this->actingAs($provider)->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
            'title' => 'Future monitoring period',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addMonth()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['handoff_at', 'handoff_location']);
        $this->assertDatabaseCount('recipient_monitoring_cycles', 0);
    }

    public function test_check_in_appointment_must_be_future_and_within_the_monitoring_plan(): void
    {
        [$provider, , $scholarship, , $plan] = $this->selectedRecipientWithPlan();
        $plan->update(['starts_on' => now()->addWeek()->toDateString()]);
        foreach ([now()->subHour(), now()->addDay(), $plan->ends_on->copy()->addDay()] as $appointment) {
            $this->actingAs($provider)->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'Invalid appointment',
                'handoff_at' => $appointment->format('Y-m-d\TH:i'),
                'handoff_location' => 'Learning Center, Room 2',
            ])->assertUnprocessable()->assertJsonValidationErrors('handoff_at');
        }
        $this->assertDatabaseCount('recipient_monitoring_cycles', 0);
    }

    public function test_provider_publishes_active_plan_as_a_versioned_recipient_checklist(): void
    {
        [$provider, $applicant, $scholarship, $application, $plan] = $this->selectedRecipientWithPlan();
        $appointment = now()->addDay()->setTime(9, 30);

        $response = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'First semester check-in',
                'period_label' => 'First semester',
                'school_year' => '2026-2027',
                'handoff_at' => $appointment->format('Y-m-d\TH:i'),
                'handoff_location' => 'Learning Center, Room 2',
                'instructions' => 'Check in with the receptionist.',
                'recipient_ids' => [$application->id],
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.is_plan_check_in', true)
            ->assertJsonPath('cycle.monitoring_plan_version', 3)
            ->assertJsonPath('cycle.requirements.0.type', 'academic_progress')
            ->assertJsonPath('cycle.requirements.1.type', 'enrollment')
            ->assertJsonPath('cycle.requirements.2.type', 'program_participation')
            ->assertJsonPath('cycle.handoff.location', 'Learning Center, Room 2')
            ->assertJsonPath('cycle.handoff_started', false)
            ->assertJsonPath('cycle.requirements.0.requires_file', false)
            ->assertJsonPath('cycle.requirements.1.requires_original_verification', true)
            ->assertJsonPath('cycle.recipients.0.monitoring_case.next_action.key', 'attend_handoff');

        $cycleId = $response->json('cycle.id');
        $this->assertDatabaseHas('recipient_monitoring_cycles', [
            'id' => $cycleId,
            'recipient_monitoring_plan_id' => $plan->id,
            'monitoring_plan_version' => 3,
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
        ]);
        $this->assertDatabaseCount('recipient_monitoring_cycle_requirements', 3);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'recipient_monitoring_request')
            ->exists());

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.id', $cycleId)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.handoff.location', 'Learning Center, Room 2')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.pending_count', 0)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.status', 'bring_document')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.can_submit', false)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.status', 'awaiting_provider');
        $this->getJson("/dashboard/monitoring/{$application->id}/data?view=check-ins&record=check-in-{$cycleId}")
            ->assertOk()
            ->assertJsonPath('detail.content.selected.handoff.location', 'Learning Center, Room 2')
            ->assertJsonPath('detail.content.selected.preparation.document_ids', [
                $response->json('cycle.requirements.0.id'),
                $response->json('cycle.requirements.1.id'),
            ])
            ->assertJsonPath('detail.content.directory.next_appointment.preparation.document_count', 2)
            ->assertJsonPath('detail.content.directory.next_appointment.preparation.preview_titles', ['Semester grades', 'Proof of current enrollment'])
            ->assertJsonPath('detail.content.selected.state', 'upcoming');

        $requirementId = $response->json('cycle.requirements.0.id');
        $requirementPath = "/dashboard/applications/{$application->id}/monitoring-requirements/{$requirementId}";
        $legacyPath = "/dashboard/applications/{$application->id}/monitoring-cycles/{$cycleId}";
        $recordPath = "/provider/monitoring-requirements/{$requirementId}/applications/{$application->id}/record";
        $this->postJson($requirementPath.'/submission', [])->assertUnprocessable()->assertJsonValidationErrors('supporting_record');
        $this->patchJson($requirementPath.'/manual-grade', ['grade' => 100])->assertUnprocessable()->assertJsonValidationErrors('supporting_record');
        $this->postJson($legacyPath.'/grade-record', [])->assertUnprocessable()->assertJsonValidationErrors('grade_record');
        $this->patchJson($legacyPath.'/manual-grade', ['grade' => 100])->assertUnprocessable()->assertJsonValidationErrors('grade_record');
        $this->post($requirementPath.'/adjustment-request', [
            'request_type' => 'exception', 'reason_category' => 'school_schedule',
            'explanation' => 'My school schedule overlaps with the appointment.',
            'supporting_record' => UploadedFile::fake()->create('grades.pdf', 20, 'application/pdf'),
            'confirmation' => '1',
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('supporting_record');
        $this->assertDatabaseCount('recipient_monitoring_submissions', 0);

        $this->actingAs($provider)->postJson($recordPath, [
            'decision' => 'met', 'verified_grade' => 92, 'document_checked' => true,
        ])->assertUnprocessable();

        $this->travelTo($appointment->copy()->addMinute());
        $this->postJson($recordPath, ['decision' => 'met', 'verified_grade' => 92])
            ->assertUnprocessable()->assertJsonValidationErrors('document_checked');
        $this->postJson($recordPath, ['decision' => 'met', 'verified_grade' => 80, 'document_checked' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('verified_grade');
        $this->postJson($recordPath, ['decision' => 'met', 'verified_grade' => 92, 'document_checked' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('attendance_status');
        $caseId = $response->json('cycle.recipients.0.monitoring_case.id');
        $this->patchJson("/provider/monitoring-cases/{$caseId}/appointment", [
            'action' => 'attendance', 'attendance_status' => 'attended',
        ])->assertOk()->assertJsonPath('cycle.recipients.0.monitoring_case.attendance_status', 'attended');
        $this->postJson($recordPath, ['decision' => 'met', 'verified_grade' => 92, 'document_checked' => true])
            ->assertCreated()
            ->assertJsonPath('cycle.recipients.0.requirements.0.submission.grade_source', 'provider_verified');
        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'recipient_monitoring_cycle_requirement_id' => $requirementId,
            'submission_source' => 'provider_record', 'path' => null, 'review_status' => 'met',
        ]);
        $submission = RecipientMonitoringSubmission::query()->firstOrFail();
        $this->patchJson("/provider/monitoring-submissions/{$submission->id}/review", ['decision' => 'met'])
            ->assertUnprocessable();
        $this->actingAs($applicant)->patchJson($legacyPath.'/manual-grade', ['grade' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('grade_record');
        $this->travelBack();
    }

    public function test_recipient_uploads_each_checklist_item_independently_and_academic_item_uses_ocr(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'period_label' => 'First semester',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addMonth()->toDateString(),
        ])
            ->json('cycle.id');

        $requirements = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->get()
            ->keyBy('type');
        $academic = $requirements->get('academic_progress');
        $enrollment = $requirements->get('enrollment');
        $participation = $requirements->get('program_participation');

        Http::fake([
            'api.ocr.space/*' => Http::response([
                'OCRExitCode' => 1,
                'IsErroredOnProcessing' => false,
                'ParsedResults' => [[
                    'ParsedText' => "Learner: Checklist Recipient\nGeneral Average: 92%",
                ]],
            ]),
        ]);

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$enrollment->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('registration.pdf', 120, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.pending_count', 1);

        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'recipient_monitoring_cycle_requirement_id' => $enrollment->id,
            'scholarship_application_id' => $application->id,
            'ocr_status' => 'not_requested',
        ]);

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$academic->id}/submission", [
                'supporting_record' => UploadedFile::fake()->image('report-card.jpg')->size(200),
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.pending_count', 0)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.submitted_count', 2)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.submission.ocr_status', 'succeeded')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.submission.grade_label', '92.00%');

        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'recipient_monitoring_cycle_requirement_id' => $academic->id,
            'scholarship_application_id' => $application->id,
            'ocr_status' => 'succeeded',
            'ocr_grade' => 92,
        ]);
        $this->assertSame(2, RecipientMonitoringSubmission::query()
            ->where('scholarship_application_id', $application->id)
            ->count());

        $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles")
            ->assertOk()
            ->assertJsonPath('cycles.0.item_submitted_count', 2)
            ->assertJsonPath('cycles.0.item_pending_count', 0)
            ->assertJsonPath('cycles.0.recipients.0.checklist_complete', true)
            ->assertJsonPath('cycles.0.recipients.0.monitoring_case.exception_context', null)
            ->assertJsonPath('cycles.0.recipients.0.monitoring_case.history', [])
            ->assertJsonPath('program_summary.attention_count', 2);

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$participation->id}/submission", [
                'supporting_record' => UploadedFile::fake()->image('attendance.jpg'),
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supporting_record');
    }

    public function test_checklist_snapshot_does_not_change_when_provider_edits_the_plan(): void
    {
        [$provider, , $scholarship, $application, $plan] = $this->selectedRecipientWithPlan();
        $cycleId = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/monitoring-check-ins", [
                'title' => 'Locked checklist',
                'handoff_at' => now()->addMonth()->format('Y-m-d\TH:i'),
                'handoff_location' => 'Learning Center, Room 2',
                'recipient_ids' => [$application->id],
            ])
            ->assertCreated()
            ->json('cycle.id');

        $plan->requirements()->firstWhere('type', 'enrollment')->update([
            'title' => 'Updated enrollment title',
        ]);
        $plan->update(['version' => 4]);

        $cycle = RecipientMonitoringCycle::with('requirements')->findOrFail($cycleId);
        $this->assertSame(3, $cycle->monitoring_plan_version);
        $this->assertSame(
            'Proof of current enrollment',
            $cycle->requirements->firstWhere('type', 'enrollment')->title,
        );
    }

    public function test_provider_reviews_uploaded_items_and_recipient_can_replace_a_record_after_the_due_date(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addMonth()->toDateString(),
        ])
            ->json('cycle.id');
        $requirement = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('type', 'enrollment')
            ->firstOrFail();

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('registration.pdf', 120, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertCreated();
        $submission = RecipientMonitoringSubmission::query()
            ->where('recipient_monitoring_cycle_requirement_id', $requirement->id)
            ->firstOrFail();

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-submissions/{$submission->id}/review", [
                'decision' => 'needs_correction',
                'notes' => 'Upload the complete registration form with the current term visible.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.requirements.1.submission.review_status', 'needs_correction');

        RecipientMonitoringCycle::findOrFail($cycleId)->update(['due_at' => now()->subDay()->toDateString()]);
        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('complete-registration.pdf', 140, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertOk();

        $submission->refresh();
        $this->assertSame('applicant_upload', $submission->submission_source);
        $this->assertSame('pending', $submission->review_status);

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-submissions/{$submission->id}/review", [
                'decision' => 'met',
                'notes' => 'The replacement record is complete.',
            ])
            ->assertOk();

        $this->assertDatabaseCount('recipient_monitoring_reviews', 2);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $applicant->id)
            ->where('type', 'recipient_monitoring_review')
            ->where('title', 'Requirement confirmed')
            ->exists());
    }

    public function test_provider_records_non_file_requirements_with_an_audit_history(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addMonth()->toDateString(),
        ])
            ->json('cycle.id');
        $requirements = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->get()
            ->keyBy('type');
        $participation = $requirements->get('program_participation');

        $this->actingAs($provider)
            ->postJson("/provider/monitoring-requirements/{$participation->id}/applications/{$application->id}/record", [
                'decision' => 'met',
                'notes' => 'Attendance was confirmed from the signed orientation sheet.',
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.recipients.0.required_review_count', 3)
            ->assertJsonPath('cycle.recipients.0.reviewed_item_count', 1)
            ->assertJsonPath('cycle.recipients.0.requirements.2.submission.submission_source', 'provider_record')
            ->assertJsonPath('cycle.recipients.0.requirements.2.submission.has_file', false)
            ->assertJsonPath('cycle.recipients.0.requirements.2.submission.review_status', 'met');

        $this->assertDatabaseHas('recipient_monitoring_submissions', [
            'recipient_monitoring_cycle_requirement_id' => $participation->id,
            'scholarship_application_id' => $application->id,
            'submission_source' => 'provider_record',
            'path' => null,
            'review_status' => 'met',
        ]);
        $this->assertDatabaseCount('recipient_monitoring_reviews', 1);

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.status', 'completed')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.submission.has_file', false)
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.submission.review_status_label', 'Requirement met');

        $this->actingAs($provider)
            ->postJson("/provider/monitoring-requirements/{$requirements->get('enrollment')->id}/applications/{$application->id}/record", [
                'decision' => 'met',
            ])
            ->assertUnprocessable();
    }

    public function test_recipient_requests_and_uses_an_approved_personal_extension(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $originalDue = now()->addDays(5);
        $approvedDue = now()->addDays(12);
        $cycleId = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => $originalDue->toDateString(),
        ])
            ->json('cycle.id');
        $requirement = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('type', 'enrollment')
            ->firstOrFail();

        $response = $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/adjustment-request", [
                'request_type' => 'extension',
                'reason_category' => 'school_schedule',
                'explanation' => 'The registrar will release the current enrollment form after the original deadline.',
                'requested_due_at' => $approvedDue->toDateString(),
                'confirmation' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.1.adjustment_request.status', 'pending');
        $adjustmentId = $response->json('application.recipient_monitoring.check_ins.0.requirements.1.adjustment_request.id');

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-adjustment-requests/{$adjustmentId}/decision", [
                'decision' => 'approved',
                'decision_notes' => 'Registrar delay confirmed.',
                'approved_due_at' => $approvedDue->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.requirements.1.adjustment_request.status', 'approved')
            ->assertJsonPath('cycle.recipients.0.requirements.1.effective_due_at', $approvedDue->toDateString())
            ->assertJsonPath('cycle.recipients.0.monitoring_case.exception_context.summary', 'Extension approved through '.$approvedDue->format('M d, Y'))
            ->assertJsonPath('cycle.recipients.0.monitoring_case.history.0.title', 'Extension Approved')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.history.0.actor', $provider->name)
            ->assertJsonPath('cycle.recipients.0.monitoring_case.history.1.title', 'Extension requested');

        RecipientMonitoringCycle::findOrFail($cycleId)->update(['due_at' => now()->subDay()->toDateString()]);
        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('late-registration.pdf', 120, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.monitoring_case.exception_context.approved_due_label', $approvedDue->format('M d, Y'))
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.monitoring_case.history.0.actor', 'Provider team');

        $this->assertDatabaseHas('recipient_monitoring_adjustment_requests', [
            'id' => $adjustmentId,
            'status' => 'approved',
            'approved_due_at' => $approvedDue->toDateString().' 00:00:00',
        ]);
    }

    public function test_approved_exception_completes_the_requirement_with_an_audit_record(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addWeek()->toDateString(),
        ])
            ->json('cycle.id');
        $requirement = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('type', 'program_participation')
            ->firstOrFail();

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirement->id}/adjustment-request", [
                'request_type' => 'exception',
                'reason_category' => 'illness',
                'explanation' => 'I was confined during the required orientation and could not attend.',
                'confirmation' => true,
            ])
            ->assertCreated();
        $adjustment = RecipientMonitoringAdjustmentRequest::firstOrFail();

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-adjustment-requests/{$adjustment->id}/decision", [
                'decision' => 'approved',
                'decision_notes' => 'Approved based on the reported health circumstance.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.requirements.2.submission.review_status', 'excused');

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.status', 'completed')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.2.adjustment_request.status', 'approved');
        $this->assertDatabaseHas('recipient_monitoring_reviews', ['decision' => 'excused']);
    }

    public function test_provider_intervention_is_shared_and_can_be_completed(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addMonth()->toDateString(),
        ])
            ->json('cycle.id');
        $requirement = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('type', 'academic_progress')
            ->firstOrFail();

        $response = $this->actingAs($provider)
            ->postJson("/provider/monitoring-requirements/{$requirement->id}/applications/{$application->id}/interventions", [
                'type' => 'consultation',
                'summary' => 'Schedule a short academic consultation before the next release.',
                'action_required' => 'Contact the scholarship desk to select an available time.',
                'follow_up_on' => now()->addWeek()->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.recipients.0.requirements.0.interventions.0.status', 'open');
        $interventionId = $response->json('cycle.recipients.0.requirements.0.interventions.0.id');

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.interventions.0.type', 'consultation')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.interventions.0.status', 'open');

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-interventions/{$interventionId}/complete", [
                'completion_notes' => 'The consultation was completed and the next steps were discussed.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.requirements.0.interventions.0.status', 'completed');
        $this->assertSame('completed', RecipientMonitoringIntervention::findOrFail($interventionId)->status);
    }

    public function test_monitoring_case_keeps_one_next_action_as_the_checklist_advances(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $response = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addMonth()->toDateString(),
        ])
            ->assertJsonPath('cycle.recipients.0.monitoring_case.status', 'awaiting_submission')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.due_status', 'open')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.next_action.role', 'applicant')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.next_action.key', 'submit_requirements');
        $cycleId = $response->json('cycle.id');
        $requirements = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->get()
            ->keyBy('type');

        Http::fake([
            'api.ocr.space/*' => Http::response([
                'OCRExitCode' => 1,
                'IsErroredOnProcessing' => false,
                'ParsedResults' => [['ParsedText' => 'General Average: 91%']],
            ]),
        ]);

        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirements->get('enrollment')->id}/submission", [
                'supporting_record' => UploadedFile::fake()->create('registration.pdf', 120, 'application/pdf'),
                'terms_accepted' => true,
            ])
            ->assertCreated();
        $this->actingAs($applicant)
            ->postJson("/dashboard/applications/{$application->id}/monitoring-requirements/{$requirements->get('academic_progress')->id}/submission", [
                'supporting_record' => UploadedFile::fake()->image('grades.jpg'),
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.case_status', 'awaiting_submission')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.next_action.role', 'provider')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.next_action.key', 'record_requirement');

        $this->actingAs($provider)
            ->postJson("/provider/monitoring-requirements/{$requirements->get('program_participation')->id}/applications/{$application->id}/record", [
                'decision' => 'met',
                'notes' => 'Participation confirmed from the attendance sheet.',
            ])
            ->assertCreated()
            ->assertJsonPath('cycle.recipients.0.monitoring_case.status', 'under_review')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.next_action.key', 'review_requirements');

        $submissions = RecipientMonitoringSubmission::query()
            ->where('scholarship_application_id', $application->id)
            ->whereIn('recipient_monitoring_cycle_requirement_id', [
                $requirements->get('academic_progress')->id,
                $requirements->get('enrollment')->id,
            ])
            ->get();
        foreach ($submissions as $submission) {
            $lastResponse = $this->actingAs($provider)
                ->patchJson("/provider/monitoring-submissions/{$submission->id}/review", [
                    'decision' => 'met',
                    'notes' => 'Record confirmed.',
                ])
                ->assertOk();
        }

        $lastResponse
            ->assertJsonPath('cycle.recipients.0.monitoring_case.status', 'ready_to_close')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.next_action.role', 'provider')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.next_action.key', 'close_check_in');
        $this->assertDatabaseHas('recipient_monitoring_cases', [
            'recipient_monitoring_cycle_id' => $cycleId,
            'scholarship_application_id' => $application->id,
            'status' => 'ready_to_close',
            'due_status' => 'open',
            'next_action_role' => 'provider',
            'next_action' => 'close_check_in',
        ]);
    }

    public function test_monitoring_case_distinguishes_the_request_window_from_overdue_work(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $cycleId = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addWeek()->toDateString(),
        ])
            ->json('cycle.id');

        RecipientMonitoringCycle::findOrFail($cycleId)->update([
            'due_at' => now()->subDay()->toDateString(),
        ]);
        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.status', 'grace_period')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.due_status', 'grace_period')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.next_action.role', 'applicant')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.next_action.key', 'request_adjustment')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.requirements.0.status', 'grace_period');
        $this->actingAs($provider)
            ->getJson('/provider/workspaces/monitoring/data?queue=awaiting')
            ->assertOk()
            ->assertJsonPath('check_ins.0.id', $cycleId)
            ->assertJsonPath('check_ins.0.work_label', 'Request window open')
            ->assertJsonPath('check_ins.0.timing_state', 'grace_period');

        RecipientMonitoringCycle::findOrFail($cycleId)->update([
            'due_at' => now()->subDays(8)->toDateString(),
        ]);
        $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles")
            ->assertOk()
            ->assertJsonPath('cycles.0.recipients.0.monitoring_case.due_status', 'overdue')
            ->assertJsonPath('cycles.0.recipients.0.monitoring_case.next_action.role', 'provider')
            ->assertJsonPath('cycles.0.recipients.0.monitoring_case.next_action.key', 'resolve_overdue');
        $this->actingAs($provider)
            ->getJson('/provider/workspaces/monitoring/data?queue=followup')
            ->assertOk()
            ->assertJsonPath('check_ins.0.id', $cycleId)
            ->assertJsonPath('check_ins.0.work_label', 'Overdue review')
            ->assertJsonPath('check_ins.0.timing_state', 'overdue');

        $case = RecipientMonitoringCase::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->where('scholarship_application_id', $application->id)
            ->firstOrFail();
        $this->assertSame('overdue', $case->due_status);
        $this->assertSame('resolve_overdue', $case->next_action);
    }

    public function test_provider_closes_and_reopens_a_completed_check_in_with_an_event_history(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $response = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'First semester check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addMonth()->toDateString(),
        ]);
        $cycleId = $response->json('cycle.id');
        $requirements = RecipientMonitoringCycleRequirement::query()
            ->where('recipient_monitoring_cycle_id', $cycleId)
            ->get();

        foreach ($requirements as $requirement) {
            RecipientMonitoringSubmission::create([
                'recipient_monitoring_cycle_id' => $cycleId,
                'recipient_monitoring_cycle_requirement_id' => $requirement->id,
                'scholarship_application_id' => $application->id,
                'applicant_id' => $applicant->id,
                'submission_source' => $requirement->requires_file ? 'applicant_upload' : 'provider_record',
                'original_name' => $requirement->requires_file ? 'confirmed-record.pdf' : 'Provider confirmation',
                'path' => $requirement->requires_file ? "monitoring/{$requirement->id}.pdf" : 'provider-record://confirmed',
                'mime_type' => $requirement->requires_file ? 'application/pdf' : null,
                'submitted_at' => now(),
                'review_status' => 'met',
                'review_notes' => 'Confirmed for closure testing.',
                'reviewed_by' => $provider->id,
                'reviewed_at' => now(),
            ]);
        }

        $readyResponse = $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles")
            ->assertOk()
            ->assertJsonPath('cycles.0.recipients.0.monitoring_case.status', 'ready_to_close')
            ->assertJsonPath('cycles.0.recipients.0.monitoring_case.status_label', 'Awaiting closure');
        $caseId = $readyResponse->json('cycles.0.recipients.0.monitoring_case.id');
        $this->actingAs($applicant)->getJson("/dashboard/monitoring/{$application->id}/data?view=check-ins")
            ->assertOk()
            ->assertJsonPath('detail.summary.check_ins.completed', 0)
            ->assertJsonPath('detail.summary.check_ins.next_due_label', null)
            ->assertJsonPath('detail.content.directory.active.0.state_label', 'Awaiting closure')
            ->assertJsonPath('detail.decision.actor', 'provider')
            ->assertJsonPath('detail.decision.title', 'Wait for the final check-in outcome');

        $otherProvider = User::factory()->create(['role' => 'provider', 'account_status' => 'active']);
        $this->actingAs($otherProvider)
            ->patchJson("/provider/monitoring-cases/{$caseId}/close", [
                'outcome' => 'completed',
            ])
            ->assertForbidden();
        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-cases/{$caseId}/close", [
                'outcome' => 'completed_with_support',
                'reason' => 'Requirements were completed after provider support.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outcome');

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-cases/{$caseId}/close", [
                'outcome' => 'completed',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.status', 'closed')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.status', 'closed')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.outcome', 'completed')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.outcome_label', 'Completed');

        $this->assertDatabaseHas('recipient_monitoring_case_events', [
            'recipient_monitoring_case_id' => $caseId,
            'action' => 'closed',
            'from_status' => 'ready_to_close',
            'to_status' => 'closed',
            'outcome' => 'completed',
        ]);
        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.monitoring_case.outcome_label', 'Completed')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.monitoring_case.history.0.title', 'Check-in closed')
            ->assertJsonPath('application.recipient_monitoring.check_ins.0.monitoring_case.history.0.actor', 'Provider team');

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-cases/{$caseId}/reopen", [
                'reason' => 'A second review is required after a provider audit.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.status', 'open')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.status', 'ready_to_close')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.outcome', null);

        $this->assertDatabaseHas('recipient_monitoring_case_events', [
            'recipient_monitoring_case_id' => $caseId,
            'action' => 'reopened',
            'from_status' => 'closed',
            'to_status' => 'ready_to_close',
        ]);
        $this->assertSame(2, RecipientMonitoringCaseEvent::query()
            ->where('recipient_monitoring_case_id', $caseId)
            ->count());
    }

    public function test_provider_records_an_overdue_incomplete_outcome_or_a_reasoned_waiver(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipientWithPlan();
        $response = $this->existingUploadCheckIn($provider, $scholarship, [
            'title' => 'Overdue check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addWeek()->toDateString(),
        ]);
        $cycleId = $response->json('cycle.id');
        RecipientMonitoringCycle::findOrFail($cycleId)->update([
            'due_at' => now()->subDays(8)->toDateString(),
        ]);
        $caseId = $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$scholarship->id}/monitoring-cycles")
            ->assertOk()
            ->assertJsonPath('cycles.0.recipients.0.monitoring_case.due_status', 'overdue')
            ->json('cycles.0.recipients.0.monitoring_case.id');

        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-cases/{$caseId}/close", [
                'outcome' => 'incomplete',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->actingAs($provider)
            ->patchJson("/provider/monitoring-cases/{$caseId}/close", [
                'outcome' => 'incomplete',
                'reason' => 'No required records were received after the grace period.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.recipients.0.monitoring_case.outcome', 'incomplete')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.status_label', 'Incomplete')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.decision_reason', 'No required records were received after the grace period.');
        $this->actingAs($applicant)->getJson("/dashboard/monitoring/{$application->id}/data?view=check-ins&record=check-in-{$cycleId}")
            ->assertOk()
            ->assertJsonPath('detail.summary.check_ins.completed', 0)
            ->assertJsonPath('detail.content.directory.completed.0.state_label', 'Incomplete')
            ->assertJsonPath('detail.content.selected.state_tone', 'critical')
            ->assertJsonPath('detail.content.selected.guidance', null)
            ->assertJsonPath('detail.content.selected.requirements.0.can_submit', false);
        $this->getJson("/dashboard/monitoring/{$application->id}/data?view=history")
            ->assertOk()
            ->assertJsonPath('detail.content.timeline.0.description', 'Incomplete')
            ->assertJsonPath('detail.content.timeline.0.tone', 'critical');

        [$waiverProvider, $waiverApplicant, $waiverScholarship] = $this->selectedRecipientWithPlan();
        $waiverResponse = $this->existingUploadCheckIn($waiverProvider, $waiverScholarship, [
            'title' => 'Special circumstance check-in',
            'opens_at' => now()->toDateString(),
            'due_at' => now()->addMonth()->toDateString(),
        ]);
        $waiverCaseId = $waiverResponse->json('cycle.recipients.0.monitoring_case.id');
        $waiverRequirementId = $waiverResponse->json('cycle.requirements.0.id');
        $waiverApplicationId = $waiverResponse->json('cycle.recipients.0.application_id');

        $interventionId = $this->actingAs($waiverProvider)
            ->postJson("/provider/monitoring-requirements/{$waiverRequirementId}/applications/{$waiverApplicationId}/interventions", [
                'type' => 'consultation',
                'summary' => 'Confirm the recipient circumstance before deciding the check-in.',
            ])
            ->assertCreated()
            ->json('cycle.recipients.0.requirements.0.interventions.0.id');
        $this->actingAs($waiverProvider)
            ->patchJson("/provider/monitoring-cases/{$waiverCaseId}/waive", [
                'outcome' => 'excused',
                'reason' => 'The recipient has an approved documented leave for this period.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('case');
        $this->actingAs($waiverProvider)
            ->patchJson("/provider/monitoring-interventions/{$interventionId}/complete", [
                'completion_notes' => 'The documented circumstance was confirmed.',
            ])
            ->assertOk();

        $this->actingAs($waiverProvider)
            ->patchJson("/provider/monitoring-cases/{$waiverCaseId}/waive", [
                'outcome' => 'excused',
                'reason' => 'The recipient has an approved documented leave for this period.',
            ])
            ->assertOk()
            ->assertJsonPath('cycle.status', 'closed')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.status', 'waived')
            ->assertJsonPath('cycle.recipients.0.monitoring_case.status_label', 'Excused');
        $this->actingAs($waiverApplicant)->getJson("/dashboard/monitoring/{$waiverApplicationId}/data?view=check-ins")
            ->assertOk()
            ->assertJsonPath('detail.content.directory.completed.0.state_label', 'Excused')
            ->assertJsonPath('detail.summary.check_ins.completed', 0);

        $this->assertDatabaseHas('recipient_monitoring_case_events', [
            'recipient_monitoring_case_id' => $waiverCaseId,
            'action' => 'waived',
            'outcome' => 'excused',
        ]);
        $this->assertDatabaseHas('recipient_monitoring_cases', [
            'scholarship_application_id' => $application->id,
            'outcome' => 'incomplete',
        ]);
    }

    private function existingUploadCheckIn(User $provider, Scholarship $scholarship, array $attributes): TestResponse
    {
        $plan = $scholarship->monitoringPlan()->with('requirements')->firstOrFail();
        $cycle = $scholarship->monitoringCycles()->create([
            ...$attributes,
            'academic_period' => $attributes['period_label'] ?? null,
            'period_type' => 'semester',
            'recipient_monitoring_plan_id' => $plan->id,
            'monitoring_plan_version' => $plan->version,
            'grace_period_days' => $plan->grace_period_days,
            'allow_exception_requests' => $plan->allow_exception_requests,
            'created_by' => $provider->id,
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'open',
            'published_at' => now(),
        ]);
        foreach ($plan->requirements as $requirement) {
            $cycle->requirements()->create([
                'source_requirement_id' => $requirement->id,
                ...$requirement->only([
                    'type', 'title', 'description', 'evidence_description', 'required', 'requires_file',
                    'requires_original_verification', 'minimum_grade', 'grading_scale', 'sort_order',
                ]),
            ]);
        }

        return $this->actingAs($provider)
            ->getJson("/provider/workspaces/monitoring/check-ins/{$cycle->id}/data")
            ->assertOk();
    }

    private function selectedRecipientWithPlan(): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'account_status' => 'active']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Tulay Aral Continuing Support',
            'description' => 'A scholarship with a complete monitoring checklist.',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'under_review',
            'document_checklist' => [],
            'submitted_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);
        $application->update([
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
            'provider_contract_terms_accepted_at' => now(),
        ]);
        $plan = RecipientMonitoringPlan::create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $provider->id,
            'updated_by' => $provider->id,
            'frequency' => 'semester',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'status' => 'active',
            'version' => 3,
            'activated_at' => now(),
        ]);
        $plan->requirements()->createMany([
            [
                'type' => 'academic_progress',
                'title' => 'Semester grades',
                'evidence_description' => 'Latest report card or official grade report.',
                'required' => true,
                'requires_file' => true,
                'requires_original_verification' => true,
                'minimum_grade' => 85,
                'grading_scale' => 'percentage',
                'sort_order' => 0,
            ],
            [
                'type' => 'enrollment',
                'title' => 'Proof of current enrollment',
                'evidence_description' => 'Enrollment certificate or registration form.',
                'required' => true,
                'requires_file' => true,
                'requires_original_verification' => false,
                'sort_order' => 1,
            ],
            [
                'type' => 'program_participation',
                'title' => 'Orientation attendance',
                'evidence_description' => 'Recorded by the provider.',
                'required' => true,
                'requires_file' => false,
                'requires_original_verification' => false,
                'sort_order' => 2,
            ],
        ]);

        return [$provider, $applicant, $scholarship, $application->fresh(), $plan->fresh()];
    }
}
