<?php

namespace Tests\Feature;

use App\Models\ProviderServicePurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminBillingRefinementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'Asia/Manila'));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unchanged_overdue_target_retains_its_exact_timestamp_when_progress_is_saved(): void
    {
        [$officer, , $purchase] = $this->requestRecord();
        $purchase->update(['target_due_at' => '2026-10-20 17:30:00']);
        $before = $purchase->fresh()->target_due_at->toISOString();
        $this->actingAs($officer)->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
            'fulfillment_status' => 'in_progress', 'target_due_at' => '2026-10-20', 'fulfillment_notes' => 'The review is still in progress.',
        ])->assertOk()->assertJsonPath('purchase.target_due_at', $before);
        $this->assertSame($before, $purchase->fresh()->target_due_at->toISOString());
    }

    public function test_other_past_dates_and_invalid_dates_cannot_replace_the_saved_target(): void
    {
        [$officer, , $purchase] = $this->requestRecord();
        $purchase->update(['target_due_at' => '2026-10-20 17:30:00']);
        foreach (['2026-10-19', '2026-10-25', 'not-a-date', '2026-02-30'] as $date) {
            $this->actingAs($officer)->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
                'fulfillment_status' => 'in_progress', 'target_due_at' => $date,
            ])->assertUnprocessable()->assertJsonValidationErrors('target_due_at');
        }
        $this->assertSame('2026-10-20', $purchase->fresh()->target_due_at->toDateString());
    }

    public function test_missing_targets_cannot_be_backdated_but_today_future_and_explicit_clear_are_allowed(): void
    {
        [$officer, , $purchase] = $this->requestRecord();
        $this->actingAs($officer)->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
            'fulfillment_status' => 'in_progress', 'target_due_at' => '2026-10-30',
        ])->assertUnprocessable()->assertJsonValidationErrors('target_due_at');
        foreach (['2026-10-31', '2026-11-05', null] as $date) {
            $this->patchJson("/admin/billing/{$purchase->id}/fulfillment", ['fulfillment_status' => 'in_progress', 'target_due_at' => $date])->assertOk();
            $this->assertSame($date, $purchase->fresh()->target_due_at?->toDateString());
        }
    }

    public function test_partial_updates_preserve_existing_assignment_date_and_progress_unless_explicitly_cleared(): void
    {
        [$officer, , $purchase] = $this->requestRecord();
        $purchase->update(['assigned_to' => $officer->id, 'target_due_at' => '2026-10-20 17:30:00', 'fulfillment_notes' => 'Keep the existing progress note.']);
        $before = $purchase->fresh()->target_due_at->toISOString();
        $this->actingAs($officer)->patchJson("/admin/billing/{$purchase->id}/fulfillment", ['fulfillment_status' => 'in_progress', 'priority' => 'high'])
            ->assertOk()->assertJsonPath('purchase.assigned_to', $officer->id)
            ->assertJsonPath('purchase.fulfillment_notes', 'Keep the existing progress note.')
            ->assertJsonPath('purchase.target_due_at', $before);
        $this->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
            'fulfillment_status' => 'in_progress', 'assigned_to' => null, 'fulfillment_notes' => null, 'target_due_at' => null,
        ])->assertOk()->assertJsonPath('purchase.assigned_to', null)->assertJsonPath('purchase.target_due_at', null)->assertJsonPath('purchase.fulfillment_notes', null);
    }

    public function test_provider_and_internal_notes_remain_separate_when_saving_an_overdue_request(): void
    {
        [$officer, $provider, $purchase] = $this->requestRecord();
        $purchase->update(['target_due_at' => '2026-10-20']);
        $this->actingAs($officer)->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
            'fulfillment_status' => 'in_progress', 'target_due_at' => '2026-10-20',
            'provider_update' => 'The program readiness review is progressing.',
            'internal_note' => 'Internal coordination note for billing staff.',
        ])->assertOk();
        $this->getJson("/admin/billing/{$purchase->id}/data?section=updates")->assertOk()->assertJsonCount(2, 'purchase.updates');
        $this->actingAs($provider)->getJson("/provider/billing/{$purchase->id}/data?section=updates")
            ->assertOk()->assertJsonCount(1, 'purchase.updates')
            ->assertJsonMissing(['message' => 'Internal coordination note for billing staff.']);
    }

    public function test_delivery_options_follow_existing_transitions_and_do_not_offer_provider_completion(): void
    {
        [$officer, , $purchase] = $this->requestRecord();
        $purchase->update(['fulfillment_status' => 'ready']);
        $response = $this->actingAs($officer)->getJson("/admin/billing/{$purchase->id}/data?section=delivery")->assertOk();
        $options = collect($response->json('status_options'))->pluck('value')->all();
        $this->assertContains('in_progress', $options);
        $this->assertNotContains('provider_review', $options);
        $this->assertNotContains('completed', $options);
        $this->patchJson("/admin/billing/{$purchase->id}/fulfillment", ['fulfillment_status' => 'completed'])->assertUnprocessable();
        $saved = $this->patchJson("/admin/billing/{$purchase->id}/fulfillment", ['fulfillment_status' => 'in_progress'])->assertOk();
        $this->assertContains('provider_review', collect($saved->json('purchase.delivery_status_options'))->pluck('value')->all());
    }

    public function test_rejected_information_request_does_not_leave_an_orphan_provider_update(): void
    {
        [$officer, , $purchase] = $this->requestRecord();
        $purchase->update(['fulfillment_status' => 'completed']);
        $this->actingAs($officer)->postJson("/admin/billing/{$purchase->id}/updates", [
            'kind' => 'clarification_request', 'message' => 'This transition is not available for completed work.',
        ])->assertUnprocessable();
        $this->assertSame(0, $purchase->updates()->count());
        $this->assertSame('completed', $purchase->fresh()->fulfillment_status);
    }

    public function test_non_billing_roles_and_pending_cancellation_cannot_change_delivery(): void
    {
        [$officer, , $purchase] = $this->requestRecord();
        $other = User::factory()->create(['role' => 'admin', 'parent_account_id' => $officer->parent_account_id, 'permissions' => ['manage_reports']]);
        $this->actingAs($other)->patchJson("/admin/billing/{$purchase->id}/fulfillment", ['fulfillment_status' => 'in_progress'])->assertForbidden();
        $purchase->update(['cancellation_status' => 'requested']);
        $this->actingAs($officer)->patchJson("/admin/billing/{$purchase->id}/fulfillment", ['fulfillment_status' => 'in_progress'])->assertUnprocessable();
    }

    private function requestRecord(): array
    {
        $root = User::factory()->create(['role' => 'admin']);
        $officer = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_billing']]);
        $provider = User::factory()->create(['role' => 'provider']);
        $purchase = ProviderServicePurchase::create([
            'provider_id' => $provider->id, 'created_by' => $provider->id, 'plan_code' => 'assisted_setup',
            'plan_name' => 'Assisted program setup', 'amount' => 75000, 'currency' => 'PHP',
            'status' => 'paid', 'fulfillment_status' => 'in_progress', 'paid_at' => now()->subDays(10),
            'reference_number' => 'SP-REFINE-TEST', 'service_terms_accepted_at' => now()->subDays(10),
        ]);

        return [$officer, $provider, $purchase];
    }
}
