<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\ServiceFinanceReview;
use App\Models\User;
use App\Queries\Admin\BillingWorkQueue;
use App\Queries\Admin\FinanceRecordQuery;
use App\Services\ServiceFinanceCoordination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminServiceFinanceCoordinationTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $billing;

    private User $provider;

    private ProviderServicePurchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        $root = User::factory()->create(['role' => 'admin']);
        $this->finance = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['view_finance']]);
        $this->billing = User::factory()->create(['role' => 'admin', 'parent_account_id' => $root->id, 'permissions' => ['manage_billing']]);
        $this->provider = User::factory()->create(['role' => 'provider']);
        $this->provider->providerProfile()->update(['verification_status' => 'approved']);
        $this->purchase = ProviderServicePurchase::create([
            'provider_id' => $this->provider->id, 'created_by' => $this->provider->id,
            'plan_code' => 'coordination', 'plan_name' => 'Program setup assistance',
            'reference_number' => 'COORDINATION-001', 'amount' => 950000, 'currency' => 'PHP',
            'status' => 'paid', 'fulfillment_status' => 'completed', 'paid_at' => now()->subDays(3),
            'fulfilled_at' => now()->subDay(), 'closed_at' => now()->subDay(), 'payment_id' => 'pay_original',
        ]);
    }

    public function test_finance_billing_round_trip_does_not_change_payment_delivery_or_refund_records(): void
    {
        $original = $this->purchase->fresh()->getAttributes();
        $this->review('request_billing')->assertOk()->assertJsonPath('record.coordination.owner', 'Billing Officer');
        $this->actingAs($this->billing)->getJson('/admin/workspaces/billing/queue-data?queue=finance')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.id', $this->purchase->id)
            ->assertJsonPath('items.0.state', 'completed')->assertJsonPath('items.0.finance_follow_up', true);
        $summary = app(BillingWorkQueue::class)->summary();
        $this->assertSame($this->purchase->id, $summary['next_request']['id']);
        $this->respond()->assertOk()->assertJsonPath('coordination.owner', 'Finance Officer');
        $this->actingAs($this->finance)->getJson('/admin/workspaces/finance/exceptions/data?status=follow_up')
            ->assertOk()->assertJsonPath('items.0.exception_label', 'Returned to Finance');
        $this->review('record_check')->assertOk()->assertJsonPath('record.coordination.active_review', null)
            ->assertJsonPath('record.coordination.check_is_current', true);
        $this->assertSame($original, $this->purchase->fresh()->getAttributes());
        $this->assertSame(0, app(FinanceRecordQuery::class)->summary()['exceptions']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'service_finance_billing_responded']);
        $this->assertSame('Internal question about the source record.', ServiceFinanceReview::sole()->request_note);
        $this->assertSame('The original payment reference was checked; no refund has been issued.', ServiceFinanceReview::sole()->billing_response);
    }

    public function test_cancelled_service_with_pending_refund_stays_visible_after_a_financial_check(): void
    {
        $this->purchase->update(['fulfillment_status' => 'cancelled', 'cancellation_status' => 'approved', 'refund_status' => 'manual_refund_pending']);
        $this->actingAs($this->finance)->getJson('/admin/workspaces/finance/exceptions/data?status=refund')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.exception_label', 'Refund pending');
        $this->review('record_check')->assertOk();
        $this->getJson('/admin/workspaces/finance/summary')->assertOk()
            ->assertJsonPath('summary.exceptions', 1)->assertJsonPath('summary.pending_refunds', 1);
        $this->assertSame('manual_refund_pending', $this->purchase->fresh()->refund_status);
        $this->assertNull($this->purchase->fresh()->refund_reference);
        $this->assertSame('paid', $this->purchase->fresh()->status);
    }

    public function test_paid_status_without_confirmation_date_is_visible_but_never_gets_a_receipt(): void
    {
        $this->purchase->update(['paid_at' => null]);
        $this->actingAs($this->finance)->getJson('/admin/workspaces/finance/exceptions/data?status=unconfirmed')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.exception_label', 'Confirmation incomplete');
        $this->getJson($this->recordUrl())->assertOk()->assertJsonPath('record.receipt_url', null);
        $this->review('record_check')->assertOk();
        $this->getJson("/admin/workspaces/finance/receipts/{$this->purchase->id}/data")->assertNotFound();
        $this->assertSame(1, app(FinanceRecordQuery::class)->summary()['exceptions']);
        $this->assertSame(0, app(FinanceRecordQuery::class)->summary()['lifetime']);
    }

    public function test_pending_or_failed_payments_can_be_reviewed_but_never_manually_confirmed_or_sent_to_delivery(): void
    {
        foreach (['pending', 'failed'] as $status) {
            $this->purchase->update(['status' => $status, 'paid_at' => null, 'fulfillment_status' => 'queued']);
            $this->review('request_billing')->assertStatus(409);
            $this->review('record_check')->assertOk()->assertJsonPath('record.payment_status', $status);
            $this->assertNull($this->purchase->fresh()->paid_at);
            $this->assertSame('queued', $this->purchase->fresh()->fulfillment_status);
        }
    }

    public function test_duplicate_handoffs_and_double_billing_responses_are_rejected(): void
    {
        $old = $this->token();
        $this->review('request_billing')->assertOk();
        $this->review('request_billing')->assertStatus(409);
        $this->actingAs($this->finance)->postJson($this->reviewUrl(), ['action' => 'record_check', 'note' => 'An old form tried to overwrite the record.', 'expected_finance_token' => $old])->assertStatus(409);
        $this->respond()->assertOk();
        $this->respond()->assertStatus(409);
        $this->assertDatabaseCount('service_finance_reviews', 1);
    }

    public function test_source_changes_make_old_forms_stale_and_previous_checks_no_longer_current(): void
    {
        $this->review('record_check')->assertOk();
        $token = $this->token();
        $this->purchase->update(['refund_status' => 'manual_refund_pending']);
        $this->actingAs($this->finance)->postJson($this->reviewUrl(), ['action' => 'record_check', 'note' => 'Old form should not check new refund details.', 'expected_finance_token' => $token])->assertStatus(409);
        $this->getJson($this->recordUrl())->assertOk()->assertJsonPath('record.coordination.check_is_current', false);
        $this->assertDatabaseCount('service_finance_reviews', 1);
    }

    public function test_billing_source_corrections_remain_part_of_the_same_handoff(): void
    {
        $this->review('request_billing')->assertOk();
        $this->purchase->update(['refund_reference' => 'corrected-reference']);
        $this->respond()->assertOk()->assertJsonPath('coordination.source_changed', true);
        $this->review('record_check')->assertOk()->assertJsonPath('record.coordination.check_is_current', true);
    }

    public function test_specialists_and_portal_manager_cannot_take_each_others_actions(): void
    {
        $this->actingAs($this->billing)->getJson($this->recordUrl())->assertForbidden();
        $this->postJson($this->reviewUrl(), [])->assertForbidden();
        $this->actingAs($this->finance)->patchJson($this->responseUrl(), [])->assertForbidden();
        $this->actingAs($this->provider)->getJson($this->recordUrl())->assertForbidden();
        $this->postJson($this->reviewUrl(), [])->assertForbidden();
        $manager = User::factory()->create(['role' => 'admin', 'parent_account_id' => $this->finance->parent_account_id, 'account_title' => 'Portal manager', 'permissions' => User::ADMIN_PERMISSIONS]);
        $this->actingAs($manager)->getJson($this->recordUrl())->assertOk()->assertJsonPath('record.can_review', false);
        $this->postJson($this->reviewUrl(), [])->assertForbidden();
        $this->patchJson($this->responseUrl(), [])->assertForbidden();
    }

    public function test_internal_notes_do_not_leak_into_provider_service_updates_or_history(): void
    {
        $this->review('request_billing')->assertOk();
        $this->respond()->assertOk();
        $this->actingAs($this->provider)->getJson("/provider/billing/{$this->purchase->id}/data?section=history")
            ->assertOk()->assertJsonMissingPath('purchase.finance_coordination')
            ->assertDontSee('Internal question about the source record.')
            ->assertDontSee('The original payment reference was checked; no refund has been issued.');
        $this->assertSame(0, $this->purchase->updates()->count());
        $this->assertSame(0, $this->purchase->lifecycleEvents()->count());
        $this->assertArrayNotHasKey('note', ActivityLog::where('action', 'service_finance_request_billing')->sole()->metadata);
    }

    public function test_note_and_snapshot_are_required_and_arbitrary_financial_changes_are_ignored(): void
    {
        $this->actingAs($this->finance)->postJson($this->reviewUrl(), ['action' => 'record_check', 'note' => 'Short'])->assertUnprocessable();
        $this->postJson($this->reviewUrl(), ['action' => 'mark_paid', 'note' => 'Invalid financial action.', 'expected_finance_token' => $this->token()])->assertUnprocessable();
        $amount = $this->purchase->amount;
        $this->postJson($this->reviewUrl(), ['action' => 'record_check', 'note' => 'Reviewed the original payment details.', 'expected_finance_token' => $this->token(), 'amount' => 1, 'status' => 'failed', 'refund_status' => 'refunded'])->assertOk();
        $this->assertSame($amount, $this->purchase->fresh()->amount);
        $this->assertSame('paid', $this->purchase->fresh()->status);
        $this->assertNull($this->purchase->fresh()->refund_status);
    }

    public function test_exception_counts_and_filters_count_each_purchase_only_once(): void
    {
        $this->purchase->update(['refund_status' => 'manual_refund_pending', 'paid_at' => null]);
        $this->review('request_billing')->assertOk();
        $this->actingAs($this->finance)->getJson('/admin/workspaces/finance/exceptions/data')
            ->assertOk()->assertJsonPath('pagination.total', 1);
        $this->getJson('/admin/workspaces/finance/summary')->assertJsonPath('summary.exceptions', 1);
        $this->getJson('/admin/workspaces/finance/exceptions/data?status=pending')->assertJsonPath('pagination.total', 0);
        $this->getJson('/admin/workspaces/finance/exceptions/data?status=follow_up')->assertJsonPath('pagination.total', 1);
    }

    private function token(): string
    {
        return app(ServiceFinanceCoordination::class)->payload($this->purchase->fresh())['token'];
    }

    private function recordUrl(): string
    {
        return "/admin/workspaces/finance/records/{$this->purchase->id}/data";
    }

    private function reviewUrl(): string
    {
        return "/admin/workspaces/finance/records/{$this->purchase->id}/review";
    }

    private function responseUrl(): string
    {
        return "/admin/workspaces/billing/requests/{$this->purchase->id}/finance-response";
    }

    private function review(string $action)
    {
        return $this->actingAs($this->finance)->postJson($this->reviewUrl(), ['action' => $action, 'note' => 'Internal question about the source record.', 'expected_finance_token' => $this->token()]);
    }

    private function respond()
    {
        return $this->actingAs($this->billing)->patchJson($this->responseUrl(), ['note' => 'The original payment reference was checked; no refund has been issued.', 'expected_finance_token' => $this->token()]);
    }
}
