<?php

namespace Tests\Feature;

use App\Models\ProviderServicePurchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderServiceReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        CarbonImmutable::setTestNow('2026-10-19 08:30:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_provider_action_reminders_only_reach_billing_users_and_are_idempotent(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $billingStaff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'permissions' => ['manage_billing'],
        ]);
        $programStaff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'permissions' => ['manage_programs'],
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $purchase = $this->purchase($provider, [
            'fulfillment_status' => 'needs_information',
            'current_action_owner' => 'provider',
            'current_action' => 'Provide requested information',
            'action_due_at' => now()->addDay(),
        ]);

        $this->artisan('provider-services:send-reminders')->assertSuccessful();
        $this->artisan('provider-services:send-reminders')->assertSuccessful();

        foreach ([$provider, $billingStaff] as $recipient) {
            $this->assertDatabaseHas('portal_notifications', [
                'user_id' => $recipient->id,
                'type' => 'provider_service_action_reminder',
                'action_url' => "/provider/billing/{$purchase->id}/updates",
            ]);
        }
        $this->assertDatabaseMissing('portal_notifications', ['user_id' => $programStaff->id]);
        $this->assertDatabaseMissing('portal_notifications', ['user_id' => $admin->id]);
        $this->assertDatabaseCount('portal_notifications', 2);
    }

    public function test_overdue_platform_action_only_alerts_the_assignee(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $assignee = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $purchase = $this->purchase($provider, [
            'assigned_to' => $assignee->id,
            'current_action_owner' => 'platform',
            'current_action' => 'Continue service delivery',
            'action_due_at' => now()->subDays(3),
        ]);

        $this->artisan('provider-services:send-reminders')->assertSuccessful();

        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $assignee->id,
            'type' => 'provider_service_action_overdue',
            'action_url' => "/admin/workspaces/billing/requests/{$purchase->id}/delivery",
        ]);
        $this->assertDatabaseMissing('portal_notifications', ['user_id' => $otherAdmin->id]);
        $this->assertDatabaseMissing('portal_notifications', ['user_id' => $provider->id]);
    }

    public function test_confirmed_meeting_reminds_both_sides_with_exact_meeting_links(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $billingStaff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'permissions' => ['manage_billing'],
        ]);
        $assignee = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $purchase = $this->purchase($provider, [
            'assigned_to' => $assignee->id,
            'action_due_at' => now()->addDays(8),
            'meeting_status' => 'confirmed',
            'meeting_mode' => 'online',
            'meeting_scheduled_for' => now()->addDay()->setTime(10, 0),
            'meeting_join_url' => 'https://meet.example.test/provider-service',
        ]);

        $this->artisan('provider-services:send-reminders')->assertSuccessful();
        $this->artisan('provider-services:send-reminders')->assertSuccessful();

        foreach ([$provider, $billingStaff] as $recipient) {
            $this->assertDatabaseHas('portal_notifications', [
                'user_id' => $recipient->id,
                'type' => 'provider_service_meeting_reminder',
                'action_url' => "/provider/billing/{$purchase->id}/meeting",
            ]);
        }
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $assignee->id,
            'type' => 'provider_service_meeting_reminder',
            'action_url' => "/admin/workspaces/billing/requests/{$purchase->id}/meeting",
        ]);
        $this->assertDatabaseMissing('portal_notifications', ['user_id' => $otherAdmin->id]);
        $this->assertDatabaseCount('portal_notifications', 3);
    }

    /** @param array<string, mixed> $attributes */
    private function purchase(User $provider, array $attributes): ProviderServicePurchase
    {
        return ProviderServicePurchase::create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Assisted program setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'paid',
            'fulfillment_status' => 'in_progress',
            'current_action_owner' => 'platform',
            'current_action' => 'Continue service delivery',
            'action_due_at' => now()->addDays(5),
            'reference_number' => 'SP-REMINDER-'.str_pad((string) $provider->id, 5, '0', STR_PAD_LEFT),
            'service_terms_accepted_at' => now(),
            'paid_at' => now()->subDay(),
            ...$attributes,
        ]);
    }
}
