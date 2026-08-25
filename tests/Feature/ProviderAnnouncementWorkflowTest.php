<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderAnnouncementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_provider_can_announce_to_qualified_and_waitlisted_applicants(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = $this->program($provider);
        $qualified = $this->application($scholarship, 'approved');
        $waitlisted = $this->application($scholarship, 'waitlisted');
        $this->application($scholarship, 'submitted');
        $rejected = $this->application($scholarship, 'rejected');

        $response = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/announcements", [
                'audience' => 'qualified_applicants',
                'title' => 'Formal application reminder',
                'message' => 'Please review the next-step instructions in your application record.',
            ])
            ->assertCreated()
            ->assertJsonPath('announcement.recipient_count', 2)
            ->assertJsonPath('announcement.audience_label', 'Qualified applicants and alternates');

        $announcementId = $response->json('announcement.id');

        $this->assertDatabaseHas('scholarship_announcements', [
            'id' => $announcementId,
            'scholarship_id' => $scholarship->id,
            'published_by' => $provider->id,
            'recipient_count' => 2,
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $qualified->applicant_id,
            'type' => 'program_announcement',
            'title' => 'Formal application reminder',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $waitlisted->applicant_id,
            'type' => 'program_announcement',
            'title' => 'Formal application reminder',
        ]);
        $this->assertDatabaseMissing('portal_notifications', [
            'user_id' => $rejected->applicant_id,
            'type' => 'program_announcement',
        ]);

        $this->actingAs($provider)
            ->getJson("/provider/scholarships/{$scholarship->id}")
            ->assertOk()
            ->assertJsonPath('scholarship.announcements.0.id', $announcementId)
            ->assertJsonPath('scholarship.announcements.0.publisher', $provider->name);
    }

    public function test_provider_cannot_publish_to_an_empty_audience(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $scholarship = $this->program($provider);
        $this->application($scholarship, 'submitted');

        $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$scholarship->id}/announcements", [
                'audience' => 'selected_recipients',
                'title' => 'Award release update',
                'message' => 'Please review the award release instructions in your application.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('audience');
    }

    public function test_provider_cannot_announce_for_another_organization(): void
    {
        $owner = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $scholarship = $this->program($owner);
        $this->application($scholarship, 'submitted');

        $this->actingAs($otherProvider)
            ->postJson("/provider/scholarships/{$scholarship->id}/announcements", [
                'audience' => 'active_applicants',
                'title' => 'Program update',
                'message' => 'This provider does not own the selected scholarship program.',
            ])
            ->assertForbidden();
    }

    private function program(User $provider): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Community Learning Grant',
            'description' => 'A program used to test provider announcements.',
            'status' => 'published',
        ]);
    }

    private function application(Scholarship $scholarship, string $status): ScholarshipApplication
    {
        return ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => $status,
            'submitted_at' => now(),
        ]);
    }
}
