<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\ProviderServicePurchase;
use App\Models\SupportAccountHandoff;
use App\Models\SupportReport;
use App\Models\User;
use App\Queries\Admin\PortalManagerWorkspaceQuery;
use App\Services\ServiceFinanceCoordination;
use App\Services\SupportAccountHandoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminEfficiencyEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->root = User::factory()->create(['role' => 'admin']);
    }

    public function test_account_handoff_moves_between_live_queues_and_remains_traceable_after_resolution(): void
    {
        $support = $this->staff('Support officer', ['manage_reports']);
        $accounts = $this->staff('Account manager', ['manage_accounts']);
        $records = $this->staff('Records officer', ['view_logs']);
        $applicant = User::factory()->create(['role' => 'applicant']);
        $report = SupportReport::create([
            'applicant_id' => $applicant->id, 'assigned_role' => 'admin', 'category' => 'account',
            'subject' => 'Check sign-in access', 'description' => 'Private report for Support only.',
            'status' => 'open', 'admin_status' => 'open', 'provider_status' => 'not_required',
        ]);
        $this->assertWork('support', 1, 0);
        $this->assertWork('accounts', 0, 0);

        $this->actingAs($support)->postJson("/admin/workspaces/support/cases/{$report->id}/account-handoffs", [
            'expected_support_token' => app(SupportAccountHandoffService::class)->caseToken($report->fresh()),
            'task' => 'sign_in', 'instruction' => 'Check access without changing the profile decision.',
        ])->assertOk();
        $handoff = SupportAccountHandoff::sole();
        $this->assertWork('support', 0, 1);
        $this->assertWork('accounts', 1, 0);
        $this->actingAs($accounts)->getJson('/admin/workspaces/accounts/data?attention=support_handoff')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('users.0.id', $applicant->id);
        $this->getJson("/admin/workspaces/accounts/{$applicant->id}/data")
            ->assertOk()->assertDontSee('Private report for Support only.');
        $this->patchJson("/admin/workspaces/accounts/{$applicant->id}/support-handoffs/{$handoff->id}", [
            'expected_handoff_token' => app(SupportAccountHandoffService::class)->payload($handoff)['token'],
            'result' => 'The account is active. The account holder can sign in normally.',
        ])->assertOk();
        $this->assertWork('accounts', 0, 0);
        $this->assertWork('support', 1, 0);
        $this->getJson('/admin/workspaces/accounts/data?attention=support_handoff')
            ->assertOk()->assertJsonPath('pagination.total', 0);
        $this->actingAs($support)->getJson('/admin/workspaces/support/data?queue=needs_response')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.id', $report->id);
        $this->patchJson("/admin/reports/{$report->id}/status", [
            'status' => 'resolved', 'admin_response' => 'Your access was checked. You can sign in using your current credentials.',
            'expected_support_token' => app(SupportAccountHandoffService::class)->caseToken($report->fresh()),
        ])->assertOk();
        $this->assertWork('support', 0, 0);
        $this->actingAs($records)->getJson('/admin/workspaces/records/activity/data?period=all&record_type=support&record_id='.$report->id)
            ->assertOk()->assertJsonPath('pagination.total', 3)
            ->assertDontSee('Private report for Support only.')
            ->assertDontSee('Check access without changing the profile decision.');
        $events = ActivityLog::whereIn('action', ['support_account_handoff_requested', 'support_account_handoff_completed', 'support_report_status_updated'])->get();
        $this->assertCount(3, $events);
        foreach ($events as $event) {
            $this->assertNotEmpty($event->user_id);
        }
        $this->actingAs($applicant)->getJson('/dashboard/reports/data')
            ->assertOk()->assertDontSee('The account is active. The account holder can sign in normally.')
            ->assertSee('Your access was checked. You can sign in using your current credentials.');
    }

    public function test_finance_handoff_returns_to_finance_then_leaves_only_the_original_payment_and_audit_history(): void
    {
        $finance = $this->staff('Finance officer', ['view_finance']);
        $billing = $this->staff('Billing officer', ['manage_billing']);
        $records = $this->staff('Records officer', ['view_logs']);
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['verification_status' => 'approved']);
        $purchase = ProviderServicePurchase::create([
            'provider_id' => $provider->id, 'created_by' => $provider->id, 'plan_code' => 'end_to_end',
            'plan_name' => 'Program setup', 'reference_number' => 'END-TO-END-001', 'amount' => 950000,
            'currency' => 'PHP', 'status' => 'paid', 'paid_at' => now(), 'fulfillment_status' => 'completed',
        ]);
        $original = $purchase->fresh()->getAttributes();
        $service = app(ServiceFinanceCoordination::class);
        $this->actingAs($finance)->postJson("/admin/workspaces/finance/records/{$purchase->id}/review", [
            'action' => 'request_billing', 'note' => 'Please compare the original payment and delivery references.',
            'expected_finance_token' => $service->payload($purchase->fresh())['token'],
        ])->assertOk();
        $this->assertWork('services', 1, 0);
        $this->assertWork('finance', 0, 1);
        $this->actingAs($billing)->getJson('/admin/workspaces/billing/queue-data?queue=finance')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.id', $purchase->id);
        $this->patchJson("/admin/workspaces/billing/requests/{$purchase->id}/finance-response", [
            'note' => 'The payment and delivery references match the original source records.',
            'expected_finance_token' => $service->payload($purchase->fresh())['token'],
        ])->assertOk();
        $this->assertWork('services', 0, 0);
        $this->assertWork('finance', 1, 0);
        $this->actingAs($finance)->getJson('/admin/workspaces/finance/exceptions/data?status=needs_review')
            ->assertOk()->assertJsonPath('pagination.total', 1)->assertJsonPath('items.0.id', $purchase->id);
        $this->postJson("/admin/workspaces/finance/records/{$purchase->id}/review", [
            'action' => 'record_check', 'note' => 'Compared the response to the source. No financial correction is required.',
            'expected_finance_token' => $service->payload($purchase->fresh())['token'],
        ])->assertOk();
        $this->assertWork('finance', 0, 0);
        $this->assertSame($original, $purchase->fresh()->getAttributes());
        $this->actingAs($records)->getJson('/admin/workspaces/records/activity/data?period=all&record_type=service&record_id='.$purchase->id)
            ->assertOk()->assertJsonPath('pagination.total', 3)
            ->assertDontSee('Please compare the original payment and delivery references.');
        $this->actingAs($provider)->getJson("/provider/billing/{$purchase->id}/data?section=history")
            ->assertOk()->assertDontSee('The payment and delivery references match the original source records.');
    }

    public function test_finance_handoff_notifications_only_target_staff_who_can_open_the_task(): void
    {
        $finance = $this->staff('Finance officer', ['view_finance']);
        $billing = $this->staff('Billing officer', ['manage_billing']);
        $blockedFinance = $this->staff('Finance officer', []);
        $blockedBilling = $this->staff('Billing officer', []);
        $provider = User::factory()->create(['role' => 'provider']);
        $purchase = ProviderServicePurchase::create([
            'provider_id' => $provider->id, 'created_by' => $provider->id, 'plan_code' => 'notification',
            'plan_name' => 'Program setup', 'reference_number' => 'END-TO-END-002', 'amount' => 10000,
            'currency' => 'PHP', 'status' => 'paid', 'paid_at' => now(), 'fulfillment_status' => 'completed',
        ]);
        $service = app(ServiceFinanceCoordination::class);
        $this->actingAs($finance)->postJson("/admin/workspaces/finance/records/{$purchase->id}/review", [
            'action' => 'request_billing', 'note' => 'Please check the original payment reference.',
            'expected_finance_token' => $service->payload($purchase->fresh())['token'],
        ])->assertOk();
        $this->assertSame(0, PortalNotification::where('type', 'service_finance_review')->where('user_id', $blockedBilling->id)->count());
        $this->assertSame(1, PortalNotification::where('type', 'service_finance_review')->where('user_id', $billing->id)->count());
        $this->actingAs($billing)->patchJson("/admin/workspaces/billing/requests/{$purchase->id}/finance-response", [
            'note' => 'The payment reference agrees with the original record.',
            'expected_finance_token' => $service->payload($purchase->fresh())['token'],
        ])->assertOk();
        $this->assertSame(0, PortalNotification::where('type', 'service_finance_review')->where('user_id', $blockedFinance->id)->count());
        $this->assertSame(1, PortalNotification::where('type', 'service_finance_review')->where('user_id', $finance->id)->count());
    }

    private function staff(string $title, array $permissions): User
    {
        return User::factory()->create(['role' => 'admin', 'parent_account_id' => $this->root->id,
            'account_title' => $title, 'permissions' => $permissions]);
    }

    private function assertWork(string $key, int $actionable, int $waiting): void
    {
        $overview = app(PortalManagerWorkspaceQuery::class)->overview();
        $area = $overview['priorities']->firstWhere('key', $key);
        $this->assertSame($actionable, $area['count'], $key.' actionable work');
        $this->assertSame($waiting, $area['waiting'], $key.' waiting work');
        $this->assertSame($overview['priorities']->sum('count'), $overview['summary']['total_attention']);
        $this->assertSame($overview['priorities']->sum('waiting'), $overview['summary']['waiting_total']);
    }
}
