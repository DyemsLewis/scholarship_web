<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\SupportAccountHandoff;
use App\Models\User;
use Database\Seeders\AdminProcessHandoffDemoSeeder;
use Database\Seeders\FreshWorkflowDemoSeeder;
use Database\Seeders\ProcessWorkflowDemoEnrichmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProcessHandoffDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_guide_handoffs_are_actionable_additive_and_do_not_complete_current_tester_actions(): void
    {
        Storage::fake('local');
        $this->seed([FreshWorkflowDemoSeeder::class, ProcessWorkflowDemoEnrichmentSeeder::class]);
        $accounts = DB::table('users')->orderBy('id')->get()->toJson();
        $purchases = DB::table('provider_service_purchases')->orderBy('id')->get()->keyBy('id');
        $this->seed(AdminProcessHandoffDemoSeeder::class);
        $this->assertSame($accounts, DB::table('users')->orderBy('id')->get()->toJson());
        foreach ($purchases as $id => $purchase) $this->assertEquals($purchase, DB::table('provider_service_purchases')->find($id));
        $this->assertDatabaseHas('support_account_handoffs', ['status' => 'pending']);
        $this->assertDatabaseHas('support_account_handoffs', ['status' => 'completed']);
        $this->assertDatabaseHas('service_finance_reviews', ['state' => 'with_billing']);
        $this->assertDatabaseHas('service_finance_reviews', ['state' => 'with_finance']);
        foreach (SupportAccountHandoff::all() as $handoff) {
            $this->assertSame('open', $handoff->report->admin_status);
            $this->assertTrue($handoff->user->isActive());
        }
        foreach (ProviderServicePurchase::where('reference_number', 'like', 'GUIDE-20261113-%')->get() as $purchase) {
            $this->assertSame('paid', $purchase->status);
            $this->assertSame('in_progress', $purchase->fulfillment_status);
            $this->assertFalse($purchase->livemode);
        }
        $counts = [ActivityLog::count(), SupportAccountHandoff::count(), ProviderServicePurchase::count()];
        $this->seed(AdminProcessHandoffDemoSeeder::class);
        $this->assertSame($counts, [ActivityLog::count(), SupportAccountHandoff::count(), ProviderServicePurchase::count()]);
        foreach (['novacore.accounts' => '/admin/workspaces/accounts/data?queue=attention',
            'novacore.support' => '/admin/workspaces/support/data', 'novacore.finance' => '/admin/workspaces/finance/exceptions/data'] as $username => $path) {
            $this->actingAs(User::where('username', $username)->sole())->getJson($path)->assertSuccessful();
        }
    }
}
