<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderApplicationDecisionNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_decision_response_points_to_the_next_reviewable_applicant_in_the_same_program(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = $this->program($provider, 'Primary program');
        $otherProgram = $this->program($provider, 'Other program');
        $current = $this->application($program, 'submitted', now()->subMinutes(3));
        $next = $this->application($program, 'under_review', now()->subMinutes(2));
        $this->application($program, 'approved', now()->subMinute());
        $this->application($otherProgram, 'submitted', now());

        $this->actingAs($provider)
            ->patchJson("/provider/applications/{$current->id}/decision", [
                'decision' => 'approve',
                'review_notes' => 'Ready for the configured next stage.',
            ])
            ->assertOk()
            ->assertJsonPath('application.status', 'approved')
            ->assertJsonPath('review_navigation.remaining_count', 1)
            ->assertJsonPath('review_navigation.next_application.id', $next->id)
            ->assertJsonPath('review_navigation.next_application.applicant_name', $next->applicant->name)
            ->assertJsonPath('review_navigation.next_application.url', "/provider/applications/{$next->id}")
            ->assertJsonPath('review_navigation.list_url', "/provider/applications?scholarship_id={$program->id}");
    }

    public function test_application_detail_returns_neighboring_records_only_from_the_same_program(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = $this->program($provider, 'Primary program');
        $otherProgram = $this->program($provider, 'Other program');
        $older = $this->application($program, 'submitted', now()->subMinutes(3));
        $current = $this->application($program, 'submitted', now()->subMinutes(2));
        $newer = $this->application($program, 'submitted', now()->subMinute());
        $this->application($otherProgram, 'submitted', now());

        $this->actingAs($provider)
            ->getJson("/provider/applications/{$current->id}/data")
            ->assertOk()
            ->assertJsonPath('application_navigation.position', 2)
            ->assertJsonPath('application_navigation.total', 3)
            ->assertJsonPath('application_navigation.previous_application.id', $newer->id)
            ->assertJsonPath('application_navigation.previous_application.url', "/provider/applications/{$newer->id}")
            ->assertJsonPath('application_navigation.next_application.id', $older->id)
            ->assertJsonPath('application_navigation.next_application.url', "/provider/applications/{$older->id}");
    }

    private function program(User $provider, string $title): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => $title,
            'description' => 'Program used to test the provider review continuation flow.',
            'selection_stages' => ['screening', 'distribution'],
            'status' => 'published',
        ]);
    }

    private function application(Scholarship $program, string $status, $submittedAt): ScholarshipApplication
    {
        return ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => User::factory()->create(['role' => 'applicant'])->id,
            'status' => $status,
            'submitted_at' => $submittedAt,
        ]);
    }
}
