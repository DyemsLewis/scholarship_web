<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\ScholarshipEvent;
use App\Models\User;
use App\Services\ScholarshipEventService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderProgramSafeguardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provider_can_save_a_title_only_draft_without_accepting_terms(): void
    {
        $provider = $this->verifiedProvider();

        $response = $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Early Program Draft',
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.status', 'draft');

        $this->assertDatabaseHas('scholarships', [
            'id' => $response->json('scholarship.id'),
            'description' => '',
            'provider_terms_accepted_at' => null,
        ]);
    }

    public function test_provider_can_separate_required_and_supporting_documents(): void
    {
        $provider = $this->verifiedProvider();

        $response = $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Document Levels Draft',
                'requirements' => "Certificate of enrollment\nLatest report card or grades",
                'optional_requirements' => "Good moral certificate\nRecommendation letter",
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('scholarship.optional_requirements', "Good moral certificate\nRecommendation letter");

        $this->assertDatabaseHas('scholarships', [
            'id' => $response->json('scholarship.id'),
            'requirements' => "Certificate of enrollment\nLatest report card or grades",
            'optional_requirements' => "Good moral certificate\nRecommendation letter",
        ]);
    }

    public function test_incomplete_program_cannot_be_submitted_for_review(): void
    {
        $provider = $this->verifiedProvider();

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Incomplete Program',
                'description' => 'This submission intentionally omits its readiness details.',
                'deadline' => now()->addMonth()->toDateString(),
                'status' => 'pending_review',
                'terms_accepted' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'category',
                'benefits',
                'application_mode',
                'eligibility',
                'requirements',
                'post_qualification_requirements',
                'handoff_mode',
                'handoff_instructions',
                'location_name',
                'contact_email',
            ]);

        $this->assertDatabaseMissing('scholarships', [
            'title' => 'Incomplete Program',
        ]);
    }

    public function test_program_submission_rejects_a_past_deadline(): void
    {
        $provider = $this->verifiedProvider();

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', $this->completeSubmissionPayload([
                'deadline' => now()->subDay()->toDateString(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deadline');
    }

    public function test_complete_program_submission_saves_the_formal_application_handoff(): void
    {
        $provider = $this->verifiedProvider();

        $response = $this->actingAs($provider)
            ->postJson('/provider/scholarships', $this->completeSubmissionPayload())
            ->assertCreated()
            ->assertJsonPath('scholarship.status', 'pending_review')
            ->assertJsonPath('scholarship.handoff_mode', 'onsite')
            ->assertJsonPath('scholarship.handoff_location_address', 'Quezon City, Metro Manila');

        $this->assertDatabaseHas('scholarships', [
            'id' => $response->json('scholarship.id'),
            'post_qualification_requirements' => "Original certificate of enrollment\nProvider formal application form",
            'handoff_instructions' => 'Bring the original documents to the scholarship office for the provider formal application.',
        ]);
    }

    public function test_profile_review_program_can_be_submitted_without_initial_document_requirements(): void
    {
        $provider = $this->verifiedProvider();

        $response = $this->actingAs($provider)
            ->postJson('/provider/scholarships', $this->completeSubmissionPayload([
                'title' => 'Profile Review Program',
                'application_mode' => 'provider_review',
                'requirements' => '',
            ]))
            ->assertCreated()
            ->assertJsonPath('scholarship.status', 'pending_review')
            ->assertJsonPath('scholarship.requirements', null);

        $this->assertDatabaseHas('scholarships', [
            'id' => $response->json('scholarship.id'),
            'application_mode' => 'provider_review',
            'requirements' => null,
        ]);
    }

    public function test_grade_point_requirement_cannot_exceed_five(): void
    {
        $provider = $this->verifiedProvider();

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Invalid Grade Point Draft',
                'minimum_grade_scale' => 'grade_point',
                'minimum_gwa' => 90,
                'status' => 'draft',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('minimum_gwa');
    }

    public function test_clearing_a_program_schedule_removes_the_saved_event(): void
    {
        $provider = $this->verifiedProvider();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Schedule Clearing Draft',
            'description' => 'A draft with a schedule that will be cleared.',
            'selection_stages' => ['screening', 'distribution'],
            'status' => 'draft',
        ]);
        $event = ScholarshipEvent::create([
            'scholarship_id' => $scholarship->id,
            'type' => 'distribution',
            'title' => 'Initial distribution schedule',
            'scheduled_at' => now()->addWeek(),
            'mode' => 'provider_managed',
            'instructions' => 'Wait for the provider announcement.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
        ]);

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", [
                'title' => $scholarship->title,
                'description' => $scholarship->description,
                'selection_stages' => json_encode(['distribution']),
                'program_events' => json_encode([]),
                'status' => 'draft',
            ])
            ->assertOk()
            ->assertJsonCount(0, 'scholarship.program_events');

        $this->assertDatabaseMissing('scholarship_events', [
            'id' => $event->id,
        ]);
    }

    public function test_provider_cannot_remove_a_schedule_that_is_active_for_applicants(): void
    {
        $provider = $this->verifiedProvider();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Active Distribution Schedule',
            'description' => 'A program whose release schedule has already reached an applicant.',
            'selection_stages' => ['screening', 'distribution'],
            'status' => 'draft',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'awarded',
            'submitted_at' => now(),
        ]);
        $event = ScholarshipEvent::create([
            'scholarship_id' => $scholarship->id,
            'type' => 'distribution',
            'title' => 'Benefit release',
            'scheduled_at' => now()->addWeek(),
            'mode' => 'provider_managed',
            'instructions' => 'Review the release instructions.',
            'status' => 'scheduled',
            'created_by' => $provider->id,
        ]);

        app(ScholarshipEventService::class)->syncEligibleApplications($event);

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", [
                'title' => $scholarship->title,
                'description' => $scholarship->description,
                'selection_stages' => json_encode(['distribution']),
                'program_events' => json_encode([]),
                'status' => 'draft',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('program_events');

        $this->assertDatabaseHas('scholarship_events', ['id' => $event->id]);
        $this->assertDatabaseHas('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'distribution',
            'status' => 'scheduled',
        ]);
    }

    public function test_a_completed_event_only_reopens_when_a_new_schedule_is_posted(): void
    {
        $provider = $this->verifiedProvider();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'New Intake Distribution',
            'description' => 'A program with an earlier release event already completed.',
            'selection_stages' => ['screening', 'distribution'],
            'status' => 'draft',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'awarded',
            'submitted_at' => now(),
        ]);
        $event = ScholarshipEvent::create([
            'scholarship_id' => $scholarship->id,
            'type' => 'distribution',
            'title' => 'Earlier benefit release',
            'scheduled_at' => now()->subWeek()->startOfHour(),
            'mode' => 'provider_managed',
            'instructions' => 'Earlier release instructions.',
            'status' => 'completed',
            'created_by' => $provider->id,
        ]);

        $this->actingAs($provider)
            ->putJson("/provider/scholarships/{$scholarship->id}", [
                'title' => $scholarship->title,
                'description' => $scholarship->description,
                'selection_stages' => json_encode(['distribution']),
                'program_events' => json_encode([[
                    'type' => 'distribution',
                    'title' => $event->title,
                    'scheduled_at' => $event->scheduled_at->format('Y-m-d H:i:s'),
                    'mode' => $event->mode,
                    'instructions' => $event->instructions,
                ]]),
                'status' => 'draft',
            ])
            ->assertOk();

        $this->assertSame('completed', $event->fresh()->status);
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseMissing('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'distribution',
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/events", [
                'type' => 'distribution',
                'title' => 'New benefit release',
                'scheduled_at' => now()->addWeek()->format('Y-m-d H:i:s'),
                'mode' => 'provider_managed',
                'instructions' => 'Review the new release instructions.',
            ])
            ->assertOk()
            ->assertJsonPath('event.status', 'scheduled')
            ->assertJsonPath('audience_count', 1);

        $this->assertSame('distribution_scheduled', $application->fresh()->status);
        $this->assertDatabaseHas('application_schedules', [
            'scholarship_application_id' => $application->id,
            'type' => 'distribution',
            'title' => 'New benefit release',
        ]);
    }

    public function test_program_schedules_must_follow_the_selection_order(): void
    {
        $provider = $this->verifiedProvider();

        $this->actingAs($provider)
            ->postJson('/provider/scholarships', [
                'title' => 'Out of Order Schedule Draft',
                'selection_stages' => json_encode(['exam', 'interview']),
                'program_events' => json_encode([
                    [
                        'type' => 'exam',
                        'scheduled_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
                        'mode' => 'provider_managed',
                        'instructions' => 'Wait for the exam instructions.',
                    ],
                    [
                        'type' => 'interview',
                        'scheduled_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
                        'mode' => 'provider_managed',
                        'instructions' => 'Wait for the interview instructions.',
                    ],
                ]),
                'status' => 'draft',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('program_events.1.scheduled_at');
    }

    public function test_pre_screening_can_qualify_multiple_applicants_but_awards_respect_available_slots(): void
    {
        $provider = $this->verifiedProvider();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Single Slot Scholarship',
            'description' => 'Only one applicant can receive a final award.',
            'slots_available' => 1,
            'status' => 'published',
        ]);
        $firstApplication = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $secondApplication = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$firstApplication->id}/status", [
                'status' => 'approved',
                'decision_reason' => 'qualified_for_formal_application',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$secondApplication->id}/status", [
                'status' => 'approved',
                'decision_reason' => 'qualified_for_formal_application',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$firstApplication->id}/status", [
                'status' => 'awarded',
                'decision_reason' => 'approved_for_award',
            ])
            ->assertOk();

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$secondApplication->id}/status", [
                'status' => 'awarded',
                'decision_reason' => 'approved_for_award',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->assertSame('approved', $secondApplication->fresh()->status);
    }

    private function verifiedProvider(): User
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);

        return $provider;
    }

    private function completeSubmissionPayload(array $overrides = []): array
    {
        return [
            'title' => 'Complete Scholarship Program',
            'category' => 'Financial assistance',
            'description' => 'A complete program ready for administrator review.',
            'eligibility' => 'Open to enrolled college students who meet the document requirements.',
            'eligible_education_levels' => 'college',
            'eligible_locations' => 'Metro Manila',
            'requirements' => 'Certificate of enrollment',
            'post_qualification_requirements' => "Original certificate of enrollment\nProvider formal application form",
            'benefits' => json_encode([[
                'type' => 'school_supplies',
                'title' => 'School essentials kit',
                'frequency' => 'one_time',
            ]]),
            'application_mode' => 'online',
            'handoff_mode' => 'onsite',
            'handoff_instructions' => 'Bring the original documents to the scholarship office for the provider formal application.',
            'handoff_location_name' => 'Community Scholarship Office',
            'handoff_location_address' => 'Quezon City, Metro Manila',
            'location_name' => 'Community Scholarship Office',
            'location_address' => 'Quezon City, Metro Manila',
            'latitude' => 14.6760,
            'longitude' => 121.0437,
            'contact_email' => 'scholarships@example.test',
            'deadline' => now()->addMonth()->toDateString(),
            'status' => 'pending_review',
            'terms_accepted' => true,
            ...$overrides,
        ];
    }
}
