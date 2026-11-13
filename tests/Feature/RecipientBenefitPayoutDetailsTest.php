<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\RecipientBenefitReleaseRecord;
use App\Models\Scholarship;
use App\Models\ScholarshipApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecipientBenefitPayoutDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_details_are_private_to_one_release_and_do_not_record_payment(): void
    {
        [$applicant, $record] = $this->release();
        $otherRelease = $record->release->replicate();
        $otherRelease->save();
        $otherRecord = $otherRelease->records()->create([
            'scholarship_application_id' => $record->scholarship_application_id,
            'applicant_id' => $applicant->id,
            'status' => 'scheduled',
        ]);
        $url = $this->url($record);
        $details = $this->bankDetails();

        $this->actingAs($applicant)->getJson($this->detailUrl($record))
            ->assertOk()
            ->assertJsonPath('detail.content.selected.payout.status', 'required')
            ->assertJsonPath('detail.content.selected.payout.submit_url', $url)
            ->assertJsonCount(2, 'detail.content.directory.payout_required')
            ->assertJsonPath('detail.summary.benefits.payout_details_needed', 2);
        $response = $this->postJson($url, $details)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertDontSeeText($details['account_number']);

        $saved = $record->payoutDetails()->sole();
        $this->assertSame($details['account_number'], $saved->destination['account_number']);
        $this->assertStringNotContainsString($details['account_number'], $saved->getRawOriginal('destination'));
        $this->assertStringNotContainsString($details['account_holder'], $saved->getRawOriginal('destination'));
        $this->assertArrayNotHasKey('destination', $saved->toArray());
        $this->assertSame('scheduled', $record->fresh()->status);
        $this->assertNull($record->fresh()->released_at);
        $this->assertFalse($otherRecord->payoutDetails()->exists());

        $this->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.payout.status', 'submitted')
            ->assertJsonPath('detail.content.selected.can_respond', false)
            ->assertJsonCount(1, 'detail.content.directory.payout_required')
            ->assertDontSeeText($details['account_number'])
            ->assertDontSeeText($details['account_holder'])
            ->assertDontSeeText($details['institution']);
        $this->getJson(route('dashboard.monitoring.show.data', $record->application))->assertOk()
            ->assertJsonPath('detail.decision.state', 'benefit_payout_required');

        $this->postJson($url, [...$details, 'account_number' => '0000098765', 'expected_revision' => 1])->assertOk();
        $this->assertSame('0000098765', $saved->fresh()->destination['account_number']);
        $this->assertDatabaseCount('recipient_benefit_payout_details', 1);
    }

    public function test_wallet_validation_uses_the_published_method_without_flashing_rejected_details(): void
    {
        [$applicant, $record] = $this->release('e_wallet');
        $url = $this->url($record);
        $this->actingAs($applicant)->post($url, $this->bankDetails())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['method', 'account_number'])
            ->assertSessionMissing('_old_input');

        $wallet = [...$this->bankDetails(), 'method' => 'e_wallet', 'account_number' => '+639000000001'];
        $this->postJson($url, [...$wallet, 'confirmed' => false])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->assertDatabaseCount('recipient_benefit_payout_details', 0);

        $this->postJson($url, $wallet)->assertOk();
        $this->assertSame('09000000001', $record->payoutDetails()->sole()->destination['account_number']);
        $this->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.payout.method', 'e_wallet')
            ->assertDontSeeText('09000000001');
    }

    public function test_only_the_applicant_on_the_release_can_submit(): void
    {
        [, $record, $provider] = $this->release();
        $url = $this->url($record);
        $this->postJson($url, $this->bankDetails())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'applicant']))->postJson($url, $this->bankDetails())->assertForbidden();
        $this->actingAs($provider)->postJson($url, $this->bankDetails())->assertForbidden();
        $this->assertDatabaseCount('recipient_benefit_payout_details', 0);
    }

    #[DataProvider('unavailableReleases')]
    public function test_inactive_releases_do_not_show_or_accept_the_form(array $changes): void
    {
        [$applicant, $record] = $this->release();
        foreach ($changes as $relation => $attributes) {
            ($relation === 'record' ? $record : $record->{$relation})->update($attributes);
        }

        $this->actingAs($applicant)->getJson($this->detailUrl($record))
            ->assertOk()->assertJsonPath('detail.content.selected.payout', null);
        $this->postJson($this->url($record), $this->bankDetails())->assertConflict();
        $this->assertDatabaseCount('recipient_benefit_payout_details', 0);
    }

    public static function unavailableReleases(): array
    {
        return [
            'agreement not accepted' => [['application' => ['student_response_status' => null]]],
            'award declined' => [['application' => ['student_response_status' => 'declined']]],
            'support ended' => [['application' => ['status' => 'benefits_terminated']]],
            'unpublished release' => [['release' => ['published_at' => null]]],
            'in person' => [['release' => ['release_method' => 'in_person']]],
            'other arrangement' => [['release' => ['release_method' => 'other']]],
            'already released' => [['record' => ['status' => 'released']]],
            'withheld' => [['record' => ['status' => 'withheld']]],
            'missed' => [['record' => ['status' => 'missed']]],
        ];
    }

    public function test_an_open_delivery_report_prevents_changing_the_payout_destination(): void
    {
        [$applicant, $record] = $this->release();
        $record->release->update(['release_at' => now()->subDay()]);

        $this->actingAs($applicant)->postJson('/dashboard/applications/'.$record->scholarship_application_id.'/delivery-reports', [
            'request_key' => (string) Str::uuid(),
            'source_type' => 'release',
            'source_id' => $record->id,
            'issue_kind' => 'not_received',
            'details' => 'I need to report that the benefit has not arrived.',
            'confirmed' => true,
        ])->assertCreated();

        $this->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.payout.status', 'required')
            ->assertJsonPath('detail.content.selected.payout.submit_url', $this->url($record));
        $this->postJson($this->url($record), $this->bankDetails())->assertOk();
        $this->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.payout.status', 'submitted')
            ->assertJsonPath('detail.content.selected.payout.submit_url', null);
        $this->postJson($this->url($record), [...$this->bankDetails(), 'account_number' => '9999123456', 'expected_revision' => 1])->assertConflict();
        $this->assertSame($this->bankDetails()['account_number'], $record->payoutDetails()->sole()->destination['account_number']);
    }

    public function test_only_authorized_staff_can_open_a_private_destination_and_reads_are_audited(): void
    {
        [$applicant, $record, $owner] = $this->release();
        $details = $this->bankDetails();
        $this->actingAs($applicant)->postJson($this->url($record), $details)->assertOk();

        $reviewUrl = $this->reviewUrl($record);
        $denied = [
            $applicant,
            User::factory()->create(['role' => 'admin']),
            $this->approvedProvider(),
            $this->releaseOfficer($owner, []),
            $this->releaseOfficer($owner, null, ['manage_monitoring']),
        ];
        foreach ($denied as $actor) {
            $this->actingAs($actor)->getJson($reviewUrl)->assertForbidden()->assertDontSeeText($details['account_number']);
            $this->patchJson($reviewUrl, $this->reviewDecision('approve', 1))->assertForbidden();
        }

        $officer = $this->releaseOfficer($owner, [$record->application->scholarship_id]);
        $this->actingAs($officer)->getJson(route('provider.workspaces.releases.distributions.data', $record->release))
            ->assertOk()->assertJsonPath('release.records.0.payout.status', 'submitted')
            ->assertDontSeeText($details['account_number'])->assertDontSeeText($details['institution']);
        $response = $this->getJson($reviewUrl)->assertOk()
            ->assertJsonPath('destination.account_number', $details['account_number'])
            ->assertJsonPath('revision', 1);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $audit = ActivityLog::where('action', 'benefit_payout_viewed')->sole();
        $this->assertSame($officer->id, $audit->user_id);
        $this->assertSame(['benefit_release_record_id' => $record->id, 'payout_revision' => 1], $audit->metadata);
        $this->assertStringNotContainsString($details['account_number'], $audit->toJson());
    }

    public function test_corrections_and_approval_block_release_until_the_current_destination_is_approved(): void
    {
        Storage::fake('local');
        [$applicant, $record, $owner] = $this->release();
        $record->release->update(['release_at' => now()->subHour()]);
        $officer = $this->releaseOfficer($owner, [$record->application->scholarship_id]);
        $resultUrl = route('provider.benefit-release-records.result', $record, false);
        $reviewUrl = $this->reviewUrl($record);
        $result = ['status' => 'released', 'notes' => 'The provider recorded the transfer.'];
        $this->actingAs($officer)->postJson($resultUrl, $result)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->actingAs($applicant)->postJson($this->url($record), $this->bankDetails())->assertOk();
        $this->actingAs($officer)->postJson($resultUrl, $result)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson($reviewUrl)->assertOk()->assertJsonPath('revision', 1);

        $replacement = [...$this->bankDetails(), 'account_number' => '000004321000', 'expected_revision' => 1];
        $this->actingAs($applicant)->postJson($this->url($record), $replacement)->assertOk();
        $this->actingAs($officer)->patchJson($reviewUrl, $this->reviewDecision('approve', 1))->assertConflict();
        $this->patchJson($reviewUrl, $this->reviewDecision('request_correction', 2))->assertUnprocessable()->assertJsonValidationErrors('correction_reason');
        $this->patchJson($reviewUrl, $this->reviewDecision('request_correction', 2) + ['correction_reason' => 'destination'])->assertOk();
        $this->getJson($reviewUrl)->assertConflict()->assertDontSeeText($replacement['account_number']);
        $this->postJson($resultUrl, $result)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id, 'type' => 'benefit_payout_correction_requested']);

        $this->actingAs($applicant)->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.payout.status', 'correction_requested')
            ->assertJsonPath('detail.content.selected.payout.correction_label', 'Check the account or registered mobile number.')
            ->assertJsonPath('detail.content.selected.payout.revision', 3)
            ->assertJsonPath('detail.decision.title', 'Correct your payout details')
            ->assertDontSeeText($replacement['account_number']);
        $this->postJson($this->url($record), [...$replacement, 'expected_revision' => 3])->assertOk();

        $this->actingAs($officer)->patchJson($reviewUrl, $this->reviewDecision('approve', 4))->assertOk();
        $this->assertDatabaseHas('recipient_benefit_payout_details', ['release_record_id' => $record->id, 'review_status' => 'approved', 'reviewed_by' => $officer->id, 'revision' => 5]);
        $this->assertDatabaseHas('portal_notifications', ['user_id' => $applicant->id, 'type' => 'benefit_payout_approved']);
        $this->assertSame('scheduled', $record->fresh()->status);

        $this->actingAs($applicant)->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.payout.status', 'approved')
            ->assertJsonPath('detail.content.selected.payout.submit_url', null);
        $this->postJson($this->url($record), [...$replacement, 'expected_revision' => 5])->assertConflict();
        $this->actingAs($officer)->postJson($resultUrl, $result + ['expected_payout_revision' => 4])->assertConflict();
        $transfer = $result + [
            'expected_payout_revision' => 5, 'transfer_sent_on' => now()->toDateString(), 'transfer_confirmed' => true,
        ];
        $this->postJson($resultUrl, $transfer)->assertUnprocessable()->assertJsonValidationErrors('receipt_proof');
        $this->postJson($resultUrl, [
            ...$transfer, 'transfer_sent_on' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('transfer_sent_on');
        $proof = UploadedFile::fake()->image('transfer-proof.png');
        $this->postJson($resultUrl, [
            ...$transfer, 'transfer_sent_on' => now()->subDay()->toDateString(), 'receipt_proof' => $proof,
        ])->assertUnprocessable()->assertJsonValidationErrors('transfer_sent_on');
        $this->postJson($resultUrl, [...$transfer, 'transfer_confirmed' => false, 'receipt_proof' => $proof])
            ->assertUnprocessable()->assertJsonValidationErrors('transfer_confirmed');
        $this->assertSame('scheduled', $record->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->postJson($resultUrl, $transfer + ['receipt_proof' => $proof])->assertOk()
            ->assertJsonPath('release.records.0.status', 'released')
            ->assertJsonPath('release.records.0.status_label', 'Sent - awaiting receipt');
        Storage::disk('local')->assertExists($record->fresh()->receipt_path);
        $this->assertDatabaseMissing('recipient_benefit_receipt_responses', ['recipient_benefit_release_record_id' => $record->id]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $applicant->id,
            'type' => 'recipient_benefit_release_result',
            'title' => 'Transfer sent',
            'action_url' => route('dashboard.monitoring.benefits.show', [$record->application, 'release-'.$record->id], false),
        ]);
        $this->getJson($reviewUrl)->assertConflict()->assertDontSeeText($replacement['account_number']);

        $this->actingAs($applicant)->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.transfer_sent_on', now()->toDateString())
            ->assertJsonPath('detail.content.selected.delivery.date_caption', 'Sent on')
            ->assertJsonPath('detail.content.selected.can_respond', true);
        $proofUrl = route('dashboard.benefit-release-records.receipt', $record, false);
        $this->get($proofUrl)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'applicant']))->get($proofUrl)->assertForbidden();
        $responseUrl = route('dashboard.benefit-release-records.response', $record, false);
        $response = ['response_type' => 'confirmed', 'acknowledged' => true];
        $this->actingAs($applicant)->postJson($responseUrl, $response + ['received_on' => now()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('received_on');
        $this->postJson($responseUrl, $response + ['received_on' => now()->toDateString()])->assertCreated();
        $this->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.delivery.label', 'Received')
            ->assertJsonPath('detail.content.selected.can_respond', false);
    }

    public function test_an_e_wallet_transfer_can_be_reported_as_not_received(): void
    {
        Storage::fake('local');
        [$applicant, $record, $owner] = $this->release('e_wallet');
        $record->release->update(['release_at' => now()->subDay()]);
        $this->actingAs($applicant)->postJson($this->url($record), [
            ...$this->bankDetails(), 'method' => 'e_wallet', 'account_number' => '09000000001',
        ])->assertOk();
        $this->actingAs($owner)->patchJson($this->reviewUrl($record), $this->reviewDecision('approve', 1))->assertOk();
        $this->postJson(route('provider.benefit-release-records.result', $record), [
            'status' => 'released', 'expected_payout_revision' => 2, 'transfer_sent_on' => now()->toDateString(),
            'transfer_confirmed' => true, 'receipt_proof' => UploadedFile::fake()->image('wallet-proof.png'),
        ])->assertOk();
        $this->actingAs($applicant)->postJson('/dashboard/applications/'.$record->scholarship_application_id.'/delivery-reports', [
            'request_key' => (string) Str::uuid(),
            'source_type' => 'release', 'source_id' => $record->id, 'issue_kind' => 'not_received',
            'details' => 'The recorded transfer has not arrived in my wallet.', 'confirmed' => true,
        ])->assertCreated();
        $this->getJson($this->detailUrl($record))->assertOk()
            ->assertJsonPath('detail.content.selected.delivery.key', 'issue_open')
            ->assertJsonPath('detail.content.selected.can_respond', false);
        $this->assertSame('open', $record->receiptResponse()->sole()->status);
    }

    private function release(string $method = 'bank_transfer'): array
    {
        $applicant = User::factory()->create(['role' => 'applicant']);
        $provider = $this->approvedProvider();
        $program = Scholarship::create([
            'provider_id' => $provider->id, 'title' => 'Learning Grant',
            'description' => 'An allowance for school supplies.', 'status' => 'published',
        ]);
        $application = ScholarshipApplication::create([
            'scholarship_id' => $program->id, 'applicant_id' => $applicant->id, 'status' => 'awarded',
            'final_outcome' => 'selected', 'student_response_status' => 'accepted',
            'student_response_terms_accepted_at' => now(),
        ]);
        $release = $program->benefitReleases()->create([
            'created_by' => $provider->id, 'title' => 'Learning allowance', 'status' => 'scheduled',
            'benefit_description' => 'Allowance for school supplies.',
            'requires_original_verification' => false,
            'release_at' => now()->addWeek(), 'release_method' => $method, 'published_at' => now(),
        ]);
        $record = $release->records()->create([
            'scholarship_application_id' => $application->id, 'applicant_id' => $applicant->id, 'status' => 'scheduled',
        ]);

        return [$applicant, $record, $provider];
    }

    private function approvedProvider(): User
    {
        $provider = User::factory()->create(['role' => 'provider', 'account_status' => 'active']);
        $provider->providerProfile()->update(['verification_status' => 'approved', 'verified_at' => now()]);

        return $provider;
    }

    private function releaseOfficer(User $owner, ?array $programIds, array $permissions = ['manage_benefit_releases']): User
    {
        return User::factory()->create([
            'role' => 'provider', 'account_status' => 'active', 'parent_account_id' => $owner->id,
            'account_title' => 'benefit_release_officer', 'permissions' => $permissions, 'assigned_program_ids' => $programIds,
        ]);
    }

    private function reviewUrl(RecipientBenefitReleaseRecord $record): string
    {
        return route('provider.benefit-release-records.payout-details.show', $record, false);
    }

    private function reviewDecision(string $decision, int $revision): array
    {
        return ['decision' => $decision, 'expected_revision' => $revision, 'confirmed' => true];
    }

    private function bankDetails(): array
    {
        return [
            'method' => 'bank_transfer', 'institution' => 'Example Savings Bank',
            'account_holder' => 'Example Recipient', 'account_number' => '000012345678',
            'confirmed' => true,
        ];
    }

    private function url(RecipientBenefitReleaseRecord $record): string
    {
        return route('dashboard.benefit-release-records.payout-details', $record, false);
    }

    private function detailUrl(RecipientBenefitReleaseRecord $record): string
    {
        return route('dashboard.monitoring.show.data', $record->application).'?view=benefits&record=release-'.$record->id;
    }
}
