<?php

namespace Tests\Feature;

use App\Domain\Recipients\RecipientSupportCatalog;
use App\Models\RecipientMonitoringCase;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\RecipientMonitoringCaseService;
use App\Services\ScholarshipBenefitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecipientSupportLifecycleFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_support_mode_is_derived_without_forcing_one_time_awards_into_monitoring(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Learning Starter Award',
            'description' => 'A one-time education support package.',
            'status' => 'draft',
        ]);
        $benefits = app(ScholarshipBenefitService::class);

        $benefits->sync($program, [[
            'type' => 'cash_grant',
            'title' => 'Starter grant',
            'amount' => 5000,
            'coverage' => null,
            'frequency' => 'one_time',
            'duration' => 'Current program cycle',
            'description' => null,
            'sort_order' => 0,
        ]]);

        $program->refresh();
        $this->assertSame(RecipientSupportCatalog::SUPPORT_ONE_TIME, $program->support_mode);
        $this->assertSame(RecipientSupportCatalog::MONITORING_NONE, $program->monitoring_policy);

        $benefits->sync($program, [
            [
                'type' => 'cash_grant',
                'title' => 'Starter grant',
                'amount' => 5000,
                'coverage' => null,
                'frequency' => 'one_time',
                'duration' => 'Current program cycle',
                'description' => null,
                'sort_order' => 0,
            ],
            [
                'type' => 'mentorship',
                'title' => 'Monthly mentoring',
                'amount' => null,
                'coverage' => null,
                'frequency' => 'monthly',
                'duration' => 'Six months',
                'description' => null,
                'sort_order' => 1,
            ],
        ]);

        $program->refresh();
        $this->assertSame(RecipientSupportCatalog::SUPPORT_MIXED, $program->support_mode);
        $this->assertSame(RecipientSupportCatalog::MONITORING_RECURRING, $program->monitoring_policy);
    }

    public function test_publishing_a_check_in_creates_a_case_that_activates_after_agreement(): void
    {
        [$provider, $applicant, $program, $application] = $this->selectedApplication();

        $response = $this->actingAs($provider)
            ->postJson("/provider/scholarships/{$program->id}/monitoring-cycles", [
                'title' => 'First semester progress check',
                'period_type' => 'semester',
                'academic_period' => 'First semester',
                'school_year' => '2026-2027',
                'opens_at' => now()->toDateString(),
                'due_at' => now()->addDays(14)->toDateString(),
                'minimum_grade' => 85,
                'grading_scale' => 'percentage',
                'instructions' => 'Submit the current official grade record.',
            ])
            ->assertCreated();

        $case = RecipientMonitoringCase::query()
            ->where('recipient_monitoring_cycle_id', $response->json('cycle.id'))
            ->where('scholarship_application_id', $application->id)
            ->firstOrFail();
        $this->assertSame(RecipientSupportCatalog::CASE_AWAITING_AGREEMENT, $case->status);

        $this->actingAs($applicant)
            ->patchJson("/dashboard/applications/{$application->id}/response", [
                'response' => 'accepted',
                'terms_accepted' => true,
            ])
            ->assertOk();

        $this->assertSame(
            RecipientSupportCatalog::CASE_AWAITING_SUBMISSION,
            $case->fresh()->status,
        );
    }

    public function test_monitoring_case_requires_valid_transitions_and_an_outcome_before_closure(): void
    {
        [$provider, , $program, $application] = $this->selectedApplication(agreementAccepted: true);
        $cycle = $program->monitoringCycles()->create([
            'created_by' => $provider->id,
            'title' => 'Semester review',
            'period_type' => 'semester',
            'due_at' => now()->addMonth()->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'open',
            'published_at' => now(),
        ]);
        $service = app(RecipientMonitoringCaseService::class);
        $case = $service->seedForCycle($cycle, collect([$application]))->first();

        try {
            $service->transition($case, RecipientSupportCatalog::CASE_CLOSED, $provider);
            $this->fail('An unresolved case should not close directly.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $case = $service->transition($case, RecipientSupportCatalog::CASE_SUBMITTED);
        $case = $service->transition($case, RecipientSupportCatalog::CASE_UNDER_REVIEW, $provider);
        $case = $service->transition($case, RecipientSupportCatalog::CASE_READY_TO_CLOSE, $provider);

        $this->expectException(ValidationException::class);
        $service->transition($case, RecipientSupportCatalog::CASE_CLOSED, $provider);
    }

    public function test_closed_monitoring_case_can_be_reopened_with_an_audit_actor(): void
    {
        [$provider, , $program, $application] = $this->selectedApplication(agreementAccepted: true);
        $cycle = $program->monitoringCycles()->create([
            'created_by' => $provider->id,
            'title' => 'Semester review',
            'period_type' => 'semester',
            'due_at' => now()->addMonth()->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'open',
            'published_at' => now(),
        ]);
        $service = app(RecipientMonitoringCaseService::class);
        $case = $service->seedForCycle($cycle, collect([$application]))->first();
        $case = $service->transition($case, RecipientSupportCatalog::CASE_SUBMITTED);
        $case = $service->transition($case, RecipientSupportCatalog::CASE_UNDER_REVIEW, $provider);
        $case = $service->transition($case, RecipientSupportCatalog::CASE_READY_TO_CLOSE, $provider);
        $case = $service->transition(
            $case,
            RecipientSupportCatalog::CASE_CLOSED,
            $provider,
            RecipientSupportCatalog::OUTCOME_COMPLETED,
        );

        $this->assertTrue($case->isClosed());
        $this->assertNotNull($case->closed_at);
        $this->assertSame($provider->id, $case->closed_by);

        $case = $service->transition(
            $case,
            RecipientSupportCatalog::CASE_UNDER_REVIEW,
            $provider,
            reason: 'A provider review correction was required.',
        );

        $this->assertFalse($case->isClosed());
        $this->assertNull($case->outcome);
        $this->assertNull($case->closed_at);
        $this->assertSame($provider->id, $case->reopened_by);
        $this->assertNotNull($case->reopened_at);
    }

    private function selectedApplication(bool $agreementAccepted = false): array
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $program = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Continuing Education Grant',
            'description' => 'A scholarship used to verify recipient lifecycle behavior.',
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'final_outcome' => 'selected',
            'document_checklist' => [],
            'provider_contract_terms_snapshot' => [
                'responsibilities' => 'Submit required records during the support period.',
            ],
            'provider_contract_terms_version' => '2026-10-18',
            'student_response_status' => $agreementAccepted ? 'accepted' : null,
            'student_responded_at' => $agreementAccepted ? now() : null,
            'student_response_terms_accepted_at' => $agreementAccepted ? now() : null,
            'submitted_at' => now()->subMonth(),
        ]);

        return [$provider, $applicant, $program, $application];
    }
}
