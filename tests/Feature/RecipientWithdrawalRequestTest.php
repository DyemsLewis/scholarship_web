<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\RecipientWithdrawalEvent;
use App\Models\RecipientWithdrawalRequest;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use App\Queries\Provider\Dashboards\RecipientOfficerDashboardQuery;
use App\Services\ApplicantApplicationDetailService;
use App\Services\RecipientBenefitEntitlementService;
use App\Support\OpaqueRecordUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RecipientWithdrawalRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_keeps_the_accepted_award_and_original_terms_unchanged(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $original = $application->getRawOriginal();
        $this->actingAs($applicant)->getJson($this->index($application))
            ->assertOk()->assertJsonPath('can_request', true)->assertJsonCount(0, 'requests.data');

        $response = $this->actingAs($applicant)->postJson($this->index($application), [
            'reason' => '  I need to leave the program because my study plans have changed.  ', 'confirmed' => true,
            'status' => 'approved', 'support_decision_id' => 123,
        ])->assertCreated()->assertJsonPath('request.status', 'pending')->assertJsonPath('request.revision', 1)
            ->assertJsonPath('request.can_cancel', true)->assertJsonPath('request.can_review', false)
            ->assertJsonPath('request.review_url', null)
            ->assertJsonPath('request.agreement.version', 'accepted-version-1')
            ->assertJsonPath('request.reason', 'I need to leave the program because my study plans have changed.')
            ->assertJsonCount(1, 'request.history');
        $this->assertSame($original, $application->fresh()->getRawOriginal());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseHas('recipient_withdrawal_requests', ['id' => $response->json('request.id'), 'pending_application_id' => $application->id]);
        $this->assertDatabaseHas('recipient_withdrawal_events', ['to_status' => 'pending', 'actor_id' => $applicant->id]);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $provider->id, 'type' => 'recipient_withdrawal_request']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'recipient_withdrawal_pending']);
        $program->update(['recipient_agreement' => ['responsibilities' => 'A later program change.']]);
        $this->actingAs($applicant)->getJson($this->index($application))->assertOk()
            ->assertJsonPath('can_request', false)
            ->assertJsonPath('requests.data.0.agreement.snapshot.recipient_expectation.responsibilities', 'The terms originally accepted.');
    }

    public function test_duplicate_submission_is_a_conflict_without_duplicate_events_or_notifications(): void
    {
        [, $applicant, , $application] = $this->award();
        $this->submit($applicant, $application);
        $notifications = PortalNotification::count();
        $this->actingAs($applicant)->postJson($this->index($application), $this->reason())->assertConflict();
        $this->assertDatabaseCount('recipient_withdrawal_requests', 1);
        $this->assertDatabaseCount('recipient_withdrawal_events', 1);
        $this->assertSame($notifications, PortalNotification::count());
    }

    #[DataProvider('ineligibleAwards')]
    public function test_only_active_accepted_awards_can_request(string $status, ?string $response, ?string $outcome): void
    {
        [, $applicant, , $application] = $this->award();
        $application->update(['status' => $status, 'student_response_status' => $response, 'final_outcome' => $outcome]);
        $this->actingAs($applicant)->postJson($this->index($application), $this->reason())
            ->assertUnprocessable()->assertJsonValidationErrors('withdrawal');
        $this->assertDatabaseCount('recipient_withdrawal_requests', 0);
    }

    public static function ineligibleAwards(): array
    {
        return [
            'not selected' => ['under_review', null, null],
            'not awarded' => ['not_awarded', null, 'not_selected'],
            'awaiting response' => ['awarded', null, 'selected'],
            'declined agreement' => ['awarded', 'declined', 'selected'],
            'already ended' => ['benefits_terminated', 'accepted', 'selected'],
            'withdrawn application' => ['withdrawn', 'accepted', 'selected'],
        ];
    }

    public function test_closed_support_decisions_block_new_requests_even_if_the_status_is_still_awarded(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $application->supportDecisions()->create([
            'applicant_id' => $applicant->id, 'decision' => 'completed',
            'effective_on' => now()->toDateString(),
            'reason' => 'The support period is already complete.', 'decided_by' => $provider->id, 'decided_at' => now(),
        ]);
        $this->actingAs($applicant)->getJson($this->index($application))->assertOk()->assertJsonPath('can_request', false);
        $this->actingAs($applicant)->postJson($this->index($application), $this->reason())->assertUnprocessable();
    }

    public function test_renewed_support_uses_the_latest_decision_rather_than_any_old_termination(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        foreach (['terminated', 'renewed'] as $decision) {
            $application->supportDecisions()->create([
                'applicant_id' => $applicant->id, 'decision' => $decision,
                'effective_on' => now()->toDateString(),
                'reason' => 'A recorded support decision.', 'decided_by' => $provider->id, 'decided_at' => now(),
            ]);
        }
        $application->update(['status' => 'renewed']);
        $this->actingAs($applicant)->getJson($this->index($application))->assertOk()->assertJsonPath('can_request', true);
        $this->submit($applicant, $application);
    }

    public function test_approval_creates_one_linked_exit_decision_without_erasing_selection_or_acceptance(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $before = $application->getRawOriginal();
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())
            ->assertOk()->assertJsonPath('request.status', 'approved')->assertJsonPath('request.revision', 2)
            ->assertJsonPath('request.can_review', false)->assertJsonPath('request.can_cancel', false)
            ->assertJsonCount(2, 'request.history');
        $after = $application->fresh()->getRawOriginal();
        $this->assertSame('benefits_terminated', $after['status']);
        unset($before['status'], $before['updated_at'], $after['status'], $after['updated_at']);
        $this->assertSame($before, $after);
        $withdrawal->refresh();
        $this->assertNull($withdrawal->pending_application_id);
        $this->assertSame($provider->id, $withdrawal->resolved_by);
        $this->assertNotNull($withdrawal->support_decision_id);
        $this->assertDatabaseCount('recipient_support_decisions', 1);
        $this->assertDatabaseHas('recipient_support_decisions', [
            'id' => $withdrawal->support_decision_id, 'decision' => 'terminated', 'reason_category' => 'recipient_withdrew',
            'effective_on' => now()->toDateString().' 00:00:00',
        ]);
        $this->assertDatabaseHas('application_status_histories', [
            'scholarship_application_id' => $application->id, 'from_status' => 'awarded',
            'to_status' => 'benefits_terminated', 'decision_reason' => 'recipient_withdrew',
        ]);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id, 'type' => 'recipient_withdrawal_reviewed']);
        $this->assertSame('active', $applicant->fresh()->account_status);
        $this->assertSame('approved', $applicant->fresh()->studentProfile->verification_status);
    }

    public function test_declining_preserves_support_and_allows_a_new_request_with_separate_history(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $original = $application->getRawOriginal();
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision('declined'))
            ->assertOk()->assertJsonPath('request.status', 'declined');
        $this->assertSame($original, $application->fresh()->getRawOriginal());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->submit($applicant, $application);
        $this->assertDatabaseCount('recipient_withdrawal_requests', 2);
        $this->assertDatabaseCount('recipient_withdrawal_events', 3);
    }

    public function test_applicant_can_cancel_only_a_pending_request_and_history_is_preserved(): void
    {
        [, $applicant, , $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($applicant)->patchJson($this->cancelUrl($withdrawal), ['expected_revision' => 1, 'confirmed' => true])
            ->assertOk()->assertJsonPath('request.status', 'cancelled')->assertJsonPath('request.revision', 2);
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_withdrawal_events', 2);
        $this->assertNull($withdrawal->fresh()->pending_application_id);
        $this->actingAs($applicant)->patchJson($this->cancelUrl($withdrawal), ['expected_revision' => 2, 'confirmed' => true])->assertConflict();
        $this->submit($applicant, $application);
        $this->assertDatabaseCount('recipient_withdrawal_requests', 2);
    }

    public function test_stale_cancel_and_review_cannot_replace_a_newer_decision(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), [...$this->decision(), 'expected_revision' => 8])->assertConflict();
        $this->actingAs($applicant)->patchJson($this->cancelUrl($withdrawal), ['expected_revision' => 9, 'confirmed' => true])->assertConflict();
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertOk();
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision('declined'))->assertConflict();
        $this->actingAs($applicant)->patchJson($this->cancelUrl($withdrawal), ['expected_revision' => 2, 'confirmed' => true])->assertConflict();
        $this->assertDatabaseCount('recipient_support_decisions', 1);
        $this->assertDatabaseCount('recipient_withdrawal_events', 2);
    }

    public function test_cancel_winning_a_race_prevents_provider_approval(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($applicant)->patchJson($this->cancelUrl($withdrawal), ['expected_revision' => 1, 'confirmed' => true])->assertOk();
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertConflict();
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
    }

    public function test_approval_rechecks_the_current_support_state_and_does_not_overwrite_a_closed_record(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $application->update(['status' => 'benefits_terminated']);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())
            ->assertUnprocessable()->assertJsonValidationErrors('withdrawal');
        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('recipient_withdrawal_events', 1);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision('declined'))->assertOk();
    }

    public function test_other_applicants_providers_and_admins_cannot_use_applicant_endpoints(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        foreach ([User::factory()->create(['role' => 'applicant']), $provider, User::factory()->create(['role' => 'admin'])] as $actor) {
            $this->actingAs($actor)->getJson($this->index($application))->assertForbidden();
            $this->actingAs($actor)->postJson($this->index($application), $this->reason())->assertForbidden();
            $this->actingAs($actor)->patchJson($this->cancelUrl($withdrawal), ['expected_revision' => 1, 'confirmed' => true])->assertForbidden();
        }
        $this->assertSame('pending', $withdrawal->fresh()->status);
    }

    public function test_review_requires_the_recipient_permission_and_assigned_program_scope(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $others = [
            User::factory()->create(['role' => 'provider', 'permissions' => ['manage_recipients']]),
            $this->officer($provider, ['manage_benefit_releases']),
            $this->officer($provider, ['manage_monitoring']),
            $this->officer($provider, ['verify_applications']),
            $this->officer($provider, ['manage_recipients'], []),
            User::factory()->create(['role' => 'admin']),
            $applicant,
        ];
        foreach ($others as $actor) {
            $this->actingAs($actor)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertForbidden();
            $this->actingAs($actor)->getJson("/provider/applications/{$application->id}/recipient-withdrawals")->assertForbidden();
        }
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertSame('pending', $withdrawal->fresh()->status);
    }

    public function test_queue_and_notifications_are_scoped_to_authorized_recipient_officers(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $assigned = $this->officer($provider, ['manage_recipients'], [$program->id]);
        $unassigned = $this->officer($provider, ['manage_recipients'], []);
        $wrongRole = $this->officer($provider, ['manage_monitoring'], [$program->id]);
        $this->submit($applicant, $application);
        [$otherProvider, $otherApplicant, , $otherApplication] = $this->award();
        $this->submit($otherApplicant, $otherApplication);
        $queue = '/provider/workspaces/recipients/withdrawals/data';
        $this->actingAs($assigned)->getJson($queue)->assertOk()->assertJsonCount(1, 'requests.data')
            ->assertJsonPath('requests.data.0.program.id', $program->id)->assertJsonPath('requests.data.0.can_review', true);
        $this->actingAs($unassigned)->getJson($queue)->assertOk()->assertJsonCount(0, 'requests.data');
        $this->actingAs($wrongRole)->getJson($queue)->assertForbidden();
        $this->actingAs($otherProvider)->getJson($queue)->assertOk()->assertJsonCount(1, 'requests.data');
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $assigned->id, 'type' => 'recipient_withdrawal_request']);
        $this->assertDatabaseMissing('portal_notifications', ['user_id' => $unassigned->id, 'type' => 'recipient_withdrawal_request']);
        $this->assertDatabaseMissing('portal_notifications', ['user_id' => $wrongRole->id, 'type' => 'recipient_withdrawal_request']);
    }

    public function test_assigned_recipient_officer_can_review_an_opaque_request_url(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $officer = $this->officer($provider, ['manage_recipients'], [$program->id]);
        $withdrawal = $this->submit($applicant, $application);
        $opaque = app(OpaqueRecordUrl::class)->encode($this->reviewUrl($withdrawal))['path'];
        $this->actingAs($officer)->patchJson($opaque, $this->decision())->assertOk()->assertJsonPath('request.status', 'approved');
        $this->assertSame($officer->id, $withdrawal->fresh()->resolved_by);
    }

    public function test_guest_and_unapproved_provider_are_denied(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $this->getJson($this->index($application))->assertUnauthorized();
        $withdrawal = $this->submit($applicant, $application);
        $provider->providerProfile()->update(['verification_status' => 'pending']);
        $this->actingAs($provider->fresh())->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertForbidden();
    }

    public function test_validation_trims_notes_requires_confirmation_and_rejects_unknown_outcomes(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $this->actingAs($applicant)->postJson($this->index($application), ['reason' => '            '])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason', 'confirmed']);
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), ['outcome' => 'blacklisted', 'review_note' => '             '])
            ->assertUnprocessable()->assertJsonValidationErrors(['outcome', 'review_note', 'expected_revision', 'confirmed']);
        $this->actingAs($applicant)->patchJson($this->cancelUrl($withdrawal), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_revision', 'confirmed']);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), [...$this->decision('declined'), 'review_note' => '  Please contact the scholarship office to clarify the requested exit.  '])
            ->assertOk()->assertJsonPath('request.review_note', 'Please contact the scholarship office to clarify the requested exit.');
    }

    public function test_pending_request_cannot_be_bypassed_by_the_old_support_outcome_or_termination_endpoints(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $this->submit($applicant, $application);
        $this->actingAs($provider)->postJson("/provider/applications/{$application->id}/support-decision", [
            'decision' => 'terminated', 'reason_category' => 'recipient_withdrew', 'effective_on' => now()->toDateString(),
            'notice_given_on' => now()->toDateString(), 'reason' => 'The applicant asked to exit the scholarship.', 'confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->actingAs($provider)->patchJson("/provider/applications/{$application->id}/status", [
            'status' => 'benefits_terminated', 'decision_reason' => 'procedure_not_followed',
            'outcome_notes' => 'Do not bypass the pending applicant withdrawal request.',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
    }

    public function test_approval_stops_future_releases_but_preserves_prior_release_proof_and_confirmation(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $release = $program->benefitReleases()->create([
            'created_by' => $provider->id, 'title' => 'Learning allowance release', 'release_at' => now()->subDay(),
            'benefit_description' => 'Agreed learning support.', 'release_method' => 'bank_transfer', 'status' => 'in_progress',
        ]);
        $past = $release->records()->create([
            'scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'status' => 'released',
            'receipt_path' => 'private/previous-receipt.pdf', 'released_at' => now()->subDay(),
        ]);
        $laterRelease = $program->benefitReleases()->create([
            'created_by' => $provider->id, 'title' => 'Separate later allowance', 'release_at' => now(),
            'benefit_description' => 'Later support.', 'release_method' => 'bank_transfer', 'status' => 'scheduled',
        ]);
        $future = $laterRelease->records()->create([
            'scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'status' => 'scheduled',
        ]);
        $originalPast = $past->refresh()->getRawOriginal();
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertOk();
        foreach (['prepared', 'released'] as $status) {
            $this->actingAs($provider)->postJson("/provider/benefit-release-records/{$future->id}/result", [
                'status' => $status, 'notes' => 'This must not release after support ended.', 'originals_verified' => true,
            ])->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->assertSame('scheduled', $future->fresh()->status);
        $this->assertSame($originalPast, $past->fresh()->getRawOriginal());
        $this->actingAs($provider)->postJson("/provider/scholarships/{$program->id}/benefit-releases", [
            'title' => 'Blocked later release', 'release_at' => now()->addDay()->toDateTimeString(),
            'benefit_description' => 'Future support.', 'release_method' => 'bank_transfer',
            'requires_original_verification' => false, 'recipient_ids' => [$application->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('recipient_ids');
        $this->actingAs($applicant)->postJson("/dashboard/benefit-release-records/{$past->id}/response", [
            'response_type' => 'confirmed', 'received_on' => now()->subDay()->toDateString(),
            'recipient_note' => 'I confirm receipt of the earlier allowance.', 'acknowledged' => true,
        ])->assertCreated();
        $this->assertDatabaseHas('recipient_benefit_receipt_responses', ['recipient_benefit_release_record_id' => $past->id]);
    }

    public function test_entitlement_cancellation_does_not_erase_delivered_or_disputed_benefits(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        foreach (['ready', 'scheduled', 'active', 'released', 'fulfilled', 'issue_open'] as $index => $status) {
            $benefit = $program->benefits()->create(['type' => 'allowance', 'title' => 'Benefit '.$index, 'frequency' => 'one_time', 'sort_order' => $index]);
            $application->benefitEntitlements()->create([
                'scholarship_id' => $program->id, 'applicant_id' => $applicant->id,
                'scholarship_benefit_id' => $benefit->id, 'benefit_type' => 'allowance',
                'title' => 'Benefit '.$index, 'frequency' => 'one_time', 'status' => $status,
            ]);
        }
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertOk();
        app(RecipientBenefitEntitlementService::class)->ensureForApplication($application->fresh());
        $this->assertSame(['cancelled', 'cancelled', 'cancelled', 'released', 'fulfilled', 'issue_open'],
            $application->benefitEntitlements()->orderBy('id')->pluck('status')->all());
    }

    public function test_an_exit_does_not_prevent_a_different_accepted_award_from_continuing(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $otherProgram = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Independent program', 'description' => 'Separate accepted support.', 'status' => 'published']);
        $other = ScholarshipApplication::create([
            'scholarship_id' => $otherProgram->id, 'applicant_id' => $applicant->id, 'status' => 'awarded',
            'application_state' => 'closed', 'workflow_stage' => 'complete', 'final_outcome' => 'selected', 'student_response_status' => 'accepted',
        ]);
        $originalOther = $other->refresh()->getRawOriginal();
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertOk();
        $this->assertSame($originalOther, $other->fresh()->getRawOriginal());
        $this->actingAs($applicant)->getJson($this->index($other))->assertOk()->assertJsonPath('can_request', true);
    }

    public function test_approval_does_not_invent_benefit_entitlements_from_a_later_program_edit(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $program->benefits()->create(['type' => 'allowance', 'title' => 'Later unassigned benefit', 'frequency' => 'one_time']);
        $this->assertDatabaseCount('recipient_benefit_entitlements', 0);
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertOk();
        $this->assertDatabaseCount('recipient_benefit_entitlements', 0);
        $this->assertDatabaseCount('recipient_benefit_release_allocations', 0);
    }

    public function test_ordinary_application_withdrawal_cannot_replace_the_accepted_award_process(): void
    {
        [, $applicant, , $application] = $this->award();
        $this->actingAs($applicant)->patchJson("/dashboard/applications/{$application->id}/withdraw", ['reason' => 'I need to exit.'])
            ->assertUnprocessable();
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_withdrawal_requests', 0);
    }

    public function test_pending_requests_do_not_block_agreed_benefit_distribution(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $benefit = $program->benefits()->create(['type' => 'allowance', 'title' => 'Agreed benefit', 'frequency' => 'one_time']);
        $this->submit($applicant, $application);
        $created = $this->actingAs($provider)->postJson("/provider/scholarships/{$program->id}/benefit-releases", [
            'title' => 'Agreed distribution', 'release_at' => now()->toDateTimeString(),
            'benefit_description' => 'A benefit that is still active.', 'release_method' => 'bank_transfer',
            'requires_original_verification' => false, 'recipient_ids' => [$application->id], 'benefit_ids' => [$benefit->id],
        ])->assertCreated();
        $recordId = $created->json('release.records.0.id');
        $this->actingAs($provider)->postJson("/provider/benefit-release-records/{$recordId}/result", [
            'status' => 'released', 'notes' => 'The agreed benefit was transferred to the recipient.',
        ])->assertOk();
        $this->assertDatabaseHas('recipient_benefit_release_records', ['id' => $recordId, 'status' => 'released']);
        $this->assertSame('pending', RecipientWithdrawalRequest::sole()->status);
    }

    public function test_closing_old_appointments_after_exit_does_not_reactivate_the_cancelled_entitlement(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $benefit = $program->benefits()->create(['type' => 'allowance', 'title' => 'Unreleased benefit', 'frequency' => 'one_time']);
        $entitlements = app(RecipientBenefitEntitlementService::class);
        $release = $program->benefitReleases()->create([
            'created_by' => $provider->id, 'title' => 'Earlier arrangement', 'release_at' => now()->subDay(),
            'benefit_description' => 'An arrangement not yet delivered.', 'release_method' => 'bank_transfer', 'status' => 'scheduled',
        ]);
        $record = $release->records()->create(['scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'status' => 'scheduled']);
        $entitlements->allocate($record, $program->benefits()->whereKey($benefit->id)->get());
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertOk();
        $this->actingAs($provider)->postJson("/provider/benefit-release-records/{$record->id}/result", [
            'status' => 'withheld', 'notes' => 'Closed without distribution following the approved exit.',
        ])->assertOk();
        $this->assertSame('withheld', $record->fresh()->status);
        $this->assertSame('cancelled', $application->benefitEntitlements()->sole()->status);
    }

    public function test_failed_audit_write_rolls_back_the_entire_approval(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $program->benefits()->create(['type' => 'allowance', 'title' => 'Accepted support', 'frequency' => 'one_time']);
        app(RecipientBenefitEntitlementService::class)->ensureForApplication($application);
        $withdrawal = $this->submit($applicant, $application);
        $notifications = PortalNotification::count();
        $eventName = 'eloquent.creating: '.RecipientWithdrawalEvent::class;
        Event::listen($eventName, function (RecipientWithdrawalEvent $event): void {
            if ($event->to_status === 'approved') {
                throw new RuntimeException('Simulated unavailable history storage.');
            }
        });
        try {
            $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertServerError();
        } finally {
            Event::forget($eventName);
        }
        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertSame('ready', $application->benefitEntitlements()->sole()->status);
        $this->assertSame($notifications, PortalNotification::count());
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('application_status_histories', 0);
        $this->assertDatabaseCount('recipient_withdrawal_events', 1);
    }

    public function test_queue_filters_history_privacy_and_page_validation(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision('declined'))->assertOk();
        $queue = '/provider/workspaces/recipients/withdrawals/data';
        $this->actingAs($provider)->getJson($queue)->assertOk()->assertJsonCount(0, 'requests.data');
        $this->actingAs($provider)->getJson($queue.'?status=all')->assertOk()->assertJsonCount(1, 'requests.data');
        $this->actingAs($provider)->getJson($queue.'?status=declined')->assertOk()->assertJsonPath('requests.data.0.status', 'declined');
        $this->actingAs($provider)->getJson($queue.'?status=unknown')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($applicant)->getJson($this->index($application).'?page=-1')->assertUnprocessable()->assertJsonValidationErrors('page');
        $item = $this->actingAs($applicant)->getJson($this->index($application))->assertOk()->json('requests.data.0');
        foreach (['pending_application_id', 'resolved_by', 'applicant_id', 'support_decision_id', 'email', 'ip_address'] as $field) {
            $this->assertArrayNotHasKey($field, $item);
        }
        $this->assertArrayNotHasKey('actor_id', $item['history'][0]);
        $this->assertCount(2, $item['history']);
    }

    public function test_event_history_is_append_only_and_transactions_roll_back_as_a_unit(): void
    {
        [$provider, $applicant, , $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $event = RecipientWithdrawalEvent::firstOrFail();
        try {
            $event->update(['note' => 'An overwritten event.']);
            $this->fail('History updates must be rejected.');
        } catch (LogicException) {
            $this->assertSame('pending', $event->fresh()->to_status);
        }
        try {
            $event->delete();
            $this->fail('History deletion must be rejected.');
        } catch (LogicException) {
            $this->assertDatabaseCount('recipient_withdrawal_events', 1);
        }
        DB::beginTransaction();
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertOk();
        DB::rollBack();
        $this->assertSame('pending', $withdrawal->fresh()->status);
        $this->assertSame('awarded', $application->fresh()->status);
        $this->assertDatabaseCount('recipient_support_decisions', 0);
        $this->assertDatabaseCount('recipient_withdrawal_events', 1);
        $this->assertFalse(ActivityLog::where('action', 'recipient_withdrawal_approved')->exists());
    }

    public function test_ui_read_endpoints_and_notification_links_preserve_program_scope(): void
    {
        $this->withoutVite();
        [$provider, $applicant, $program, $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $providerUrl = "/provider/recipient-withdrawals/{$withdrawal->id}";
        $applicantUrl = "/dashboard/recipient-withdrawals/{$withdrawal->id}";
        $this->actingAs($provider)->getJson($providerUrl)->assertOk()->assertJsonPath('request.read_url', $providerUrl)
            ->assertJsonPath('request.can_review', true);
        $this->actingAs($applicant)->getJson($applicantUrl)->assertOk()->assertJsonPath('request.read_url', $applicantUrl)
            ->assertJsonPath('request.can_cancel', true)->assertJsonPath('request.review_url', null);
        $unassigned = $this->officer($provider, ['manage_recipients'], []);
        $this->actingAs($unassigned)->getJson($providerUrl)->assertForbidden();
        $this->actingAs($unassigned)->get("/provider/workspaces/recipients/withdrawals/{$withdrawal->id}")->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'applicant']))->getJson($applicantUrl)->assertForbidden();
        $this->actingAs($provider)->get('/provider/workspaces/recipients/withdrawals')->assertOk()->assertViewIs('provider-recipient-withdrawals');
        $this->actingAs($provider)->get("/provider/workspaces/recipients/withdrawals/{$withdrawal->id}")->assertOk()->assertSee('data-withdrawal-id="'.$withdrawal->id.'"', false);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $provider->id, 'action_url' => "/provider/workspaces/recipients/withdrawals/{$withdrawal->id}"]);
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision())->assertOk();
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id, 'action_url' => "/dashboard/applications/{$application->id}/outcome#scholarship-withdrawal"]);
    }

    public function test_queue_search_and_program_filters_cannot_escape_assignment_scope(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $applicant->studentProfile()->update(['first_name' => 'Alexandra', 'last_name' => 'Sample']);
        $this->submit($applicant->fresh(), $application);
        [$otherProvider, $otherApplicant, $otherProgram, $otherApplication] = $this->award();
        $this->submit($otherApplicant, $otherApplication);
        $queue = '/provider/workspaces/recipients/withdrawals/data';
        $this->actingAs($provider)->getJson($queue.'?search=Alexandra')->assertOk()->assertJsonCount(1, 'requests.data');
        $this->actingAs($provider)->getJson($queue.'?search=Alexandra%20Sample')->assertOk()->assertJsonCount(1, 'requests.data');
        $this->actingAs($provider)->getJson($queue.'?search=missing')->assertOk()->assertJsonCount(0, 'requests.data');
        $this->actingAs($provider)->getJson($queue.'?program_id='.$program->id)->assertOk()->assertJsonCount(1, 'requests.data')->assertJsonCount(1, 'programs');
        $this->actingAs($provider)->getJson($queue.'?program_id='.$otherProgram->id)->assertForbidden();
        $this->actingAs($provider)->getJson($queue.'?search='.str_repeat('a', 161))->assertUnprocessable();
    }

    public function test_outcome_ui_contract_is_available_only_for_an_accepted_selection(): void
    {
        [, $applicant, , $application] = $this->award();
        $detail = app(ApplicantApplicationDetailService::class);
        $payload = $detail->build($application, $applicant, 'outcome');
        $this->assertSame($this->index($application), $payload['content']['withdrawal']['history_url']);
        $application->update(['student_response_status' => null]);
        $this->assertNull($detail->build($application->fresh(), $applicant, 'outcome')['content']['withdrawal']);
        $application->update(['student_response_status' => 'declined']);
        $this->assertNull($detail->build($application->fresh(), $applicant, 'outcome')['content']['withdrawal']);
        $application->update(['student_response_status' => 'accepted', 'status' => 'benefits_terminated']);
        $ended = $detail->build($application->fresh(), $applicant, 'outcome');
        $this->assertSame('Scholarship support has ended', $ended['decision']['title']);
        $this->assertStringContainsString('Future support has ended', $ended['content']['recipient_support']['description']);
        $this->assertSame($this->index($application), $ended['content']['withdrawal']['history_url']);
    }

    public function test_recipient_dashboard_surfaces_only_pending_requests_for_assigned_programs(): void
    {
        [$provider, $applicant, $program, $application] = $this->award();
        $withdrawal = $this->submit($applicant, $application);
        $assigned = $this->officer($provider, ['manage_recipients'], [$program->id]);
        $unassigned = $this->officer($provider, ['manage_recipients'], []);
        $dashboard = app(RecipientOfficerDashboardQuery::class);
        $sections = collect($dashboard->get($assigned)['sections']);
        $section = $sections->firstWhere('key', 'withdrawal-requests');
        $this->assertNotNull($section);
        $this->assertSame($withdrawal->id, $section['items'][0]['id']);
        $this->assertStringContainsString('/recipients/withdrawals/', $section['items'][0]['action']['url']);
        $this->assertNull(collect($dashboard->get($unassigned)['sections'])->firstWhere('key', 'withdrawal-requests'));
        $this->actingAs($provider)->patchJson($this->reviewUrl($withdrawal), $this->decision('declined'))->assertOk();
        $this->assertNull(collect($dashboard->get($assigned)['sections'])->firstWhere('key', 'withdrawal-requests'));
    }

    private function award(): array
    {
        $provider = User::factory()->create(['role' => 'provider', 'permissions' => ['manage_recipients', 'manage_benefit_releases', 'verify_applications', 'record_final_decisions']]);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);
        $applicant = User::factory()->create(['role' => 'applicant', 'account_status' => 'active']);
        $applicant->studentProfile()->update(['verification_status' => 'approved']);
        $program = Scholarship::create(['provider_id' => $provider->id, 'title' => 'Withdrawal Test Award', 'description' => 'Accepted scholarship support.', 'status' => 'published']);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'awarded',
            'application_state' => 'closed', 'workflow_stage' => 'complete', 'final_outcome' => 'selected',
            'decision_reason' => 'approved_for_award', 'awarded_amount' => 5000, 'outcome_at' => now()->subDays(5),
            'student_response_status' => 'accepted', 'student_responded_at' => now()->subDays(4),
            'provider_contract_terms_snapshot' => ['recipient_expectation' => ['responsibilities' => 'The terms originally accepted.']],
            'provider_contract_terms_version' => 'accepted-version-1', 'student_response_terms_version' => 'accepted-version-1',
            'provider_contract_terms_accepted_at' => now()->subDays(4), 'submitted_at' => now()->subWeek(),
        ]);

        return [$provider, $applicant, $program, $application->refresh()];
    }

    private function officer(User $owner, array $permissions, ?array $programs = null): User
    {
        return User::factory()->create(['role' => 'provider', 'parent_account_id' => $owner->id,
            'permissions' => $permissions, 'assigned_program_ids' => $programs]);
    }

    private function submit(User $applicant, ScholarshipApplication $application): RecipientWithdrawalRequest
    {
        $response = $this->actingAs($applicant)->postJson($this->index($application), $this->reason())->assertCreated();

        return RecipientWithdrawalRequest::findOrFail($response->json('request.id'));
    }

    private function reason(): array
    {
        return ['reason' => 'My study circumstances changed and I request to leave this award.', 'confirmed' => true];
    }

    private function decision(string $outcome = 'approved'): array
    {
        return ['outcome' => $outcome, 'review_note' => 'We reviewed the applicant request and recorded this outcome.', 'expected_revision' => 1, 'confirmed' => true];
    }

    private function index(ScholarshipApplication $application): string
    {
        return "/dashboard/applications/{$application->id}/recipient-withdrawals";
    }

    private function reviewUrl(RecipientWithdrawalRequest $withdrawal): string
    {
        return "/provider/recipient-withdrawals/{$withdrawal->id}/review";
    }

    private function cancelUrl(RecipientWithdrawalRequest $withdrawal): string
    {
        return "/dashboard/recipient-withdrawals/{$withdrawal->id}/cancel";
    }
}
