<?php

namespace Tests\Feature;

use App\Models\ProviderServicePurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProviderBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'billing.enabled' => true,
            'billing.currency' => 'PHP',
            'billing.paymongo.base_url' => 'https://api.paymongo.com',
            'billing.paymongo.secret_key' => 'sk_test_portal',
            'billing.paymongo.webhook_secret' => 'whsec_portal',
            'billing.paymongo.payment_methods' => ['card', 'gcash', 'qrph'],
            'billing.paymongo.signature_tolerance_seconds' => 300,
            'billing.plans.assisted_setup.amount' => 75000,
        ]);
    }

    public function test_only_authorized_provider_accounts_can_access_optional_services(): void
    {
        $applicant = User::factory()->create();
        $provider = User::factory()->create(['role' => 'provider']);
        $billingStaff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'billing_staff',
            'permissions' => ['manage_billing'],
        ]);
        $programStaff = User::factory()->create([
            'role' => 'provider',
            'parent_account_id' => $provider->id,
            'account_title' => 'program_coordinator',
            'permissions' => ['manage_programs'],
        ]);

        $this->actingAs($applicant)->get('/provider/billing')->assertForbidden();
        $this->actingAs($provider)->get('/provider/billing')->assertOk();
        $this->actingAs($billingStaff)->get('/provider/billing')->assertOk();
        $this->actingAs($programStaff)->get('/provider/billing')->assertForbidden();
    }

    public function test_provider_checkout_uses_paymongo_v2_and_stores_a_pending_order(): void
    {
        Http::fake([
            'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_test_checkout',
                    'type' => 'checkout_session',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_test_checkout',
                        'livemode' => false,
                    ],
                ],
            ]),
        ]);
        $provider = User::factory()->create(['role' => 'provider']);

        $response = $this->actingAs($provider)->postJson('/provider/billing/checkout', [
            'plan_code' => 'assisted_setup',
            'accept_terms' => true,
        ])->assertCreated()
            ->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/cs_test_checkout')
            ->assertJsonPath('purchase.status', 'pending');

        $purchase = ProviderServicePurchase::query()->findOrFail($response->json('purchase.id'));

        $this->assertSame($provider->id, $purchase->provider_id);
        $this->assertSame(75000, $purchase->amount);
        $this->assertSame('PHP', $purchase->currency);
        $this->assertSame('cs_test_checkout', $purchase->checkout_session_id);
        $this->assertNotNull($purchase->service_terms_accepted_at);

        Http::assertSent(function (ClientRequest $request) use ($purchase): bool {
            return $request->url() === 'https://api.paymongo.com/v2/checkout_sessions'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_portal:'))
                && $request['data']['attributes']['line_items'][0]['amount'] === 75000
                && $request['data']['attributes']['line_items'][0]['currency'] === 'PHP'
                && $request['data']['attributes']['reference_number'] === $purchase->reference_number
                && $request['data']['attributes']['metadata']['purchase_id'] === (string) $purchase->id
                && $request['data']['attributes']['pass_on_fees'] === false;
        });
    }

    public function test_checkout_requires_optional_service_terms_acceptance(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)->postJson('/provider/billing/checkout', [
            'plan_code' => 'assisted_setup',
            'accept_terms' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('accept_terms');

        Http::assertNothingSent();
        $this->assertDatabaseCount('provider_service_purchases', 0);
    }

    public function test_valid_signed_paid_webhook_confirms_payment_once_and_notifies_both_roles(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $purchase = $this->pendingPurchase($provider);
        [$payload, $json] = $this->paidWebhookPayload($purchase);
        $signature = $this->signatureFor($json);

        $this->postRawWebhook($json, $signature)->assertOk()->assertJsonPath('received', true);

        $purchase->refresh();
        $this->assertSame('paid', $purchase->status);
        $this->assertSame('pay_test_paid', $purchase->payment_id);
        $this->assertSame('gcash', $purchase->payment_method);
        $this->assertNotNull($purchase->paid_at);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $provider->id,
            'type' => 'provider_service_paid',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $admin->id,
            'type' => 'provider_service_queue',
        ]);

        $this->postRawWebhook($json, $signature)
            ->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('portal_notifications', 2);
    }

    public function test_webhook_rejects_invalid_signatures_and_amount_mismatches(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $purchase = $this->pendingPurchase($provider);
        [, $json] = $this->paidWebhookPayload($purchase);

        $this->postRawWebhook($json, 't='.now()->timestamp.',te=invalid')
            ->assertUnauthorized();
        $this->assertSame('pending', $purchase->fresh()->status);

        [, $wrongAmountJson] = $this->paidWebhookPayload($purchase, 74999);
        $this->postRawWebhook($wrongAmountJson, $this->signatureFor($wrongAmountJson))
            ->assertUnprocessable();
        $this->assertSame('pending', $purchase->fresh()->status);
    }

    public function test_billing_admin_can_track_fulfillment_and_provider_is_notified(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $primaryAdmin = User::factory()->create(['role' => 'admin']);
        $billingAdmin = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Billing officer',
            'permissions' => ['manage_billing'],
        ]);
        $reviewAdmin = User::factory()->create([
            'role' => 'admin',
            'parent_account_id' => $primaryAdmin->id,
            'account_title' => 'Review officer',
            'permissions' => ['manage_reviews'],
        ]);
        $purchase = $this->pendingPurchase($provider);
        $purchase->update(['status' => 'paid', 'paid_at' => now()]);

        $this->actingAs($reviewAdmin)->get('/admin/billing')->assertForbidden();
        $this->actingAs($billingAdmin)->get('/admin/billing')->assertOk();
        $this->actingAs($billingAdmin)
            ->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
                'fulfillment_status' => 'in_progress',
                'fulfillment_notes' => 'Setup call is scheduled with the provider.',
            ])->assertOk()
            ->assertJsonPath('purchase.fulfillment_status', 'in_progress');

        $this->assertDatabaseHas('provider_service_purchases', [
            'id' => $purchase->id,
            'fulfillment_status' => 'in_progress',
            'fulfilled_by' => $billingAdmin->id,
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $provider->id,
            'type' => 'provider_service_status',
        ]);
    }

    private function pendingPurchase(User $provider): ProviderServicePurchase
    {
        return ProviderServicePurchase::create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Assisted program setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'pending',
            'fulfillment_status' => 'queued',
            'reference_number' => 'SP-TEST-'.str_pad((string) $provider->id, 5, '0', STR_PAD_LEFT),
            'checkout_session_id' => 'cs_test_'.str_pad((string) $provider->id, 5, '0', STR_PAD_LEFT),
            'service_terms_accepted_at' => now(),
        ]);
    }

    /**
     * @return array{0: array, 1: string}
     */
    private function paidWebhookPayload(ProviderServicePurchase $purchase, int $amount = 75000): array
    {
        $payload = [
            'data' => [
                'id' => 'evt_test_paid',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'livemode' => false,
                    'data' => [
                        'id' => $purchase->checkout_session_id,
                        'type' => 'checkout_session',
                        'attributes' => [
                            'reference_number' => $purchase->reference_number,
                            'metadata' => [
                                'purchase_id' => (string) $purchase->id,
                                'provider_id' => (string) $purchase->provider_id,
                                'plan_code' => $purchase->plan_code,
                            ],
                            'payments' => [[
                                'id' => 'pay_test_paid',
                                'type' => 'payment',
                                'attributes' => [
                                    'status' => 'paid',
                                    'amount' => $amount,
                                    'currency' => 'PHP',
                                    'source' => ['type' => 'gcash'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [$payload, $json];
    }

    private function signatureFor(string $json): string
    {
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', "{$timestamp}.{$json}", 'whsec_portal');

        return "t={$timestamp},te={$signature}";
    }

    private function postRawWebhook(string $json, string $signature)
    {
        return $this->call('POST', '/webhooks/paymongo', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $signature,
        ], $json);
    }
}
