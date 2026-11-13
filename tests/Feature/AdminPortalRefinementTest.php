<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\User;
use App\Queries\Admin\PortalManagerWorkspaceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPortalRefinementTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_oversight_preserves_total_exceptions_beyond_the_preview_limit(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        foreach (range(1, 11) as $number) {
            $this->purchase($provider, 'pending', 'EXCEPTION-'.$number);
        }
        $data = app(PortalManagerWorkspaceQuery::class)->finance();
        $this->assertSame(11, $data['summary']['exceptions']);
        $this->assertCount(8, $data['exceptions']['items']);
        $this->assertSame(11, $data['exceptions']['pagination']['total']);
        $this->assertEmpty($data['payments']['items']);
    }

    public function test_oversight_has_no_specialist_action_or_export_links_in_nested_summaries(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $this->purchase($provider, 'paid', 'PAID-SERVICE');
        ActivityLog::create(['action' => 'records_users_exported', 'description' => 'Export requested.']);
        $query = app(PortalManagerWorkspaceQuery::class);
        $services = $query->services();
        $this->assertArrayNotHasKey('action_url', $services['summary']['next_request']);
        $this->assertArrayNotHasKey('action_url', $services['queues']['ready']['items'][0]);
        $records = $query->records();
        $this->assertArrayNotHasKey('record_url', $records['exports']['recent_exports'][0]);
        foreach ($records['exports']['datasets'] as $dataset) {
            $this->assertArrayNotHasKey('download_url', $dataset);
        }
    }

    public function test_managed_provider_access_exceptions_use_the_organization_logo(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $provider->providerProfile()->update(['logo_path' => 'uploads/demo-organization.png']);
        $staff = User::factory()->create(['role' => 'provider', 'parent_account_id' => $provider->id, 'must_reset_password' => true]);
        $users = app(PortalManagerWorkspaceQuery::class)->accounts()['users'];
        $item = $users->firstWhere('id', $staff->id);
        $this->assertSame(asset('uploads/demo-organization.png'), $item['image_url']);
        $this->assertSame('contain', $item['image_fit']);
    }

    public function test_account_preview_total_is_not_limited_to_the_twelve_shown_rows(): void
    {
        User::factory()->count(15)->create(['must_reset_password' => true]);
        $data = app(PortalManagerWorkspaceQuery::class)->accounts();
        $this->assertCount(12, $data['users']);
        $this->assertSame(15, $data['summary']['attention_total']);
        $this->assertLessThan($data['users'][1]['id'], $data['users'][0]['id']);
    }

    private function purchase(User $provider, string $status, string $reference): ProviderServicePurchase
    {
        return ProviderServicePurchase::create([
            'provider_id' => $provider->id, 'created_by' => $provider->id, 'plan_code' => 'service', 'plan_name' => 'Demo service',
            'amount' => 10000, 'currency' => 'PHP', 'status' => $status, 'reference_number' => $reference,
            'fulfillment_status' => 'ready', 'paid_at' => $status === 'paid' ? now() : null,
        ]);
    }
}
