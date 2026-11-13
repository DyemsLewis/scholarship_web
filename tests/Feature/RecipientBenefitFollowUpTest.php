<?php

namespace Tests\Feature;

use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Services\BenefitDeliveryReportService;
use App\Services\RecipientBenefitEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecipientBenefitFollowUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_unclaimed_requires_notice_reminder_expired_deadline_and_explicit_close_without_inferring_withdrawal(): void
    {
        $this->freezeTime();
        [$provider, $applicant, $application, $record] = $this->award();
        $original = $record->getRawOriginal();

        $this->complete($provider, $application)->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->act($provider, $record, 'close_unclaimed', 0)->assertConflict();
        $this->act($provider, $record, 'notice', 0, ['respond_by' => now()->addDays(3)->toDateString()])->assertUnprocessable()->assertJsonValidationErrors('respond_by');
        $this->act($provider, $record, 'notice', 0)->assertOk()->assertJsonPath('follow_up.status', 'awaiting_response');
        $this->act($provider, $record, 'remind', 1)->assertConflict();
        $this->act($provider, $record, 'close_unclaimed', 1)->assertConflict();

        $this->actingAs($applicant)->getJson("/dashboard/monitoring/{$application->id}/data?view=benefits&record=release-{$record->id}")
            ->assertOk()->assertJsonPath('detail.decision.state', 'benefit_follow_up');
        $this->travel(8)->days();
        $this->act($provider, $record, 'close_unclaimed', 1)->assertConflict();
        $this->act($provider, $record, 'remind', 1, ['respond_by' => now()->addDays(2)->toDateString()])->assertOk()->assertJsonPath('follow_up.revision', 2);
        $this->act($provider, $record, 'close_unclaimed', 2)->assertConflict();
        $this->travel(3)->days();
        $this->act($provider, $record, 'close_unclaimed', 1)->assertConflict();
        $this->act($provider, $record, 'close_unclaimed', 2)->assertOk()->assertJsonPath('follow_up.status', 'unclaimed')->assertJsonCount(3, 'follow_up.events');

        $this->assertSame($original, $record->fresh()->getRawOriginal());
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertSame('accepted', $application->fresh()->student_response_status);
        $this->assertSame('unclaimed', $application->benefitEntitlements()->sole()->status);
        $this->assertDatabaseCount('recipient_withdrawal_requests', 0);
        $this->assertDatabaseCount('recipient_agreement_cases', 0);
        $this->assertDatabaseCount('recipient_benefit_receipt_responses', 0);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->actingAs($provider)->getJson("/provider/applications/{$application->id}/recipient-record")
            ->assertOk()->assertJsonPath('record.settlement.counts.unclaimed', 1)->assertJsonPath('record.settlement.counts.received', 0);
        $this->complete($provider, $application)->assertOk();
        $decision = $application->supportDecisions()->sole();
        $this->assertSame('completed', $decision->decision);
        $this->assertSame(['received' => 0, 'unclaimed' => 1, 'declined' => 0, 'pending' => 0], $decision->closeout_snapshot);
        $overview = route('dashboard.monitoring.show.data', $application, false);
        $this->actingAs($applicant)->getJson($overview)->assertOk()
            ->assertJsonPath('detail.content.closeout.kind', 'completed')
            ->assertJsonPath('detail.content.closeout.counts.unclaimed', 1)
            ->assertJsonPath('detail.content.closeout.counts.received', 0)
            ->assertJsonPath('detail.content.closeout.withdrawal_href', null)
            ->assertJsonCount(0, 'detail.content.schedule');
        $this->getJson($overview.'?view=history&record=outcome-'.$decision->id)->assertOk()
            ->assertJsonPath('detail.content.selected.closeout.counts', $decision->closeout_snapshot);
        $this->actingAs(User::factory()->create(['role' => 'applicant']))->getJson($overview)->assertForbidden();
    }

    public function test_a_late_reply_prevents_unclaimed_closure_and_replacement_keeps_the_missed_history(): void
    {
        $this->freezeTime();
        [$provider, $applicant, $application, $record, $benefit] = $this->award();
        $this->act($provider, $record, 'notice', 0)->assertOk();
        $this->travel(3)->days();
        $this->act($provider, $record, 'remind', 1)->assertOk();
        $this->travel(8)->days();
        $this->act($applicant, $record, 'continue', 2)->assertOk()->assertJsonPath('follow_up.status', 'responded');
        $this->act($provider, $record, 'close_unclaimed', 2)->assertConflict();
        $this->act($provider, $record, 'close_unclaimed', 3)->assertConflict();
        $this->complete($provider, $application)->assertUnprocessable();

        $this->actingAs($provider)->postJson("/provider/scholarships/{$application->scholarship_id}/benefit-releases", [
            'title' => 'Replacement pickup', 'benefit_description' => $benefit->title, 'release_at' => now()->addDay()->toDateTimeString(),
            'release_method' => 'in_person', 'location' => 'School office', 'requires_original_verification' => false,
            'benefit_ids' => [$benefit->id], 'recipient_ids' => [$application->id],
        ])->assertCreated();
        $this->assertSame('missed', $record->fresh()->status);
        $this->assertSame('rescheduled', $record->followUp()->sole()->status);
        $this->assertNotNull($record->followUp()->sole()->replacement_record_id);
        $this->complete($provider, $application)->assertUnprocessable();
    }

    public function test_only_the_recipient_can_decline_a_benefit_and_a_later_request_can_reopen_it(): void
    {
        [$provider, $applicant, $application, $record] = $this->award();
        $this->act($applicant, $record, 'decline', 0)->assertConflict();
        $this->act($provider, $record, 'notice', 0)->assertOk();
        $this->act($provider, $record, 'decline', 1)->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->act($applicant, $record, 'decline', 1, ['confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->act($applicant, $record, 'decline', 1)->assertOk()->assertJsonPath('follow_up.status', 'declined');
        $this->assertSame('declined', $application->benefitEntitlements()->sole()->status);
        $this->assertSame('accepted', $application->fresh()->student_response_status);
        $this->act($applicant, $record, 'continue', 2)->assertOk()->assertJsonPath('follow_up.status', 'responded');
        $this->assertSame('ready', $application->benefitEntitlements()->sole()->status);
        $this->complete($provider, $application)->assertUnprocessable();
    }

    public function test_other_accounts_cannot_change_the_follow_up_and_an_open_delivery_report_blocks_closure(): void
    {
        [$provider, $applicant, $application, $record] = $this->award();
        $outside = User::factory()->create(['role' => 'provider', 'permissions' => ['manage_benefit_releases']]);
        $outside->providerProfile()->update(['verification_status' => 'approved']);
        $this->act($outside, $record, 'notice', 0)->assertForbidden();
        $this->act(User::factory()->create(['role' => 'applicant']), $record, 'decline', 0)->assertForbidden();
        $this->act($provider, $record, 'notice', 0)->assertOk();
        app(BenefitDeliveryReportService::class)->submit($applicant, $application, [
            'source_type' => 'release', 'source_id' => $record->id, 'issue_kind' => 'not_received',
            'details' => 'I still need this benefit and would like to resolve the missed delivery.',
            'request_key' => (string) Str::uuid(), 'confirmed' => true,
        ]);
        $this->travel(8)->days();
        $this->act($provider, $record, 'remind', 1)->assertConflict();
        $this->act($provider, $record, 'close_unclaimed', 1)->assertConflict();
        $this->complete($provider, $application)->assertUnprocessable()->assertJsonValidationErrors('decision');
    }

    public function test_normal_completion_requires_a_receipt_and_does_not_skip_future_or_withheld_benefits(): void
    {
        [$provider, $applicant, $application, $record] = $this->award('scheduled');
        $record->release->update(['release_at' => now()->addDays(10)]);
        $this->complete($provider, $application)->assertUnprocessable();
        $record->release->update(['release_at' => now()->subDay()]);
        $record->update(['status' => 'withheld']);
        $this->complete($provider, $application)->assertUnprocessable();
        $record->update(['status' => 'released', 'released_at' => now()]);
        $this->complete($provider, $application)->assertUnprocessable();
        $this->actingAs($applicant)->postJson("/dashboard/benefit-release-records/{$record->id}/response", [
            'response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true,
        ])->assertCreated();
        $this->complete($provider, $application)->assertOk();
    }

    public function test_recurring_replacement_must_be_a_real_later_release_of_the_same_benefit(): void
    {
        [$provider, $applicant, $application, $record, $benefit] = $this->award('missed', 'monthly');
        $this->act($provider, $record, 'notice', 0)->assertOk();
        $this->act($applicant, $record, 'continue', 1)->assertOk();
        $this->act($provider, $record, 'link_replacement', 2, ['replacement_record_id' => $record->id])->assertConflict();
        $replacement = $this->actingAs($provider)->postJson("/provider/scholarships/{$application->scholarship_id}/benefit-releases", [
            'title' => 'Replacement monthly release', 'benefit_description' => $benefit->title, 'release_at' => now()->addDay()->toDateTimeString(),
            'release_method' => 'in_person', 'location' => 'School office', 'requires_original_verification' => false,
            'benefit_ids' => [$benefit->id], 'recipient_ids' => [$application->id],
        ])->assertCreated()->json('release.records.0.id');
        $this->act($provider, $record, 'link_replacement', 2, ['replacement_record_id' => $replacement])
            ->assertOk()->assertJsonPath('follow_up.status', 'rescheduled');
        $this->assertSame($replacement, $record->followUp()->sole()->replacement_record_id);
        $this->complete($provider, $application)->assertUnprocessable();
    }

    public function test_later_receipt_updates_the_next_step_without_rewriting_the_early_closeout(): void
    {
        [$provider, $applicant, $application, $record] = $this->award('released');
        $record->update(['released_at' => now()->subHour()]);
        $this->actingAs($provider)->postJson("/provider/applications/{$application->id}/support-decision", [
            'decision' => 'terminated', 'reason_category' => 'provider_funding_ended',
            'effective_on' => now()->toDateString(), 'notice_given_on' => now()->subDay()->toDateString(),
            'reason' => 'The remaining program funding was discontinued.', 'confirmed' => true,
        ])->assertOk();
        $decision = $application->supportDecisions()->sole();
        $this->assertSame(['received' => 0, 'unclaimed' => 0, 'declined' => 0, 'pending' => 1], $decision->closeout_snapshot);
        $overview = route('dashboard.monitoring.show.data', $application, false);
        $this->actingAs($applicant)->getJson($overview)->assertOk()
            ->assertJsonPath('detail.content.closeout.kind', 'ended_early')
            ->assertJsonPath('detail.content.closeout.reason_label', 'Provider funding ended')
            ->assertJsonPath('detail.decision.state', 'benefit_confirmation_required');
        $this->postJson("/dashboard/benefit-release-records/{$record->id}/response", [
            'response_type' => 'confirmed', 'received_on' => now()->toDateString(), 'acknowledged' => true,
        ])->assertCreated();
        $this->getJson($overview)->assertOk()
            ->assertJsonPath('detail.content.closeout.counts', $decision->closeout_snapshot)
            ->assertJsonPath('detail.decision.state', 'outcome_response_required');
        $this->postJson("/dashboard/support-decisions/{$decision->id}/response", [
            'response_type' => 'acknowledged', 'confirmed' => true,
        ])->assertCreated();
        $this->getJson($overview)->assertOk()->assertJsonPath('detail.decision.state', 'support_closed');
        $this->assertSame($decision->closeout_snapshot, $decision->fresh()->closeout_snapshot);
    }

    private function act(User $actor, RecipientBenefitReleaseRecord $record, string $action, int $revision, array $override = [])
    {
        $prefix = $actor->isApplicant() ? 'dashboard' : 'provider';
        return $this->actingAs($actor)->postJson("/{$prefix}/benefit-release-records/{$record->id}/follow-up", [
            'action' => $action, 'note' => 'Please arrange the missed school benefit with the program office.',
            'respond_by' => now()->addDays(7)->toDateString(), 'expected_revision' => $revision, 'confirmed' => true,
            ...$override,
        ]);
    }

    private function complete(User $provider, ScholarshipApplication $application)
    {
        return $this->actingAs($provider)->postJson("/provider/applications/{$application->id}/support-decision", [
            'decision' => 'completed', 'reason_category' => 'program_completed', 'effective_on' => now()->toDateString(),
            'reason' => 'The program period is complete and benefits are accounted for.', 'confirmed' => true,
        ]);
    }

    private function award(string $releaseStatus = 'missed', string $frequency = 'one_time'): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'permissions' => ['manage_recipients', 'manage_benefit_releases']]);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);
        $applicant = User::factory()->create(['role' => 'applicant', 'account_status' => 'active']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'School Support Award', 'description' => 'Accepted scholarship support.', 'status' => 'published']);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'awarded',
            'application_state' => 'closed', 'workflow_stage' => 'complete', 'final_outcome' => 'selected',
            'student_response_status' => 'accepted', 'provider_contract_terms_snapshot' => ['responsibilities' => 'Confirm the benefit when received.'],
            'provider_contract_terms_version' => 'v1', 'student_response_terms_version' => 'v1',
            'provider_contract_terms_accepted_at' => now()->subWeek(), 'outcome_at' => now()->subWeek(), 'submitted_at' => now()->subMonth(),
        ]);
        $benefit = $program->benefits()->create(['type' => 'school_supplies', 'title' => 'School supplies', 'frequency' => $frequency]);
        $release = $program->benefitReleases()->create([
            'created_by' => $provider->id, 'title' => 'School supplies pickup', 'release_at' => now()->subDay(),
            'benefit_description' => $benefit->title, 'release_method' => 'in_person', 'status' => 'scheduled', 'published_at' => now()->subWeek(),
        ]);
        $record = $release->records()->create(['scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'status' => 'scheduled']);
        $entitlements = app(RecipientBenefitEntitlementService::class);
        $entitlements->allocate($record, $program->benefits()->get());
        $record->update(['status' => $releaseStatus, 'recorded_at' => now(), 'notes' => 'Original result recorded by the release officer.']);
        $entitlements->syncReleaseResult($record->fresh());

        return [$provider, $applicant, $application, $record->fresh(), $benefit];
    }
}
