<?php

namespace Tests\Feature;

use App\Models\ProviderServicePurchase;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_finance_area_is_limited_to_super_admin_and_delegated_finance_staff(): void
    {
        $superAdmin = User::factory()->create(['role' => 'admin']);
        $financeStaff = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $superAdmin->id,
            'account_title' => 'Finance officer',
            'permissions' => ['view_finance'],
        ]);
        $billingStaff = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $superAdmin->id,
            'account_title' => 'Billing officer',
            'permissions' => ['manage_billing'],
        ]);
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($superAdmin)->get('/admin/finance')->assertOk();
        $this->actingAs($financeStaff)->get('/admin/finance')->assertOk();
        $this->actingAs($financeStaff)->getJson('/admin/finance/data')->assertOk();
        $this->actingAs($billingStaff)->get('/admin/finance')->assertForbidden();
        $this->actingAs($billingStaff)->getJson('/admin/finance/data')->assertForbidden();
        $this->actingAs($provider)->get('/admin/finance')->assertForbidden();
    }

    public function test_finance_summary_and_receipts_only_count_confirmed_payments(): void
    {
        Carbon::setTestNow('2026-09-07 10:00:00');

        $admin = User::factory()->create(['role' => 'admin']);
        $provider = User::factory()->create(['role' => 'provider']);

        $this->purchase($provider, 'Today service', 100000, 'paid', now()->subHour());
        $this->purchase($provider, 'Earlier this month', 200000, 'paid', now()->subDays(3));
        $this->purchase($provider, 'Previous month', 300000, 'paid', now()->subMonth());
        $this->purchase($provider, 'Pending checkout', 900000, 'pending');
        $this->purchase($provider, 'Failed checkout', 800000, 'failed');

        $this->actingAs($admin)
            ->getJson('/admin/finance/data?period=month')
            ->assertOk()
            ->assertJsonPath('summary.today', 100000)
            ->assertJsonPath('summary.month', 300000)
            ->assertJsonPath('summary.lifetime', 600000)
            ->assertJsonPath('summary.successful_payments', 3)
            ->assertJsonPath('summary.pending_payments', 1)
            ->assertJsonPath('summary.failed_payments', 1)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonCount(2, 'receipts')
            ->assertJsonPath('receipts.0.service', 'Today service');

        $this->actingAs($admin)
            ->getJson('/admin/finance/data')
            ->assertOk()
            ->assertJsonPath('selected_period', 'all')
            ->assertJsonPath('pagination.total', 3)
            ->assertJsonCount(3, 'receipts');
    }

    private function purchase(
        User $provider,
        string $name,
        int $amount,
        string $status,
        $paidAt = null,
    ): ProviderServicePurchase {
        return ProviderServicePurchase::create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => str($name)->slug('_')->toString(),
            'plan_name' => $name,
            'amount' => $amount,
            'currency' => 'PHP',
            'status' => $status,
            'fulfillment_status' => 'queued',
            'reference_number' => 'TEST-'.str()->random(12),
            'payment_method' => $status === 'paid' ? 'gcash' : null,
            'paid_at' => $paidAt,
        ]);
    }
}
