<?php

namespace Tests\Feature;

use App\Mail\PortalNotificationMail;
use App\Models\ApplicationSchedule;
use App\Models\MobileApiToken;
use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProgramSelectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_approval_advances_to_the_configured_stage_and_applies_its_shared_schedule(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'exam',
            'interview',
            'distribution',
        ]);
        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'exam',
                'title' => 'General qualifying exam',
                'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => 'onsite',
                'venue' => 'Community Learning Center',
                'location_address' => 'Mabini Street, Quezon City',
                'instructions' => 'Bring a school ID and arrive 15 minutes early.',
            ])
            ->assertOk()
            ->assertJsonPath('audience_count', 0);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Eligibility and required files were reviewed.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'exam_scheduled')
            ->assertJsonPath('application.schedules.0.type', 'exam')
            ->assertJsonPath('application.schedules.0.title', 'General qualifying exam');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'exam_scheduled',
        ]);
        $this->assertDatabaseHas('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'exam',
            'title' => 'General qualifying exam',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_schedule',
        ]);
        $this->assertStringNotContainsString(
            'acknowledge',
            PortalNotification::query()
                ->where('user_id', $applicant->id)
                ->where('type', 'application_schedule')
                ->latest('id')
                ->value('message'),
        );
    }

    public function test_shared_distribution_is_released_only_after_a_formal_award_is_recorded(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'distribution',
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'distribution',
                'title' => 'Scholarship release day',
                'scheduled_at' => now()->addWeek()->format('Y-m-d H:i:s'),
                'mode' => 'onsite',
                'venue' => 'Foundation Office',
                'instructions' => 'Bring a valid school ID and release form.',
            ])
            ->assertOk()
            ->assertJsonPath('audience_count', 0);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Applicant passed document and eligibility review.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved')
            ->assertJsonCount(0, 'application.schedules');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'awarded',
                'decision_reason' => 'approved_for_award',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'distribution_scheduled')
            ->assertJsonPath('application.schedules.0.type', 'distribution');

        $application->refresh();

        $this->assertNotNull($application->distribution_scheduled_for);
        $this->assertSame('distribution_scheduled', $application->status);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_status',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'application_schedule',
        ]);
    }

    public function test_schedules_added_after_stage_approval_are_applied_to_waiting_applicants(): void
    {
        $cases = [
            [
                'stages' => ['screening', 'exam', 'distribution'],
                'status' => 'exam_qualified',
                'type' => 'exam',
                'expected_status' => 'exam_scheduled',
            ],
            [
                'stages' => ['screening', 'interview', 'distribution'],
                'status' => 'interview',
                'type' => 'interview',
                'expected_status' => 'interview',
            ],
            [
                'stages' => ['screening', 'distribution'],
                'status' => 'awarded',
                'type' => 'distribution',
                'expected_status' => 'distribution_scheduled',
            ],
        ];

        foreach ($cases as $index => $case) {
            [$provider, $applicant, $scholarship, $application] = $this->applicationWithPlan(
                $case['stages'],
                $case['status'],
            );

            $this->actingAs($provider)
                ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                    'type' => $case['type'],
                    'title' => ucfirst($case['type']).' schedule',
                    'scheduled_at' => now()->addDays($index + 2)->format('Y-m-d H:i:s'),
                    'mode' => 'provider_managed',
                    'instructions' => 'Open the application for the confirmed schedule details.',
                ])
                ->assertOk()
                ->assertJsonPath('audience_count', 1);

            $this->assertDatabaseHas('scholarship_applications', [
                'id' => $application->id,
                'status' => $case['expected_status'],
            ]);
            $this->assertDatabaseHas('application_schedules', [
                'scholarship_application_id' => $application->id,
                'type' => $case['type'],
                'status' => 'scheduled',
            ]);
            $this->assertDatabaseHas('portal_notifications', [
                'user_id' => $applicant->id,
                'type' => 'application_schedule',
            ]);
        }
    }

    public function test_direct_status_updates_apply_an_existing_shared_schedule(): void
    {
        [$provider, $_applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'exam',
            'distribution',
        ]);

        ScholarshipEvent::create([
            'scholarship_id' => $scholarship->id,
            'type' => 'exam',
            'title' => 'Shared qualifying exam',
            'scheduled_at' => now()->addDays(3),
            'mode' => 'provider_managed',
            'instructions' => 'Review the posted exam instructions.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/status", [
                'status' => 'exam_qualified',
                'decision_reason' => 'for_exam',
                'review_notes' => 'The applicant passed eligibility review.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'exam_scheduled')
            ->assertJsonPath('application.schedules.0.type', 'exam');
    }

    public function test_online_distribution_can_use_instructions_without_a_link_but_interview_cannot(): void
    {
        [$provider, $_applicant, $scholarship] = $this->applicationWithPlan([
            'screening',
            'interview',
            'distribution',
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'distribution',
                'title' => 'Digital scholarship release',
                'scheduled_at' => now()->addWeek()->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'instructions' => 'Approved recipients will receive transfer instructions by email.',
            ])
            ->assertOk()
            ->assertJsonPath('event.mode', 'online')
            ->assertJsonPath('event.online_url', null);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'interview',
                'title' => 'Online applicant interview',
                'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'instructions' => 'Join ten minutes before the interview.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('online_url');
    }

    public function test_editing_a_shared_event_preserves_each_applicants_completed_tracking(): void
    {
        [$provider, $_applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'interview',
            'distribution',
        ], 'interview');
        $firstDate = now()->addDays(2)->setTime(9, 0);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'interview',
                'title' => 'Applicant interview',
                'scheduled_at' => $firstDate->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'online_url' => 'https://example.test/interview-room',
                'instructions' => 'Join ten minutes before the interview.',
            ])
            ->assertOk()
            ->assertJsonPath('audience_count', 1);

        $schedule = ApplicationSchedule::query()
            ->where('scholarship_application_id', $application->id)
            ->where('type', 'interview')
            ->firstOrFail();
        $schedule->update([
            'status' => 'completed',
            'attendance_status' => 'attended',
            'attendance_notes' => 'Applicant completed the interview.',
            'completed_at' => now(),
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'interview',
                'title' => 'Final applicant interview',
                'scheduled_at' => now()->addDays(4)->setTime(10, 0)->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'online_url' => 'https://example.test/final-interview-room',
                'instructions' => 'Use the updated meeting link.',
            ])
            ->assertOk()
            ->assertJsonPath('audience_count', 0);

        $schedule->refresh();

        $this->assertSame('Applicant interview', $schedule->title);
        $this->assertSame($firstDate->format('Y-m-d H:i:s'), $schedule->scheduled_at?->format('Y-m-d H:i:s'));
        $this->assertSame('completed', $schedule->status);
        $this->assertSame('attended', $schedule->attendance_status);
        $this->assertSame('Applicant completed the interview.', $schedule->attendance_notes);
        $this->assertSame('Final applicant interview', $scholarship->events()->where('type', 'interview')->value('title'));
    }

    public function test_provider_cannot_publish_a_stage_that_is_not_in_the_program_plan(): void
    {
        [$provider, $_applicant, $scholarship] = $this->applicationWithPlan([
            'screening',
            'distribution',
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'interview',
                'scheduled_at' => now()->addDay()->format('Y-m-d H:i:s'),
                'mode' => 'online',
                'online_url' => 'https://example.test/interview',
                'instructions' => 'Join the interview room.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->assertSame(0, ScholarshipEvent::query()->count());
    }

    public function test_program_form_stores_optional_stages_in_the_canonical_order(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Interview Selection Scholarship',
                'description' => 'A program with an interview before final approval.',
                'selection_stages' => json_encode(['interview']),
                'status' => 'draft',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.selection_stages', [
                'screening',
                'interview',
            ]);

        $this->assertDatabaseHas('scholarships', [
            'provider_id' => $provider->id,
            'title' => 'Interview Selection Scholarship',
            'selection_stages' => json_encode(['screening', 'interview']),
        ]);
    }

    public function test_program_form_accepts_online_distribution_without_a_link(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Digital Release Scholarship',
                'description' => 'A program that releases its benefits remotely.',
                'selection_stages' => json_encode(['distribution']),
                'program_events' => json_encode([[
                    'type' => 'distribution',
                    'title' => 'Digital benefit release',
                    'scheduled_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
                    'mode' => 'online',
                    'instructions' => 'Recipients will receive secure transfer instructions after approval.',
                ]]),
                'status' => 'draft',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.program_events.0.mode', 'online')
            ->assertJsonPath('scholarship.program_events.0.online_url', null);
    }

    public function test_program_form_saves_planned_dates_that_applicants_can_preview_before_applying(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $examDate = now()->addDays(5)->startOfHour();
        $distributionDate = now()->addDays(20)->startOfHour();

        $scholarshipId = $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Scheduled Selection Scholarship',
                'description' => 'A program with dates applicants can review before applying.',
                'selection_stages' => json_encode(['exam', 'distribution']),
                'exam_duration_minutes' => 75,
                'exam_passing_score' => 70,
                'program_events' => json_encode([
                    [
                        'type' => 'exam',
                        'title' => 'General qualifying exam',
                        'scheduled_at' => $examDate->format('Y-m-d H:i:s'),
                        'mode' => 'online',
                        'online_url' => 'https://example.test/private-exam-room',
                        'instructions' => 'Use the private link sent to qualified applicants.',
                    ],
                    [
                        'type' => 'distribution',
                        'title' => 'Scholarship distribution day',
                        'scheduled_at' => $distributionDate->format('Y-m-d H:i:s'),
                        'mode' => 'onsite',
                        'venue' => 'Community Learning Center',
                        'location_address' => 'Mabini Street, Quezon City',
                        'instructions' => 'Bring a valid ID and the signed award agreement.',
                    ],
                ]),
                'status' => 'draft',
                'terms_accepted' => true,
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'scholarship.program_events')
            ->assertJsonPath('scholarship.exam_duration_minutes', 75)
            ->assertJsonPath('scholarship.exam_passing_score', '70.00')
            ->assertJsonPath('scholarship.program_events.0.type', 'exam')
            ->assertJsonPath('scholarship.program_events.1.type', 'distribution')
            ->json('scholarship.id');

        $scholarship = Scholarship::findOrFail($scholarshipId);

        $this->actingAs($admin)
            ->getJson("/admin/scholarships/{$scholarship->id}/review/data")
            ->assertOk()
            ->assertJsonPath('scholarship.exam_duration_minutes', 75)
            ->assertJsonPath('scholarship.exam_passing_score', '70.00')
            ->assertJsonPath('scholarship.program_events.0.type', 'exam')
            ->assertJsonPath('scholarship.program_events.0.online_url', 'https://example.test/private-exam-room')
            ->assertJsonPath('scholarship.program_events.0.instructions', 'Use the private link sent to qualified applicants.')
            ->assertJsonPath('scholarship.program_events.1.venue', 'Community Learning Center');

        $scholarship->update(['status' => 'published']);

        $payload = $this->actingAs($applicant)
            ->getJson("/dashboard/scholarships/{$scholarship->id}/data")
            ->assertOk()
            ->assertJsonPath('scholarship.has_applied', false)
            ->assertJsonPath('scholarship.program_events.0.type', 'exam')
            ->assertJsonPath('scholarship.program_events.0.mode', 'online')
            ->assertJsonPath('scholarship.program_events.1.type', 'distribution')
            ->assertJsonPath('scholarship.program_events.1.venue', 'Community Learning Center')
            ->json('scholarship.program_events');

        $this->assertSame(0, ScholarshipApplication::query()->where('applicant_id', $applicant->id)->count());
        $this->assertArrayNotHasKey('online_url', $payload[0]);
        $this->assertArrayNotHasKey('instructions', $payload[0]);
        $this->assertArrayNotHasKey('instructions', $payload[1]);
    }

    public function test_program_edit_updates_an_existing_planned_date(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Editable Schedule Scholarship',
            'description' => 'A draft program with a distribution schedule.',
            'selection_stages' => ['screening', 'distribution'],
            'status' => 'draft',
        ]);
        $event = ScholarshipEvent::create([
            'scholarship_id' => $scholarship->id,
            'type' => 'distribution',
            'title' => 'Initial distribution schedule',
            'scheduled_at' => now()->addDays(10)->startOfHour(),
            'mode' => 'provider_managed',
            'instructions' => 'Wait for the provider announcement.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
        ]);
        $updatedDate = now()->addDays(15)->setTime(9, 0);

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", [
                'title' => $scholarship->title,
                'description' => $scholarship->description,
                'selection_stages' => json_encode(['distribution']),
                'program_events' => json_encode([[
                    'type' => 'distribution',
                    'title' => 'Updated distribution schedule',
                    'scheduled_at' => $updatedDate->format('Y-m-d H:i:s'),
                    'mode' => 'onsite',
                    'venue' => 'Foundation Office',
                    'location_address' => 'Quezon City',
                    'instructions' => 'Bring a valid ID and award agreement.',
                ]]),
                'status' => 'draft',
                'terms_accepted' => true,
            ])
            ->assertOk()
            ->assertJsonPath('scholarship.program_events.0.id', $event->id)
            ->assertJsonPath('scholarship.program_events.0.title', 'Updated distribution schedule')
            ->assertJsonPath('scholarship.program_events.0.venue', 'Foundation Office');

        $this->assertDatabaseHas('scholarship_events', [
            'id' => $event->id,
            'title' => 'Updated distribution schedule',
            'mode' => 'onsite',
            'venue' => 'Foundation Office',
        ]);
    }

    public function test_applicant_progress_uses_only_the_programs_configured_stages(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'interview',
            'distribution',
        ]);
        ScholarshipEvent::create([
            'scholarship_id' => $scholarship->id,
            'type' => 'interview',
            'title' => 'Shared applicant interview',
            'scheduled_at' => now()->addDays(5),
            'mode' => 'online',
            'online_url' => 'https://example.test/private-interview-room',
            'instructions' => 'Use the private interview link.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
        ]);

        $payload = $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->json('application');

        $this->assertSame(
            ['submitted', 'screening', 'interview', 'handoff', 'distribution'],
            array_column($payload['status_progress']['steps'], 'key'),
        );
        $this->assertSame('screening', $payload['status_progress']['current_stage']);
        $this->assertSame('Shared applicant interview', $payload['scholarship']['program_events'][0]['title']);
        $this->assertArrayNotHasKey('online_url', $payload['scholarship']['program_events'][0]);
        $this->assertArrayNotHasKey('instructions', $payload['scholarship']['program_events'][0]);
    }

    public function test_formal_application_handoff_is_revealed_only_after_portal_qualification(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
        ]);
        $scholarship->update([
            'post_qualification_requirements' => "Original report card\nProvider formal application form",
            'handoff_mode' => 'onsite',
            'handoff_instructions' => 'Bring the originals to the provider office for formal application.',
            'handoff_deadline' => now()->addDays(14)->toDateString(),
            'handoff_location_name' => 'Provider Office',
            'handoff_location_address' => 'Quezon City, Metro Manila',
        ]);

        $beforeQualification = $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.formal_application_handoff', null)
            ->json('application');

        $this->assertArrayNotHasKey('handoff_instructions', $beforeQualification['scholarship']);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Applicant passed portal pre-screening.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved');

        $this->actingAs($applicant)
            ->getJson("/dashboard/applications/{$application->id}/data")
            ->assertOk()
            ->assertJsonPath('application.formal_application_handoff.mode', 'onsite')
            ->assertJsonPath('application.formal_application_handoff.requirements.0', 'Original report card')
            ->assertJsonPath('application.formal_application_handoff.location_address', 'Quezon City, Metro Manila')
            ->assertJsonPath('application.formal_application_handoff.instructions', 'Bring the originals to the provider office for formal application.');

        $plainToken = 'formal-application-mobile-token';
        MobileApiToken::create([
            'user_id' => $applicant->id,
            'name' => 'mobile_app',
            'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $this->withToken($plainToken)
            ->getJson('/api/mobile/profile')
            ->assertOk()
            ->assertJsonPath('applications.0.formal_application_handoff.mode', 'onsite')
            ->assertJsonPath('applications.0.formal_application_handoff.requirements.0', 'Original report card')
            ->assertJsonPath('applications.0.formal_application_handoff.location_address', 'Quezon City, Metro Manila');
    }

    public function test_provider_records_final_outcomes_after_formal_application(): void
    {
        [$provider, $awardedApplicant, $scholarship, $awardedApplication] = $this->applicationWithPlan([
            'screening',
        ], 'approved');
        $notSelectedApplicant = User::factory()->create(['role' => 'applicant']);
        $notSelectedApplication = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $notSelectedApplicant->id,
            'status' => 'approved',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$awardedApplication->id}/status", [
                'status' => 'awarded',
                'decision_reason' => 'approved_for_award',
                'review_notes' => 'Selected after the provider formal application process.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'awarded');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$notSelectedApplication->id}/status", [
                'status' => 'not_awarded',
                'decision_reason' => 'not_selected',
                'review_notes' => 'The formal selection process is complete.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'not_awarded');

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $awardedApplicant->id,
            'type' => 'application_outcome',
            'title' => 'Scholarship award confirmed',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $notSelectedApplicant->id,
            'type' => 'application_outcome',
            'title' => 'Formal application result',
        ]);
        Mail::assertQueued(PortalNotificationMail::class, 2);
    }

    public function test_screening_cannot_be_published_as_an_applicant_schedule(): void
    {
        [$provider, $_applicant, $scholarship, $application] = $this->applicationWithPlan([
            'screening',
            'distribution',
        ], 'submitted');

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'screening',
                'title' => 'Document screening day',
                'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'mode' => 'provider_managed',
                'instructions' => 'Keep your profile and uploaded documents current.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseMissing('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'screening',
        ]);
    }

    private function applicationWithPlan(array $stages, string $status = 'under_review'): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Program Selection Test Scholarship',
            'description' => 'Used to verify program-level selection and scheduling.',
            'selection_stages' => $stages,
            'exam_duration_minutes' => in_array('exam', $stages, true) ? 60 : null,
            'exam_passing_score' => in_array('exam', $stages, true) ? 75 : null,
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);

        return [$provider, $applicant, $scholarship, $application];
    }
}
