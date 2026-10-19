<?php

namespace Tests\Feature;

use App\Models\PortalNotification;
use App\Models\ProviderServicePurchase;
use App\Models\User;
use App\Services\ProviderServiceLifecycleService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderServiceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_and_delivery_have_separate_states_and_next_action_owners(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $lifecycle = app(ProviderServiceLifecycleService::class);
        $purchase = $this->purchase($provider);

        $purchase = $lifecycle->initialize($purchase);

        $this->assertSame('pending', $purchase->status);
        $this->assertSame('queued', $purchase->fulfillment_status);
        $this->assertSame('provider', $purchase->current_action_owner);
        $this->assertSame('Complete service payment', $purchase->current_action);

        $purchase = $lifecycle->transitionPayment(
            $purchase,
            'paid',
            null,
            ['payment_method' => 'gcash'],
            'payment_confirmed',
        );

        $this->assertSame('paid', $purchase->status);
        $this->assertSame('ready', $purchase->fulfillment_status);
        $this->assertSame('platform', $purchase->current_action_owner);
        $this->assertSame('Assign and start the service', $purchase->current_action);
        $this->assertNotNull($purchase->paid_at);

        $purchase = $lifecycle->transitionDelivery($purchase, 'in_progress', $admin);
        $this->assertSame('platform', $purchase->current_action_owner);
        $this->assertSame('Continue service delivery', $purchase->current_action);
        $this->assertNotNull($purchase->started_at);

        $purchase = $lifecycle->transitionDelivery(
            $purchase,
            'needs_information',
            $admin,
            ['fulfillment_notes' => 'Please identify the program this request covers.'],
        );
        $this->assertSame('provider', $purchase->current_action_owner);
        $this->assertSame('Provide requested information', $purchase->current_action);

        $purchase = $lifecycle->transitionDelivery($purchase, 'ready', $provider);
        $purchase = $lifecycle->transitionDelivery($purchase, 'in_progress', $admin);
        $purchase = $lifecycle->transitionDelivery($purchase, 'provider_review', $admin);

        $this->assertSame('provider', $purchase->current_action_owner);
        $this->assertSame('Review the delivered work', $purchase->current_action);

        $purchase = $lifecycle->transitionDelivery(
            $purchase,
            'in_progress',
            $provider,
            [],
            'provider_revision_requested',
        );

        $this->assertSame(1, $purchase->revision_count);
        $this->assertNotNull($purchase->reopened_at);

        $purchase = $lifecycle->transitionDelivery($purchase, 'provider_review', $admin);
        $purchase = $lifecycle->transitionDelivery(
            $purchase,
            'completed',
            $provider,
            ['provider_confirmed_at' => now()],
            'provider_completion_confirmed',
        );

        $this->assertNull($purchase->current_action_owner);
        $this->assertNull($purchase->current_action);
        $this->assertNotNull($purchase->closed_at);
        $this->assertNotNull($purchase->fulfilled_at);
        $this->assertDatabaseHas('provider_service_lifecycle_events', [
            'provider_service_purchase_id' => $purchase->id,
            'event_type' => 'payment_confirmed',
            'payment_from' => 'pending',
            'payment_to' => 'paid',
            'delivery_from' => 'queued',
            'delivery_to' => 'ready',
        ]);
        $this->assertDatabaseHas('provider_service_lifecycle_events', [
            'provider_service_purchase_id' => $purchase->id,
            'event_type' => 'provider_revision_requested',
            'delivery_from' => 'provider_review',
            'delivery_to' => 'in_progress',
        ]);
        $this->assertDatabaseHas('provider_service_lifecycle_events', [
            'provider_service_purchase_id' => $purchase->id,
            'event_type' => 'provider_completion_confirmed',
            'delivery_from' => 'provider_review',
            'delivery_to' => 'completed',
            'action_owner' => null,
        ]);
    }

    public function test_invalid_delivery_jump_is_rejected_without_changing_the_request(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $lifecycle = app(ProviderServiceLifecycleService::class);
        $purchase = $this->purchase($provider, 'paid');

        try {
            $lifecycle->transitionDelivery($purchase, 'provider_review', $provider);
            $this->fail('The invalid lifecycle transition should have been rejected.');
        } catch (DomainException $error) {
            $this->assertSame('Service delivery cannot move from ready to provider_review.', $error->getMessage());
        }

        $this->assertSame('ready', $purchase->fresh()->fulfillment_status);
        $this->assertDatabaseCount('provider_service_lifecycle_events', 0);
    }

    public function test_pending_meeting_becomes_the_current_platform_action(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $lifecycle = app(ProviderServiceLifecycleService::class);
        $purchase = $this->purchase($provider, 'paid');
        $purchase->update([
            'meeting_status' => 'requested',
            'meeting_scheduled_for' => now()->addDays(2),
            'target_due_at' => now()->addDays(4),
        ]);

        $purchase = $lifecycle->syncCurrentAction($purchase);

        $this->assertSame('platform', $purchase->current_action_owner);
        $this->assertSame('Review the meeting request', $purchase->current_action);
        $this->assertNotNull($purchase->action_due_at);
    }

    public function test_cancellation_request_takes_priority_and_cancelled_services_have_no_next_action(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $lifecycle = app(ProviderServiceLifecycleService::class);
        $purchase = $this->purchase($provider, 'paid');
        $purchase->update(['cancellation_status' => 'requested']);

        $purchase = $lifecycle->syncCurrentAction($purchase);
        $this->assertSame('platform', $purchase->current_action_owner);
        $this->assertSame('Review the cancellation request', $purchase->current_action);

        $purchase = $lifecycle->transitionDelivery($purchase, 'cancelled', $admin, [
            'cancellation_status' => 'approved',
            'refund_status' => 'not_applicable',
        ], 'service_cancellation_approved');
        $this->assertNull($purchase->current_action_owner);
        $this->assertNull($purchase->current_action);
        $this->assertNotNull($purchase->closed_at);
    }

    public function test_action_deadlines_are_contextual_and_stable_until_the_owner_changes(): void
    {
        CarbonImmutable::setTestNow('2026-10-19 09:00:00');

        try {
            $provider = User::factory()->create(['role' => 'provider']);
            $admin = User::factory()->create(['role' => 'admin']);
            $lifecycle = app(ProviderServiceLifecycleService::class);
            $purchase = $this->purchase($provider, 'paid');
            $purchase->update(['target_due_at' => now()->addDays(10)]);

            $purchase = $lifecycle->initialize($purchase);
            $this->assertTrue($purchase->action_due_at->equalTo(now()->addDays(2)));

            $stableDueAt = $purchase->action_due_at->copy();
            CarbonImmutable::setTestNow('2026-10-20 09:00:00');
            $purchase = $lifecycle->syncCurrentAction($purchase);
            $this->assertTrue($purchase->action_due_at->equalTo($stableDueAt));

            $reminder = PortalNotification::create([
                'user_id' => $admin->id,
                'type' => 'provider_service_action_reminder',
                'title' => 'Service action due soon',
                'message' => 'Start this service request.',
                'action_url' => "/admin/workspaces/billing/requests/{$purchase->id}/delivery",
                'deduplication_key' => "provider-service:{$purchase->id}:action:platform:upcoming:test",
            ]);

            $purchase = $lifecycle->transitionDelivery($purchase, 'in_progress', $admin);
            $this->assertTrue($purchase->action_due_at->equalTo(now()->addDays(5)));
            $this->assertNotNull($reminder->fresh()->read_at);

            $purchase = $lifecycle->transitionDelivery($purchase, 'needs_information', $admin);
            $this->assertSame('provider', $purchase->current_action_owner);
            $this->assertTrue($purchase->action_due_at->equalTo(now()->addDays(3)));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function purchase(User $provider, string $paymentStatus = 'pending'): ProviderServicePurchase
    {
        return ProviderServicePurchase::create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Assisted program setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => $paymentStatus,
            'fulfillment_status' => $paymentStatus === 'paid' ? 'ready' : 'queued',
            'reference_number' => 'SP-LIFECYCLE-'.str_pad((string) $provider->id, 5, '0', STR_PAD_LEFT),
            'service_terms_accepted_at' => now(),
            'paid_at' => $paymentStatus === 'paid' ? now() : null,
        ]);
    }
}
