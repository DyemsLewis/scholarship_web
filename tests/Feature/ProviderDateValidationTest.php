<?php

namespace Tests\Feature;

use App\Models\RecipientMonitoringCase;
use App\Models\RecipientMonitoringPlan;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\ApplicationWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderDateValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-16 14:30:00', 'Asia/Manila'));
    }

    private function program(): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Date safeguards', 'description' => 'Date checks.', 'status' => 'published', 'selection_stages' => ['screening', 'formal_application', 'decision']]);
        $this->actingAs($provider);
        return [$provider, $program];
    }

    private function planPayload(array $values = []): array
    {
        return [...['frequency' => 'semester', 'starts_on' => now()->subWeek()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(), 'grace_period_days' => 7, 'allow_exception_requests' => true, 'status' => 'draft', 'requirements' => []], ...$values];
    }

    private function recipient(User $provider, Scholarship $program): ScholarshipApplication
    {
        $applicant = User::factory()->create(['role' => 'applicant']);
        $application = ScholarshipApplication::create(['scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'submitted', 'submitted_at' => now(), 'document_checklist' => []]);
        $workflow = app(ApplicationWorkflowService::class);
        $application = $workflow->start($application);
        $application = $workflow->recordStageResult($application, 'screening', 'passed', $provider);
        $application = $workflow->recordStageResult($application, 'formal_application', 'passed', $provider);
        $application = $workflow->recordFinalOutcome($application, 'selected', $provider);
        $application->update(['student_response_status' => 'accepted', 'student_responded_at' => now(), 'provider_contract_terms_accepted_at' => now()]);
        return $application;
    }

    public function test_new_expired_monitoring_plans_are_rejected_but_ongoing_periods_remain_valid(): void
    {
        [, $program] = $this->program();
        $url = "/provider/scholarships/{$program->id}/monitoring-plan";
        foreach (['draft', 'active'] as $status) {
            $this->putJson($url, $this->planPayload(['status' => $status, 'ends_on' => now()->subDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        }
        $this->assertDatabaseCount('recipient_monitoring_plans', 0);
        $this->putJson($url, $this->planPayload())->assertOk();
    }

    public function test_monitoring_dates_use_program_bounds_and_cannot_exclude_existing_rescheduled_appointments(): void
    {
        [$provider, $program] = $this->program();
        $program->update(['support_starts_at' => now()->subMonth(), 'support_ends_at' => now()->addMonths(2)]);
        $url = "/provider/scholarships/{$program->id}/monitoring-plan";
        $this->putJson($url, $this->planPayload(['starts_on' => now()->subMonths(2)->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('starts_on');
        $this->putJson($url, $this->planPayload(['ends_on' => now()->addMonths(3)->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->putJson($url, $this->planPayload())->assertOk();
        $plan = $program->monitoringPlan()->firstOrFail();
        $cycle = $plan->cycles()->create(['scholarship_id' => $program->id, 'created_by' => $provider->id, 'title' => 'Booked check-in', 'period_type' => 'custom', 'due_at' => now()->addWeek(), 'handoff_at' => now()->addWeek(), 'minimum_grade' => 85, 'grading_scale' => 'percentage', 'status' => 'open']);
        $application = $this->recipient($provider, $program);
        RecipientMonitoringCase::create(['recipient_monitoring_cycle_id' => $cycle->id, 'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id, 'status' => 'awaiting_submission', 'appointment_at' => now()->addWeeks(2)]);
        $before = $plan->fresh()->getAttributes();
        $this->putJson($url, $this->planPayload(['ends_on' => now()->addDays(10)->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->assertSame($before, $plan->fresh()->getAttributes());
    }

    public function test_existing_expired_active_plan_keeps_its_unchanged_historical_dates(): void
    {
        [$provider, $program] = $this->program();
        $payload = $this->planPayload(['starts_on' => now()->subMonths(2)->toDateString(), 'ends_on' => now()->subMonth()->toDateString()]);
        RecipientMonitoringPlan::create(['scholarship_id' => $program->id, 'created_by' => $provider->id, 'status' => 'active', 'frequency' => 'semester', 'starts_on' => $payload['starts_on'], 'ends_on' => $payload['ends_on']]);
        $this->putJson("/provider/scholarships/{$program->id}/monitoring-plan", $payload)->assertOk();
    }

    public function test_benefit_release_cannot_be_scheduled_earlier_today_or_at_the_current_time(): void
    {
        [$provider, $program] = $this->program();
        $application = $this->recipient($provider, $program);
        $payload = ['title' => 'Learning allowance', 'benefit_description' => 'Learning assistance', 'release_method' => 'other', 'requires_original_verification' => false, 'recipient_ids' => [$application->id]];
        foreach ([now()->subMinute(), now()] as $time) {
            $this->postJson("/provider/scholarships/{$program->id}/benefit-releases", [...$payload, 'release_at' => $time->format('Y-m-d\TH:i')])->assertUnprocessable()->assertJsonValidationErrors('release_at');
        }
        $this->assertDatabaseCount('recipient_benefit_releases', 0);
        $this->postJson("/provider/scholarships/{$program->id}/benefit-releases", [...$payload, 'release_at' => now()->addMinute()->format('Y-m-d\TH:i')])->assertCreated();
    }

    public function test_renewal_can_be_effective_in_the_past_but_its_new_end_and_review_cannot_already_be_expired(): void
    {
        [$provider, $program] = $this->program();
        $application = $this->recipient($provider, $program);
        $payload = ['decision' => 'renewed', 'reason_category' => 'continued_funding', 'effective_on' => now()->subWeek()->toDateString(), 'support_ends_on' => now()->addMonth()->toDateString(), 'next_review_on' => now()->addWeek()->toDateString(), 'next_period_terms' => 'Continue the agreed recipient requirements.', 'confirmed' => true];
        foreach (['support_ends_on', 'next_review_on'] as $field) {
            $this->postJson("/provider/applications/{$application->id}/support-decision", [...$payload, $field => now()->subDay()->toDateString()])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson("/provider/applications/{$application->id}/support-decision", $payload)->assertOk();
    }
}
