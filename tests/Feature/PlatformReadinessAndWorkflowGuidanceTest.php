<?php

namespace Tests\Feature;

use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformReadinessAndWorkflowGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_identifies_who_must_act_next_for_both_portal_views(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create();
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Guided Workflow Scholarship',
            'description' => 'Used to verify clear next-action ownership.',
            'status' => 'published',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'deadline' => now()->addMonth()->toDateString(),
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $workflow = app(ApplicationWorkflowService::class);

        $application = $workflow->start($application);
        $initial = $workflow->payload($application);

        $this->assertSame('provider', $initial['next_action']['actor']);
        $this->assertSame('Scholarship provider', $initial['next_action']['actor_label']);
        $this->assertSame('provider', $initial['provider_action']['actor']);
        $this->assertNotEmpty($initial['next_action']['description']);
        $this->assertNotEmpty($initial['provider_action']['description']);

        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $formalApplication = $workflow->payload($application);

        $this->assertSame('formal_application', $formalApplication['current_stage']);
        $this->assertSame('applicant', $formalApplication['next_action']['actor']);
        $this->assertSame('Applicant', $formalApplication['next_action']['actor_label']);
        $this->assertSame('provider', $formalApplication['provider_action']['actor']);
        $this->assertSame('Record the formal application result', $formalApplication['provider_action']['label']);
    }

    public function test_readiness_command_reports_local_warnings_without_changing_data(): void
    {
        $this->artisan('platform:readiness')
            ->expectsOutputToContain('Scholarship platform readiness audit')
            ->expectsOutputToContain('Database connection')
            ->expectsOutputToContain('Local development can continue')
            ->assertSuccessful();
    }
}
