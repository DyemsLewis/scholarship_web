<?php

namespace Tests\Feature;

use App\Models\RecipientMonitoringPlan;
use App\Models\RecipientSupportDecision;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicantMonitoringDetailContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_contract_returns_a_focused_overview_and_one_next_action(): void
    {
        [, $applicant, $scholarship, $application, $plan] = $this->selectedRecipient();

        $structured = $this->cycle($scholarship, $plan, [
            'title' => 'First semester progress check',
            'due_at' => now()->addWeeks(2)->toDateString(),
        ]);
        $structured->requirements()->create([
            'type' => 'enrollment',
            'title' => 'Current enrollment record',
            'required' => true,
            'requires_file' => true,
            'requires_original_verification' => false,
            'sort_order' => 0,
        ]);

        $this->cycle($scholarship, $plan, [
            'title' => 'Earlier academic update',
            'due_at' => now()->addMonth()->toDateString(),
        ]);

        $response = $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application));

        $response
            ->assertOk()
            ->assertJsonPath('detail.version', 1)
            ->assertJsonPath('detail.view', 'overview')
            ->assertJsonPath('detail.identity.program.title', 'Harborlight Continuing Scholars Program')
            ->assertJsonPath('detail.identity.provider.name', 'Harborlight Education Foundation')
            ->assertJsonPath('detail.status.mode', 'recurring')
            ->assertJsonPath('detail.decision.state', 'check_in_required')
            ->assertJsonPath('detail.decision.actor', 'applicant')
            ->assertJsonPath('detail.decision.action.href', route('dashboard.monitoring.check-ins.show', [
                'application' => $application,
                'record' => 'check-in-'.$structured->id,
            ], false))
            ->assertJsonPath('detail.summary.check_ins.total', 2)
            ->assertJsonCount(4, 'detail.navigation')
            ->assertJsonPath('detail.navigation.1.label', 'Check-ins')
            ->assertJsonPath('detail.content.type', 'overview')
            ->assertJsonPath('detail.content.plan.mode', 'recurring')
            ->assertJsonPath('detail.content.next_check_in.href', route('dashboard.monitoring.check-ins.show', [
                'application' => $application,
                'record' => 'check-in-'.$structured->id,
            ], false))
            ->assertJsonMissingPath('application');
    }

    public function test_monitoring_sections_have_direct_page_routes_and_scoped_contract_content(): void
    {
        [, $applicant, $scholarship, $application, $plan] = $this->selectedRecipient();
        $cycle = $this->cycle($scholarship, $plan);
        $cycle->requirements()->create([
            'type' => 'academic_progress',
            'title' => 'Semester grades',
            'required' => true,
            'requires_file' => true,
            'requires_original_verification' => true,
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'sort_order' => 0,
        ]);

        foreach (['check-ins', 'benefits', 'history'] as $view) {
            $this->actingAs($applicant)
                ->get(route('dashboard.monitoring.show.section', [
                    'application' => $application,
                    'section' => $view,
                ]))
                ->assertOk()
                ->assertSee('data-monitoring-section="'.$view.'"', false);

            $this->actingAs($applicant)
                ->getJson(route('dashboard.monitoring.show.data', $application).'?view='.$view)
                ->assertOk()
                ->assertJsonPath('detail.view', $view)
                ->assertJsonPath('detail.content.type', str_replace('-', '_', $view))
                ->assertJsonPath('detail.navigation.0.key', 'overview');
        }
    }

    public function test_legacy_academic_cycles_are_normalized_with_structured_check_ins(): void
    {
        [, $applicant, $scholarship, $application, $plan] = $this->selectedRecipient();
        $structured = $this->cycle($scholarship, $plan, ['title' => 'Enrollment check']);
        $structured->requirements()->create([
            'type' => 'enrollment',
            'title' => 'Proof of enrollment',
            'required' => true,
            'requires_file' => true,
            'requires_original_verification' => false,
            'sort_order' => 0,
        ]);
        $this->cycle($scholarship, $plan, [
            'title' => 'Academic grade update',
            'academic_period' => 'First semester',
        ]);

        $records = $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=check-ins')
            ->assertOk()
            ->json('detail.content.active');

        $records = collect($records);

        $this->assertCount(2, $records);
        $this->assertTrue($records->contains(fn (array $record): bool => $record['record_type'] === 'checklist'));
        $this->assertTrue($records->contains(fn (array $record): bool => $record['record_type'] === 'academic_record'));
        $this->assertTrue($records->every(fn (array $record): bool => isset($record['record_key'], $record['state'], $record['requirements'])));
    }

    public function test_check_in_directory_opens_one_focused_record_by_direct_url(): void
    {
        [, $applicant, $scholarship, $application, $plan] = $this->selectedRecipient();
        $cycle = $this->cycle($scholarship, $plan, ['title' => 'First semester progress check']);
        $cycle->requirements()->create([
            'type' => 'academic_progress',
            'title' => 'Semester grades',
            'required' => true,
            'requires_file' => true,
            'requires_original_verification' => true,
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'sort_order' => 0,
        ]);
        $recordKey = 'check-in-'.$cycle->id;

        $this->actingAs($applicant)
            ->get(route('dashboard.monitoring.check-ins.show', [
                'application' => $application,
                'record' => $recordKey,
            ]))
            ->assertOk()
            ->assertSee('data-monitoring-section="check-ins"', false)
            ->assertSee('data-monitoring-record="'.$recordKey.'"', false);

        $response = $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=check-ins&record='.$recordKey);

        $response
            ->assertOk()
            ->assertJsonPath('detail.content.selected.record_key', $recordKey)
            ->assertJsonPath('detail.content.selected.title', 'First semester progress check')
            ->assertJsonPath('detail.content.selected.requirements.0.title', 'Semester grades')
            ->assertJsonPath('detail.content.directory.active.0.record_key', $recordKey)
            ->assertJsonPath('detail.content.directory.active.0.pending_items', 1)
            ->assertJsonPath('detail.content.directory.active.0.href', route('dashboard.monitoring.check-ins.show', [
                'application' => $application,
                'record' => $recordKey,
            ], false));

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=check-ins&record=check-in-999999')
            ->assertNotFound();
    }

    public function test_one_time_benefit_has_an_explicit_monitoring_mode_and_check_in_empty_state(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipient(withPlan: false);
        $release = $scholarship->benefitReleases()->create([
            'created_by' => $provider->id,
            'title' => 'School supplies allowance',
            'release_at' => now()->addWeek(),
            'benefit_description' => 'One-time allowance for school supplies.',
            'amount' => 2500,
            'release_method' => 'bank_transfer',
            'requires_original_verification' => false,
            'status' => 'scheduled',
            'published_at' => now(),
        ]);
        $release->records()->create([
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'status' => 'scheduled',
            'originals_verified' => false,
        ]);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application))
            ->assertOk()
            ->assertJsonPath('detail.status.mode', 'one_time')
            ->assertJsonPath('detail.content.plan.mode', 'one_time')
            ->assertJsonPath('detail.decision.state', 'benefit_scheduled');

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=check-ins')
            ->assertOk()
            ->assertJsonPath('detail.content.empty.key', 'one_time_benefit')
            ->assertJsonPath('detail.content.empty.title', 'No recurring check-ins required');
    }

    public function test_support_only_record_has_calm_empty_states_without_fake_schedules(): void
    {
        [, $applicant, , $application] = $this->selectedRecipient(withPlan: false);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application))
            ->assertOk()
            ->assertJsonPath('detail.status.mode', 'support_only')
            ->assertJsonPath('detail.status.mode_label', 'Support record')
            ->assertJsonPath('detail.decision.state', 'no_action')
            ->assertJsonPath('detail.content.plan.mode', 'not_scheduled')
            ->assertJsonPath('detail.content.next_check_in', null)
            ->assertJsonPath('detail.content.next_release', null);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=check-ins')
            ->assertOk()
            ->assertJsonPath('detail.content.empty.key', 'not_scheduled');

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=benefits')
            ->assertOk()
            ->assertJsonPath('detail.content.empty', true);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=history')
            ->assertOk()
            ->assertJsonPath('detail.content.empty', true);
    }

    public function test_benefit_directory_opens_one_focused_release_by_direct_url(): void
    {
        [$provider, $applicant, $scholarship, $application] = $this->selectedRecipient(withPlan: false);
        $release = $scholarship->benefitReleases()->create([
            'created_by' => $provider->id,
            'title' => 'Learning materials allowance',
            'release_at' => now()->subDay(),
            'benefit_description' => 'Allowance for books and school supplies.',
            'amount' => 3500,
            'release_method' => 'bank_transfer',
            'requires_original_verification' => false,
            'status' => 'completed',
            'published_at' => now()->subDays(2),
        ]);
        $record = $release->records()->create([
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'status' => 'released',
            'originals_verified' => false,
            'released_at' => now()->subDay(),
            'recorded_at' => now()->subDay(),
            'recorded_by' => $provider->id,
        ]);
        $recordKey = 'release-'.$record->id;

        $this->actingAs($applicant)
            ->get(route('dashboard.monitoring.benefits.show', [
                'application' => $application,
                'record' => $recordKey,
            ]))
            ->assertOk()
            ->assertSee('data-monitoring-section="benefits"', false)
            ->assertSee('data-monitoring-record="'.$recordKey.'"', false);

        $response = $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=benefits&record='.$recordKey);

        $response
            ->assertOk()
            ->assertJsonPath('detail.content.selected.record_key', $recordKey)
            ->assertJsonPath('detail.content.selected.title', 'Learning materials allowance')
            ->assertJsonPath('detail.content.selected.can_respond', true)
            ->assertJsonPath('detail.content.directory.action_required.0.record_key', $recordKey)
            ->assertJsonPath('detail.decision.state', 'benefit_confirmation_required')
            ->assertJsonPath('detail.decision.action.href', route('dashboard.monitoring.benefits.show', [
                'application' => $application,
                'record' => $recordKey,
            ], false));

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=benefits&record=release-999999')
            ->assertNotFound();
    }

    public function test_support_history_opens_one_focused_outcome_and_only_latest_can_be_answered(): void
    {
        [$provider, $applicant, , $application] = $this->selectedRecipient(withPlan: false);
        $older = RecipientSupportDecision::create([
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'decision' => 'renewed',
            'reason_category' => 'requirements_met',
            'effective_on' => now()->subMonth()->toDateString(),
            'reason' => 'The previous monitoring period was completed.',
            'decided_by' => $provider->id,
            'decided_at' => now()->subMonth(),
            'response_status' => 'pending',
        ]);
        $latest = RecipientSupportDecision::create([
            'scholarship_application_id' => $application->id,
            'applicant_id' => $applicant->id,
            'decision' => 'terminated',
            'reason_category' => 'requirement_not_met',
            'effective_on' => now()->toDateString(),
            'reason' => 'A required record remained unresolved after follow-up.',
            'decided_by' => $provider->id,
            'decided_at' => now(),
            'response_status' => 'pending',
        ]);
        $recordKey = 'outcome-'.$latest->id;

        $this->actingAs($applicant)
            ->get(route('dashboard.monitoring.history.show', [
                'application' => $application,
                'record' => $recordKey,
            ]))
            ->assertOk()
            ->assertSee('data-monitoring-section="history"', false)
            ->assertSee('data-monitoring-record="'.$recordKey.'"', false);

        $response = $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=history&record='.$recordKey);

        $response
            ->assertOk()
            ->assertJsonPath('detail.content.selected.record_key', $recordKey)
            ->assertJsonPath('detail.content.selected.decision', 'terminated')
            ->assertJsonPath('detail.content.selected.can_respond', true)
            ->assertJsonPath('detail.content.action_required.0.record_key', $recordKey)
            ->assertJsonPath('detail.decision.state', 'outcome_response_required')
            ->assertJsonPath('detail.decision.action.href', route('dashboard.monitoring.history.show', [
                'application' => $application,
                'record' => $recordKey,
            ], false));

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application))
            ->assertOk()
            ->assertJsonPath('detail.content.latest_support_decision.href', route('dashboard.monitoring.history.show', [
                'application' => $application,
                'record' => $recordKey,
            ], false));

        $olderPayload = collect($response->json('detail.content.timeline'))
            ->firstWhere('record_key', 'outcome-'.$older->id);

        $this->assertNotNull($olderPayload);
        $this->assertSame('support_outcome', $olderPayload['kind']);
        $this->assertSame(route('dashboard.monitoring.history.show', [
            'application' => $application,
            'record' => 'outcome-'.$older->id,
        ], false), $olderPayload['href']);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=history&record=outcome-999999')
            ->assertNotFound();
    }

    public function test_an_applicant_cannot_open_another_applicants_monitoring_contract(): void
    {
        [, , , $application] = $this->selectedRecipient();
        $otherApplicant = User::factory()->create(['role' => 'applicant']);

        $this->actingAs($otherApplicant)
            ->getJson(route('dashboard.monitoring.show.data', $application))
            ->assertForbidden();

        $this->actingAs($otherApplicant)
            ->get(route('dashboard.monitoring.show.section', [
                'application' => $application,
                'section' => 'check-ins',
            ]))
            ->assertForbidden();

        $this->actingAs($otherApplicant)
            ->get(route('dashboard.monitoring.check-ins.show', [
                'application' => $application,
                'record' => 'check-in-1',
            ]))
            ->assertForbidden();

        $this->actingAs($otherApplicant)
            ->get(route('dashboard.monitoring.benefits.show', [
                'application' => $application,
                'record' => 'release-1',
            ]))
            ->assertForbidden();

        $this->actingAs($otherApplicant)
            ->get(route('dashboard.monitoring.history.show', [
                'application' => $application,
                'record' => 'outcome-1',
            ]))
            ->assertForbidden();
    }

    private function selectedRecipient(bool $withPlan = true): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'account_status' => 'active']);
        $provider->providerProfile()->update([
            'provider_name' => 'Harborlight Education Foundation',
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $scholarship = Scholarship::create([
            'provider_id' => $provider->id,
            'title' => 'Harborlight Continuing Scholars Program',
            'description' => 'A continuing scholarship support program.',
            'selection_stages' => ['screening', 'formal_application', 'decision'],
            'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $scholarship->id,
            'applicant_id' => $applicant->id,
            'status' => 'awarded',
            'application_state' => 'completed',
            'workflow_stage' => 'decision',
            'final_outcome' => 'selected',
            'document_checklist' => [],
            'submitted_at' => now(),
            'student_response_status' => 'accepted',
            'student_responded_at' => now(),
            'student_response_terms_accepted_at' => now(),
            'provider_contract_terms_accepted_at' => now(),
        ]);

        if (! $withPlan) {
            return [$provider, $applicant, $scholarship, $application, null];
        }

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
            'version' => 1,
            'activated_at' => now(),
        ]);

        return [$provider, $applicant, $scholarship, $application, $plan];
    }

    private function cycle(
        Scholarship $scholarship,
        RecipientMonitoringPlan $plan,
        array $overrides = [],
    ) {
        return $plan->cycles()->create([
            'scholarship_id' => $scholarship->id,
            'created_by' => $scholarship->provider_id,
            'monitoring_plan_version' => $plan->version,
            'grace_period_days' => 7,
            'allow_exception_requests' => true,
            'title' => 'Semester monitoring check',
            'period_type' => 'semester',
            'school_year' => '2026-2027',
            'opens_at' => now()->subDay()->toDateString(),
            'due_at' => now()->addWeeks(2)->toDateString(),
            'minimum_grade' => 85,
            'grading_scale' => 'percentage',
            'status' => 'open',
            'published_at' => now(),
            ...$overrides,
        ]);
    }
}
