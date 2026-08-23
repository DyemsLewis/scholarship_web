<?php

namespace Tests\Feature;

use App\Models\ProviderServicePurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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
            'request_summary' => 'We need help checking the setup of our first scholarship program.',
            'requested_outcome' => 'A reviewed program that is ready for publication.',
        ])->assertCreated()
            ->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/cs_test_checkout')
            ->assertJsonPath('purchase.status', 'pending');

        $purchase = ProviderServicePurchase::query()->findOrFail($response->json('purchase.id'));

        $this->assertSame($provider->id, $purchase->provider_id);
        $this->assertSame(75000, $purchase->amount);
        $this->assertSame('PHP', $purchase->currency);
        $this->assertSame('cs_test_checkout', $purchase->checkout_session_id);
        $this->assertNotNull($purchase->service_terms_accepted_at);
        $this->assertSame('ready', $purchase->fulfillment_status);
        $this->assertSame('We need help checking the setup of our first scholarship program.', $purchase->request_summary);
        $this->assertCount(3, $purchase->milestones);

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
            'request_summary' => 'We need help checking the setup of our first scholarship program.',
            'requested_outcome' => 'A reviewed program that is ready for publication.',
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

    public function test_provider_can_confirm_a_paid_checkout_without_a_webhook(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $purchase = $this->pendingPurchase($provider);
        [$payload] = $this->paidWebhookPayload($purchase);
        $resource = data_get($payload, 'data.attributes.data');
        $resource['attributes']['livemode'] = false;

        Http::fake([
            "https://api.paymongo.com/v1/checkout_sessions/{$purchase->checkout_session_id}" => Http::response([
                'data' => $resource,
            ]),
        ]);

        $this->actingAs($provider)
            ->postJson('/provider/billing/sync', [
                'reference' => $purchase->reference_number,
            ])
            ->assertOk()
            ->assertJsonPath('confirmed', true)
            ->assertJsonPath('purchase.status', 'paid');

        $purchase->refresh();
        $this->assertSame('paid', $purchase->status);
        $this->assertSame('pay_test_paid', $purchase->payment_id);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $provider->id,
            'type' => 'provider_service_paid',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'user_id' => $admin->id,
            'type' => 'provider_service_queue',
        ]);
        Http::assertSent(function (ClientRequest $request) use ($purchase): bool {
            return $request->method() === 'GET'
                && $request->url() === "https://api.paymongo.com/v1/checkout_sessions/{$purchase->checkout_session_id}"
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk_test_portal:'));
        });
    }

    public function test_checkout_sync_keeps_an_unpaid_session_pending(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $purchase = $this->pendingPurchase($provider);
        [$payload] = $this->paidWebhookPayload($purchase);
        $resource = data_get($payload, 'data.attributes.data');
        $resource['attributes']['livemode'] = false;
        $resource['attributes']['payments'][0]['attributes']['status'] = 'pending';

        Http::fake([
            "https://api.paymongo.com/v1/checkout_sessions/{$purchase->checkout_session_id}" => Http::response([
                'data' => $resource,
            ]),
        ]);

        $this->actingAs($provider)
            ->postJson('/provider/billing/sync', [
                'reference' => $purchase->reference_number,
            ])
            ->assertStatus(202)
            ->assertJsonPath('confirmed', false)
            ->assertJsonPath('purchase.status', 'pending');

        $this->assertSame('pending', $purchase->fresh()->status);
        $this->assertDatabaseCount('portal_notifications', 0);
    }

    public function test_provider_cannot_sync_another_providers_checkout(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $purchase = $this->pendingPurchase($provider);

        Http::fake();

        $this->actingAs($otherProvider)
            ->postJson('/provider/billing/sync', [
                'reference' => $purchase->reference_number,
            ])
            ->assertNotFound();

        $this->assertSame('pending', $purchase->fresh()->status);
        Http::assertNothingSent();
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

    public function test_service_workspace_supports_assignment_updates_files_and_private_admin_notes(): void
    {
        Storage::fake('local');

        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $purchase = $this->pendingPurchase($provider);
        $purchase->update([
            'status' => 'paid',
            'paid_at' => now(),
            'fulfillment_status' => 'ready',
            'request_summary' => 'Our team needs help reviewing a new scholarship program workflow.',
            'requested_outcome' => 'A clear and publication-ready program setup.',
            'milestones' => [
                ['id' => 'milestone_1', 'label' => 'Program form walkthrough', 'completed' => false],
                ['id' => 'milestone_2', 'label' => 'Publishing-readiness check', 'completed' => false],
            ],
        ]);

        $this->actingAs($otherProvider)
            ->getJson("/provider/billing/{$purchase->id}/data")
            ->assertForbidden();

        $this->actingAs($admin)
            ->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
                'fulfillment_status' => 'in_progress',
                'assigned_to' => $admin->id,
                'priority' => 'high',
                'target_due_at' => now()->addWeek()->toDateString(),
                'milestones' => [
                    ['id' => 'milestone_1', 'label' => 'Program form walkthrough', 'completed' => true],
                    ['id' => 'milestone_2', 'label' => 'Publishing-readiness check', 'completed' => false],
                ],
                'fulfillment_notes' => 'The first review is underway.',
                'provider_update' => 'We completed the initial walkthrough and are checking publication readiness.',
                'internal_note' => 'Coordinate the final check with the portal manager.',
            ])
            ->assertOk()
            ->assertJsonPath('purchase.assigned_to', $admin->id)
            ->assertJsonPath('purchase.priority', 'high')
            ->assertJsonCount(2, 'purchase.updates');

        $providerWorkspace = $this->actingAs($provider)
            ->getJson("/provider/billing/{$purchase->id}/data")
            ->assertOk()
            ->assertJsonPath('purchase.assigned_to', $admin->id)
            ->assertJsonCount(1, 'purchase.updates')
            ->assertJsonMissing(['message' => 'Coordinate the final check with the portal manager.']);

        $this->assertSame('progress_update', $providerWorkspace->json('purchase.updates.0.kind'));

        $providerFileResponse = $this->actingAs($provider)
            ->post("/provider/billing/{$purchase->id}/files", [
                'service_file' => UploadedFile::fake()->create('program-draft.pdf', 100, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonFragment(['category' => 'supporting']);

        $providerFile = collect($providerFileResponse->json('purchase.files'))->firstWhere('category', 'supporting');

        $this->actingAs($admin)
            ->get($providerFile['view_url'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($otherProvider)
            ->get($providerFile['view_url'])
            ->assertForbidden();

        $deliverableResponse = $this->actingAs($admin)
            ->post("/admin/billing/{$purchase->id}/deliverables", [
                'service_file' => UploadedFile::fake()->create('review-recommendations.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonFragment(['category' => 'deliverable']);

        $deliverable = collect($deliverableResponse->json('purchase.files'))->firstWhere('category', 'deliverable');

        $this->actingAs($provider)
            ->get($deliverable['view_url'])
            ->assertOk();
    }

    public function test_provider_controls_completion_and_can_reopen_unresolved_service_work(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $purchase = $this->pendingPurchase($provider);
        $purchase->update([
            'status' => 'paid',
            'paid_at' => now(),
            'fulfillment_status' => 'in_progress',
            'request_summary' => 'Review our application workflow and identify setup issues.',
            'requested_outcome' => 'A workflow ready for our next application cycle.',
            'milestones' => [
                ['id' => 'milestone_1', 'label' => 'Workflow review', 'completed' => false],
            ],
        ]);

        $this->actingAs($admin)
            ->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
                'fulfillment_status' => 'completed',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fulfillment_status');

        $this->actingAs($admin)
            ->patchJson("/admin/billing/{$purchase->id}/fulfillment", [
                'fulfillment_status' => 'provider_review',
                'assigned_to' => $admin->id,
                'priority' => 'normal',
                'milestones' => [
                    ['id' => 'milestone_1', 'label' => 'Workflow review', 'completed' => true],
                ],
                'provider_update' => 'The workflow review is complete. Please review the recommendations and confirm the result.',
            ])
            ->assertOk()
            ->assertJsonPath('purchase.fulfillment_status', 'provider_review');

        $this->actingAs($provider)
            ->postJson("/provider/billing/{$purchase->id}/confirm", [
                'rating' => 5,
                'feedback' => 'The recommendations are clear and useful.',
            ])
            ->assertOk()
            ->assertJsonPath('purchase.fulfillment_status', 'completed')
            ->assertJsonPath('purchase.provider_rating', 5);

        $this->assertDatabaseHas('provider_service_purchases', [
            'id' => $purchase->id,
            'fulfillment_status' => 'completed',
            'provider_rating' => 5,
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/billing/{$purchase->id}/reopen", [
                'reason' => 'One of the recommended workflow steps still needs clarification.',
            ])
            ->assertOk()
            ->assertJsonPath('purchase.fulfillment_status', 'in_progress');

        $this->assertNull($purchase->fresh()->provider_confirmed_at);
    }

    public function test_provider_response_moves_information_request_back_to_ready(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $admin = User::factory()->create(['role' => 'admin']);
        $purchase = $this->pendingPurchase($provider);
        $purchase->update(['status' => 'paid', 'paid_at' => now(), 'fulfillment_status' => 'ready']);

        $this->actingAs($admin)
            ->postJson("/admin/billing/{$purchase->id}/updates", [
                'kind' => 'clarification_request',
                'message' => 'Which scholarship program should be included in this setup review?',
            ])
            ->assertCreated()
            ->assertJsonPath('purchase.fulfillment_status', 'needs_information');

        $this->actingAs($provider)
            ->postJson("/provider/billing/{$purchase->id}/updates", [
                'message' => 'Please review our Senior High School Support Program.',
            ])
            ->assertCreated()
            ->assertJsonPath('purchase.fulfillment_status', 'ready');
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
