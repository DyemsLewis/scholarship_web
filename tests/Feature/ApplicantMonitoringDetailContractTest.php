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

    public function test_support_landing_uses_the_same_direct_action_as_the_detail_and_only_shows_owned_support(): void
    {
        [$provider, $applicant, $program, $application, $plan] = $this->selectedRecipient();
        $program->update(['image_path' => 'uploads/scholarships/support-logo.png']);
        $cycle = $this->cycle($program, $plan, [
            'title' => 'Next check-in period',
            'opens_at' => now()->addDays(3)->toDateString(),
            'due_at' => now()->addWeeks(2)->toDateString(),
        ]);
        $cycle->requirements()->create([
            'type' => 'enrollment', 'title' => 'Proof of enrollment', 'required' => true,
            'requires_file' => true, 'requires_original_verification' => false, 'sort_order' => 0,
        ]);
        $released = $this->landingRelease($program, $application, 'Learning allowance', 'released', now()->subDay());
        $this->landingRelease($program, $application, 'Next learning allowance', 'scheduled', now()->addWeek());
        $this->landingRelease($program, $application, 'Old release appointment', 'scheduled', now()->subWeek());
        $other = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Another scholarship', 'description' => 'Other scholarship', 'status' => 'published']);
        $pending = ScholarshipApplication::create([
            'scholarship_id' => $other->id, 'applicant_id' => $applicant->id, 'status' => 'submitted',
        ]);
        $anotherApplicant = User::factory()->create(['role' => 'applicant']);
        ScholarshipApplication::create([
            'scholarship_id' => $other->id, 'applicant_id' => $anotherApplicant->id,
            'status' => 'awarded', 'final_outcome' => 'selected',
        ]);

        $landing = $this->actingAs($applicant)->getJson(route('dashboard.monitoring.data'))
            ->assertOk()
            ->assertJsonCount(1, 'support.active')
            ->assertJsonCount(0, 'support.past')
            ->assertJsonPath('support.active.0.logo_url', $program->logoUrl())
            ->assertJsonPath('support.focus.decision.state', 'benefit_confirmation_required')
            ->assertJsonPath('support.focus.decision.action.href', route('dashboard.monitoring.benefits.show', [$application, 'release-'.$released->id], false))
            ->assertJsonMissing(['title' => 'Another scholarship'])
            ->assertJsonMissing(['title' => 'Old release appointment'])
            ->assertJsonMissingPath('applications');
        $this->assertDatabaseCount('recipient_monitoring_cases', 0);
        $this->assertDatabaseHas('scholarship_applications', ['id' => $pending->id, 'status' => 'submitted']);
        $landing->assertJsonPath('support.schedule.0.title', 'Next learning allowance')
            ->assertJsonPath('support.schedule.1.title', 'Next check-in period');
        $detail = $this->getJson(route('dashboard.monitoring.show.data', $application))->assertOk()
            ->assertJsonCount(2, 'detail.content.schedule')
            ->assertJsonPath('detail.content.schedule.0.title', 'Next learning allowance')
            ->assertJsonPath('detail.content.schedule.0.context', 'In person')
            ->assertJsonPath('detail.content.schedule.1.title', 'Next check-in period');
        $this->assertSame($detail->json('detail.decision'), $landing->json('support.focus.decision'));
        $this->assertNotContains($detail->json('detail.decision.action.href'), array_column($detail->json('detail.content.schedule'), 'href'));
    }

    public function test_support_landing_keeps_ended_awards_in_past_support_without_hiding_a_pending_outcome(): void
    {
        [$provider, $applicant, $program, $ended] = $this->selectedRecipient();
        $scheduledProgram = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Current award', 'description' => 'Current scholarship', 'status' => 'published']);
        $current = ScholarshipApplication::create([
            'scholarship_id' => $scheduledProgram->id, 'applicant_id' => $applicant->id,
            'status' => 'awarded', 'student_response_status' => 'accepted',
        ]);
        $this->landingRelease($scheduledProgram, $current, 'Scheduled award', 'scheduled', now()->addDays(2));
        $declinedProgram = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Declined award', 'description' => 'Declined scholarship', 'status' => 'published']);
        ScholarshipApplication::create([
            'scholarship_id' => $declinedProgram->id, 'applicant_id' => $applicant->id,
            'status' => 'awarded', 'student_response_status' => 'declined',
        ]);
        $decision = RecipientSupportDecision::create([
            'scholarship_application_id' => $ended->id, 'applicant_id' => $applicant->id,
            'decision' => 'completed', 'reason' => 'The support period ended.', 'decided_by' => $provider->id,
            'decided_at' => now(), 'effective_on' => now()->toDateString(), 'response_status' => 'pending',
        ]);
        $this->landingRelease($program, $ended, 'Future date on ended support', 'scheduled', now()->addDay());
        $this->actingAs($applicant)->getJson(route('dashboard.monitoring.data'))
            ->assertOk()
            ->assertJsonCount(1, 'support.active')
            ->assertJsonPath('support.active.0.title', 'Current award')
            ->assertJsonCount(2, 'support.past')
            ->assertJsonFragment(['label' => 'Award declined'])
            ->assertJsonPath('support.focus.decision.state', 'outcome_response_required')
            ->assertJsonPath('support.focus.decision.action.href', route('dashboard.monitoring.history.show', [$ended, 'outcome-'.$decision->id], false))
            ->assertJsonCount(1, 'support.schedule')
            ->assertJsonPath('support.schedule.0.title', 'Scheduled award');
        $this->getJson(route('dashboard.monitoring.show.data', $ended))
            ->assertOk()->assertJsonCount(0, 'detail.content.schedule');
    }

    public function test_support_landing_handles_empty_accounts_and_rejects_other_roles(): void
    {
        $this->getJson(route('dashboard.monitoring.data'))->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'provider']))->getJson(route('dashboard.monitoring.data'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'applicant']))->getJson(route('dashboard.monitoring.data'))
            ->assertOk()
            ->assertJsonPath('support.focus', null)
            ->assertJsonCount(0, 'support.active')
            ->assertJsonCount(0, 'support.past')
            ->assertJsonCount(0, 'support.schedule');
    }

    private function landingRelease(Scholarship $program, ScholarshipApplication $application, string $title, string $status, \Carbon\CarbonInterface $date): \App\Models\RecipientBenefitReleaseRecord
    {
        return $program->benefitReleases()->create([
            'created_by' => $program->provider_id, 'title' => $title, 'release_at' => $date,
            'benefit_description' => $title, 'release_method' => 'in_person', 'status' => 'scheduled', 'published_at' => now(),
        ])->records()->create([
            'scholarship_application_id' => $application->id, 'applicant_id' => $application->applicant_id, 'status' => $status,
        ]);
    }

    public function test_every_monitoring_section_uses_the_program_logo_not_its_cover(): void
    {
        [, $applicant, $program, $application] = $this->selectedRecipient();
        foreach (['uploads/scholarships/monitoring-logo.png', null] as $logo) {
            $program->update([
                'image_path' => $logo,
                'cover_image_key' => 'science-project',
                'cover_image_source' => 'system',
            ]);
            foreach (['overview', 'check-ins', 'benefits', 'history'] as $view) {
                $this->actingAs($applicant)
                    ->getJson(route('dashboard.monitoring.show.data', $application).'?view='.$view)
                    ->assertOk()
                    ->assertJsonPath('detail.identity.program.image_url', $program->logoUrl())
                    ->assertJsonPath('detail.identity.program.logo_url', $program->logoUrl())
                    ->assertJsonPath('detail.identity.program.cover_image_url', $program->coverImageUrl());
            }
        }
    }

    public function test_overview_links_to_the_saved_agreement_and_only_includes_published_plan_instructions(): void
    {
        [, $applicant, , $application, $plan] = $this->selectedRecipient();
        $application->update(['provider_contract_terms_snapshot' => ['program_title' => 'Scholarship agreement']]);
        $plan->update(['status' => 'draft', 'instructions' => 'Internal draft instructions']);
        $agreementUrl = route('dashboard.applications.show.section', ['application' => $application, 'section' => 'outcome'], false).'?action=view-agreement';

        $this->actingAs($applicant)->getJson(route('dashboard.monitoring.show.data', $application))
            ->assertOk()
            ->assertJsonPath('detail.content.plan.agreement.label', 'Accepted')
            ->assertJsonPath('detail.content.plan.agreement.href', $agreementUrl)
            ->assertJsonPath('detail.content.plan.instructions', null);
        $plan->update(['status' => 'active', 'instructions' => 'Bring your current school records.']);
        $this->getJson(route('dashboard.monitoring.show.data', $application))
            ->assertOk()
            ->assertJsonPath('detail.content.plan.instructions', 'Bring your current school records.');
    }

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
            ->assertJsonPath('detail.page.title', 'Scholarship Support')
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
            ->assertJsonPath('detail.content.plan.mode_label', 'Recurring check-ins')
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
            ->assertJsonPath('detail.content.selected.preparation', null)
            ->assertJsonPath('detail.content.directory.next_appointment', null)
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

    public function test_legacy_closure_without_an_outcome_is_not_counted_as_completed(): void
    {
        [, $applicant, $scholarship, $application, $plan] = $this->selectedRecipient();
        $closed = $this->cycle($scholarship, $plan, ['title' => 'Legacy academic check-in', 'status' => 'closed']);
        $this->cycle($scholarship, $plan, ['title' => 'Cancelled academic check-in', 'status' => 'cancelled']);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=check-ins&record=academic-'.$closed->id)
            ->assertOk()
            ->assertJsonPath('detail.summary.check_ins.completed', 0)
            ->assertJsonPath('detail.summary.check_ins.next_due_label', null)
            ->assertJsonCount(2, 'detail.content.directory.completed')
            ->assertJsonPath('detail.content.selected.state_label', 'Closed')
            ->assertJsonPath('detail.content.selected.state_tone', 'neutral')
            ->assertJsonFragment(['state_label' => 'Cancelled']);

        $this->getJson(route('dashboard.monitoring.show.data', $application).'?view=history')
            ->assertOk()
            ->assertJsonCount(2, 'detail.content.timeline')
            ->assertJsonPath('detail.content.timeline.0.date_label', null)
            ->assertJsonPath('detail.content.timeline.1.month_key', null);
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
            ->assertJsonPath('detail.decision.state', 'benefit_payout_required')
            ->assertJsonCount(3, 'detail.navigation')
            ->assertJsonMissing(['key' => 'check-ins'])
            ->assertJsonCount(0, 'detail.content.schedule');

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=check-ins')
            ->assertOk()
            ->assertJsonPath('detail.content.empty.key', 'one_time_benefit')
            ->assertJsonPath('detail.content.empty.title', 'No recurring check-ins required');
    }

    public function test_support_only_record_has_calm_empty_states_without_fake_schedules(): void
    {
        [, $applicant, $program, $application] = $this->selectedRecipient(withPlan: false);
        $program->update(['support_mode' => 'recurring', 'monitoring_policy' => 'none']);

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
            ->assertJsonPath('detail.content.selected.delivery.label', 'Confirmation needed')
            ->assertJsonPath('detail.content.selected.delivery.date_caption', 'Provider recorded release')
            ->assertJsonPath('detail.content.directory.action_required.0.record_key', $recordKey)
            ->assertJsonPath('detail.decision.state', 'benefit_confirmation_required')
            ->assertJsonPath('detail.decision.action.href', route('dashboard.monitoring.benefits.show', [
                'application' => $application,
                'record' => $recordKey,
            ], false));

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=benefits&record=release-999999')
            ->assertNotFound();

        $this->postJson(route('dashboard.benefit-release-records.response', $record), [
            'response_type' => 'confirmed',
            'received_on' => now()->toDateString(),
            'acknowledged' => true,
        ])->assertCreated();

        $this->getJson(route('dashboard.monitoring.show.data', $application).'?view=benefits&record='.$recordKey)
            ->assertOk()
            ->assertJsonPath('detail.content.selected.delivery.label', 'Received')
            ->assertJsonPath('detail.content.selected.delivery.date_caption', 'Received on')
            ->assertJsonPath('detail.content.selected.delivery.date_label', now()->format('M d, Y'))
            ->assertJsonPath('detail.content.selected.can_respond', false)
            ->assertJsonCount(0, 'detail.content.directory.action_required')
            ->assertJsonPath('detail.content.directory.completed.0.record_key', $recordKey);
    }

    public function test_benefit_directory_distinguishes_future_schedules_from_collection_and_past_updates(): void
    {
        [, $applicant, $program, $application] = $this->selectedRecipient(withPlan: false);
        $future = $this->landingRelease($program, $application, 'Future collection', 'prepared', now()->addDay());
        $ready = $this->landingRelease($program, $application, 'Collection ready', 'prepared', now()->subHour());
        $waiting = $this->landingRelease($program, $application, 'Awaiting provider', 'scheduled', now()->subDay());
        $transfer = $this->landingRelease($program, $application, 'Pending transfer', 'prepared', now()->subDays(2));
        $transfer->release->update(['release_method' => 'e_wallet']);
        $transfer->payoutDetails()->create([
            'method' => 'e_wallet', 'destination' => ['institution' => 'Example Wallet'],
            'submitted_at' => now()->subDays(3),
        ]);

        $url = route('dashboard.monitoring.show.data', $application).'?view=benefits';
        $this->actingAs($applicant)->getJson($url)->assertOk()
            ->assertJsonPath('detail.content.one_time', true)
            ->assertJsonCount(1, 'detail.content.directory.upcoming')
            ->assertJsonPath('detail.content.directory.upcoming.0.record_key', 'release-'.$future->id)
            ->assertJsonPath('detail.content.directory.upcoming.0.delivery.label', 'Scheduled')
            ->assertJsonCount(3, 'detail.content.directory.in_progress')
            ->assertJsonPath('detail.content.directory.in_progress.0.record_key', 'release-'.$transfer->id)
            ->assertJsonPath('detail.content.directory.in_progress.0.delivery.label', 'Awaiting release update')
            ->assertJsonPath('detail.content.directory.in_progress.1.record_key', 'release-'.$waiting->id)
            ->assertJsonPath('detail.content.directory.in_progress.2.record_key', 'release-'.$ready->id)
            ->assertJsonPath('detail.content.directory.in_progress.2.delivery.label', 'Ready for collection')
            ->assertJsonCount(0, 'detail.content.directory.completed');

        $this->getJson($url.'&record=release-'.$ready->id)->assertOk()
            ->assertJsonPath('detail.content.selected.delivery.label', 'Ready for collection')
            ->assertJsonPath('detail.content.selected.can_respond', false);
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
            ->assertJsonPath('detail.content.selected.closeout.kind', 'ended_early')
            ->assertJsonPath('detail.content.selected.closeout.counts', null)
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
        $this->assertSame('No response recorded', $olderPayload['status_label']);
        $this->assertSame(route('dashboard.monitoring.history.show', [
            'application' => $application,
            'record' => 'outcome-'.$older->id,
        ], false), $olderPayload['href']);

        $this->actingAs($applicant)
            ->getJson(route('dashboard.monitoring.show.data', $application).'?view=history&record=outcome-999999')
            ->assertNotFound();

        $this->getJson(route('dashboard.monitoring.show.data', $application).'?view=history&record=outcome-'.$older->id)
            ->assertOk()
            ->assertJsonPath('detail.content.selected.response_status_label', 'No response recorded')
            ->assertJsonPath('detail.content.selected.can_respond', false);
    }

    public function test_support_history_uses_actual_dates_and_separates_action_from_past_records(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
        [$provider, $applicant, $program, $application, $plan] = $this->selectedRecipient();
        $closed = $this->cycle($program, $plan, [
            'title' => 'Closed semester check-in', 'status' => 'closed', 'closed_at' => now()->subDays(4),
            'due_at' => now()->subMonth()->toDateString(),
        ]);
        $this->cycle($program, $plan, ['title' => 'Next semester check-in']);
        $release = $this->landingRelease($program, $application, 'Learning allowance', 'released', now()->subMonth());
        $release->update(['released_at' => now()->subDays(3), 'recorded_at' => now()->subDays(3)]);
        $withheld = $this->landingRelease($program, $application, 'Withheld release', 'withheld', now()->addWeek());
        $withheld->update(['recorded_at' => now()->subWeeks(2)]);
        $decision = RecipientSupportDecision::create([
            'scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id,
            'decision' => 'renewed', 'reason_category' => 'requirements_met', 'reason' => 'The check-in was completed.',
            'effective_on' => now()->addWeek()->toDateString(), 'decided_by' => $provider->id, 'decided_at' => now(),
            'response_status' => 'pending',
        ]);

        $this->actingAs($applicant)->postJson(route('dashboard.benefit-release-records.response', $release), [
            'response_type' => 'confirmed', 'received_on' => '2026-11-09', 'acknowledged' => true,
        ])->assertCreated();

        $url = route('dashboard.monitoring.show.data', $application).'?view=history';
        $this->getJson($url)->assertOk()
            ->assertJsonPath('detail.content.action_required.0.record_key', 'outcome-'.$decision->id)
            ->assertJsonCount(3, 'detail.content.timeline')
            ->assertJsonPath('detail.content.timeline.0.record_key', 'release-'.$release->id)
            ->assertJsonPath('detail.content.timeline.0.status_label', 'Received')
            ->assertJsonPath('detail.content.timeline.0.date_label', 'Nov 09, 2026')
            ->assertJsonPath('detail.content.timeline.0.date_caption', 'Received')
            ->assertJsonPath('detail.content.timeline.0.month_label', 'November 2026')
            ->assertJsonPath('detail.content.timeline.1.record_key', 'academic-'.$closed->id)
            ->assertJsonPath('detail.content.timeline.1.date_caption', 'Closed')
            ->assertJsonPath('detail.content.timeline.1.date_label', 'Nov 06, 2026')
            ->assertJsonPath('detail.content.timeline.2.record_key', 'release-'.$withheld->id)
            ->assertJsonPath('detail.content.timeline.2.date_label', 'Oct 27, 2026')
            ->assertJsonPath('detail.content.timeline.2.date_caption', 'Recorded');

        $this->postJson(route('dashboard.support-decisions.response', $decision), [
            'response_type' => 'acknowledged', 'confirmed' => true,
        ])->assertCreated();
        $this->getJson($url)->assertOk()
            ->assertJsonCount(0, 'detail.content.action_required')
            ->assertJsonCount(4, 'detail.content.timeline')
            ->assertJsonPath('detail.content.timeline.0.record_key', 'outcome-'.$decision->id)
            ->assertJsonPath('detail.content.timeline.0.status_label', 'Acknowledged')
            ->assertJsonPath('detail.content.timeline.0.date_label', 'Nov 10, 2026');
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
