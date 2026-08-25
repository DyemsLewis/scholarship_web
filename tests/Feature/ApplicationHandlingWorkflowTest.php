<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ApplicationHandlingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_applicant_can_withdraw_an_active_application(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = $this->application($this->program($provider), $applicant, 'under_review');

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/withdraw", [
                'reason' => 'I need to correct my school enrollment before applying again.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'withdrawn')
            ->assertJsonPath('application.can_withdraw', false);

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'withdrawn',
            'withdrawn_by' => $applicant->id,
        ]);
        $this->assertDatabaseHas('application_status_histories', [
            'scholarship_application_id' => $application->id,
            'from_status' => 'under_review',
            'to_status' => 'withdrawn',
            'decision_reason' => 'applicant_withdrawal',
        ]);
        $this->assertTrue(PortalNotification::query()
            ->where('user_id', $provider->id)
            ->where('type', 'application_withdrawn')
            ->exists());
    }

    public function test_provider_and_applicant_can_complete_a_correction_cycle(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = $this->application($this->program($provider), $applicant, 'under_review');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/correction", [
                'action' => 'request',
                'message' => 'Replace the unreadable grade record and confirm your current grade level.',
            ])
            ->assertOk()
            ->assertJsonPath('application.correction_status', 'requested');

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/correction-response", [
                'response' => 'I replaced the grade record and updated my grade level.',
            ])
            ->assertOk()
            ->assertJsonPath('application.correction_status', 'submitted')
            ->assertJsonPath('application.status', 'under_review');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$application->id}/correction", [
                'action' => 'resolve',
            ])
            ->assertOk()
            ->assertJsonPath('application.correction_status', 'resolved');

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $application->id,
            'status' => 'under_review',
            'correction_status' => 'resolved',
            'correction_response' => 'I replaced the grade record and updated my grade level.',
        ]);
    }

    public function test_provider_can_rank_and_promote_waitlisted_alternates(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = $this->program($provider);
        $first = $this->application($program, User::factory()->create(['role' => 'applicant']), 'approved');
        $second = $this->application($program, User::factory()->create(['role' => 'applicant']), 'approved');

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$first->id}/waitlist", ['action' => 'waitlist'])
            ->assertOk()
            ->assertJsonPath('application.status', 'waitlisted')
            ->assertJsonPath('application.waitlist_position', 1);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$second->id}/waitlist", ['action' => 'waitlist'])
            ->assertOk()
            ->assertJsonPath('application.status', 'waitlisted')
            ->assertJsonPath('application.waitlist_position', 2);

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$first->id}/waitlist", [
                'action' => 'promote',
                'note' => 'An award slot became available.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'awarded')
            ->assertJsonPath('application.waitlist_position', null);

        $this->assertDatabaseHas('scholarship_applications', [
            'id' => $second->id,
            'status' => 'waitlisted',
            'waitlist_position' => 2,
        ]);
    }

    private function program(User $provider): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Community Scholarship Program',
            'description' => 'A scholarship used to test applicant handling workflows.',
            'selection_stages' => ['screening', 'distribution'],
            'award_slots' => 2,
            'status' => 'published',
        ]);
    }

    private function application(Scholarship $program, User $applicant, string $status): ScholarshipApplication
    {
        return ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }
}
