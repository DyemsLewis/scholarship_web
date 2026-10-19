<?php

namespace Tests\Feature;

use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceRequest;
use App\Models\Scholarship;
use App\Models\User;
use App\Services\ProviderServiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderServiceIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.enabled' => true,
            'billing.paymongo.base_url' => 'https://api.paymongo.com',
            'billing.paymongo.secret_key' => 'sk_test_service_intake',
            'billing.paymongo.webhook_secret' => 'whsec_service_intake',
            'billing.paymongo.payment_methods' => ['card', 'gcash'],
        ]);
    }

    public function test_provider_prepares_and_reviews_intake_before_a_purchase_exists(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $program = $this->program($provider, 'Future Leaders Grant');

        $response = $this->actingAs($provider)
            ->postJson('/provider/workspaces/billing/services/assisted_setup/requests', [
                'scholarship_id' => $program->id,
                'program_stage' => 'requirements',
                'main_blocker' => 'Our eligibility and document requirements still need a readiness review.',
                'requested_outcome' => 'A complete program draft that is ready for platform review.',
                'contact_name' => 'Program Operations Lead',
                'contact_email' => 'operations@example.org',
            ])
            ->assertCreated()
            ->assertJsonPath('request.plan_code', 'assisted_setup')
            ->assertJsonPath('request.scholarship_title', 'Future Leaders Grant');

        $serviceRequest = ProviderServiceRequest::query()->findOrFail($response->json('request.id'));

        $this->assertDatabaseCount('provider_service_purchases', 0);
        $this->assertSame('ready_for_review', $serviceRequest->status);
        $this->assertSame($program->id, $serviceRequest->scholarship_id);

        $this->actingAs($provider)
            ->get($response->json('review_url'))
            ->assertOk()
            ->assertViewIs('provider-service-review');

        $this->actingAs($provider)
            ->getJson("/provider/workspaces/billing/service-requests/{$serviceRequest->id}/review/data")
            ->assertOk()
            ->assertJsonPath('request.requested_outcome', 'A complete program draft that is ready for platform review.')
            ->assertJsonPath('request.review_items.0.value', 'Future Leaders Grant')
            ->assertJsonPath('request.contact.0.value', 'Program Operations Lead');

        $otherProvider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($otherProvider)
            ->getJson("/provider/workspaces/billing/service-requests/{$serviceRequest->id}/review/data")
            ->assertForbidden();

        $this->actingAs($otherProvider)
            ->postJson("/provider/workspaces/billing/service-requests/{$serviceRequest->id}/checkout", [
                'accept_terms' => true,
            ])
            ->assertForbidden();
    }

    public function test_service_intake_enforces_program_ownership_and_plan_specific_fields(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);
        $otherProvider = User::factory()->create(['role' => 'provider']);
        $outsideProgram = $this->program($otherProvider, 'Outside Program');

        $this->actingAs($provider)
            ->postJson('/provider/workspaces/billing/services/assisted_setup/requests', [
                'scholarship_id' => $outsideProgram->id,
                'program_stage' => 'drafting',
                'main_blocker' => 'This program belongs to another provider organization.',
                'requested_outcome' => 'This request must not be accepted by the platform.',
                'contact_name' => 'Provider Contact',
                'contact_email' => 'provider@example.org',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scholarship_id');

        $program = $this->program($provider, 'Active Cycle Grant');

        $this->actingAs($provider)
            ->postJson('/provider/workspaces/billing/services/application_cycle_support/requests', [
                'scholarship_id' => $program->id,
                'cycle_stage' => 'review',
                'main_blocker' => 'The reviewer queue needs clearer assignment and ownership.',
                'requested_outcome' => 'An organized review queue with clear team responsibility.',
                'contact_name' => 'Cycle Lead',
                'contact_email' => 'cycle@example.org',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('support_areas');
    }

    public function test_integration_consultation_can_be_requested_without_an_existing_program(): void
    {
        $provider = User::factory()->create(['role' => 'provider']);

        $this->actingAs($provider)
            ->postJson('/provider/workspaces/billing/services/integration_consultation/requests', [
                'scholarship_id' => null,
                'current_process' => 'Applications are currently collected through forms and reviewed in separate spreadsheets.',
                'systems_used' => 'Google Forms and spreadsheets',
                'requested_outcome' => 'A practical migration plan for moving the complete workflow into the portal.',
                'contact_name' => 'Technology Coordinator',
                'contact_email' => 'technology@example.org',
            ])
            ->assertCreated()
            ->assertJsonPath('request.scholarship_id', null)
            ->assertJsonPath('request.plan_code', 'integration_consultation');
    }

    public function test_reviewed_intake_creates_one_linked_checkout_and_converts_after_payment(): void
    {
        Http::fake([
            'https://api.paymongo.com/v2/checkout_sessions' => Http::response([
                'data' => [
                    'id' => 'cs_service_intake',
                    'type' => 'checkout_session',
                    'attributes' => [
                        'checkout_url' => 'https://checkout.paymongo.com/cs_service_intake',
                        'livemode' => false,
                    ],
                ],
            ]),
        ]);
        $provider = User::factory()->create(['role' => 'provider']);
        $program = $this->program($provider, 'Community Scholars Grant');
        $serviceRequest = ProviderServiceRequest::create([
            'provider_id' => $provider->id,
            'created_by' => $provider->id,
            'scholarship_id' => $program->id,
            'plan_code' => 'assisted_setup',
            'plan_name' => 'Assisted program setup',
            'amount' => 75000,
            'currency' => 'PHP',
            'status' => 'ready_for_review',
            'answers' => [
                'program_stage' => 'review',
                'main_blocker' => 'The final program setup needs a readiness check.',
                'requested_outcome' => 'A reviewed program ready for submission.',
                'contact_name' => 'Program Lead',
                'contact_email' => 'program@example.org',
            ],
            'request_summary' => 'Community Scholars Grant: final readiness check.',
            'requested_outcome' => 'A reviewed program ready for submission.',
            'submitted_at' => now(),
        ]);

        $this->actingAs($provider)
            ->postJson("/provider/workspaces/billing/service-requests/{$serviceRequest->id}/checkout", [
                'accept_terms' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_terms');

        $this->assertDatabaseCount('provider_service_purchases', 0);

        $this->actingAs($provider)
            ->postJson("/provider/workspaces/billing/service-requests/{$serviceRequest->id}/checkout", [
                'accept_terms' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('checkout_url', 'https://checkout.paymongo.com/cs_service_intake')
            ->assertJsonPath('purchase.status', 'pending');

        $purchase = ProviderServicePurchase::query()->where('service_request_id', $serviceRequest->id)->firstOrFail();

        $this->assertSame('checkout_started', $serviceRequest->fresh()->status);
        $this->assertNotNull($serviceRequest->fresh()->reviewed_at);
        $this->assertNotNull($serviceRequest->fresh()->terms_accepted_at);

        Http::assertSent(function (ClientRequest $request) use ($serviceRequest): bool {
            $successUrl = $request['data']['attributes']['success_url'];

            return str_contains($successUrl, "/provider/workspaces/billing/service-requests/{$serviceRequest->id}/review")
                && str_contains($successUrl, 'checkout=submitted');
        });

        app(ProviderServiceLifecycleService::class)->transitionPayment(
            $purchase,
            'paid',
            null,
            ['payment_method' => 'gcash'],
            'payment_confirmed',
        );

        $this->assertSame('converted', $serviceRequest->fresh()->status);
        $this->assertNotNull($serviceRequest->fresh()->converted_at);
        $this->assertDatabaseCount('provider_service_purchases', 1);
    }

    private function program(User $provider, string $title): Scholarship
    {
        return Scholarship::create([
            'provider_id' => $provider->id,
            'title' => $title,
            'description' => 'A scholarship program used to test optional provider service intake.',
            'status' => 'draft',
        ]);
    }
}
