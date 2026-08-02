<?php

namespace App\Services;

use App\Models\ProviderServicePurchase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayMongoCheckoutService
{
    public function isConfigured(): bool
    {
        return (bool) config('billing.enabled')
            && filled(config('billing.paymongo.secret_key'))
            && filled(config('billing.paymongo.webhook_secret'));
    }

    public function isLiveMode(): bool
    {
        return str_starts_with((string) config('billing.paymongo.secret_key'), 'sk_live_');
    }

    /**
     * @return array{id: string, checkout_url: string, livemode: bool}
     */
    public function createCheckout(ProviderServicePurchase $purchase): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Provider payments are not configured.');
        }

        $baseUrl = rtrim((string) config('billing.paymongo.base_url'), '/');
        $payload = [
            'data' => [
                'attributes' => [
                    'line_items' => [[
                        'currency' => $purchase->currency,
                        'amount' => $purchase->amount,
                        'description' => 'Optional operational support for the scholarship provider portal.',
                        'name' => $purchase->plan_name,
                        'quantity' => 1,
                    ]],
                    'payment_method_types' => config('billing.paymongo.payment_methods', ['card', 'gcash', 'qrph']),
                    'success_url' => route('provider.billing', [
                        'checkout' => 'submitted',
                        'reference' => $purchase->reference_number,
                    ]),
                    'cancel_url' => route('provider.billing', [
                        'checkout' => 'cancelled',
                        'reference' => $purchase->reference_number,
                    ]),
                    'description' => 'Optional provider service. Core scholarship publishing and applicant access remain free.',
                    'reference_number' => $purchase->reference_number,
                    'send_email_receipt' => (bool) config('billing.paymongo.send_email_receipt', true),
                    'pass_on_fees' => (bool) config('billing.paymongo.pass_on_fees', false),
                    'metadata' => [
                        'purchase_id' => (string) $purchase->id,
                        'provider_id' => (string) $purchase->provider_id,
                        'plan_code' => $purchase->plan_code,
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withBasicAuth((string) config('billing.paymongo.secret_key'), '')
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post("{$baseUrl}/v2/checkout_sessions", $payload);
        } catch (ConnectionException $error) {
            report($error);

            throw new RuntimeException('The payment gateway could not be reached.', previous: $error);
        }

        if (! $response->successful()) {
            report(new RuntimeException("PayMongo checkout failed with HTTP {$response->status()}."));

            throw new RuntimeException('The payment gateway could not start checkout.');
        }

        $checkoutId = $response->json('data.id');
        $checkoutUrl = $response->json('data.attributes.checkout_url');

        if (! is_string($checkoutId) || ! is_string($checkoutUrl) || ! str_starts_with($checkoutUrl, 'https://')) {
            throw new RuntimeException('The payment gateway returned an invalid checkout session.');
        }

        return [
            'id' => $checkoutId,
            'checkout_url' => $checkoutUrl,
            'livemode' => (bool) ($response->json('data.attributes.livemode') ?? $this->isLiveMode()),
        ];
    }

    /**
     * @return array{valid: bool, livemode: bool}
     */
    public function verifyWebhookSignature(string $payload, ?string $signatureHeader): array
    {
        $secret = (string) config('billing.paymongo.webhook_secret');

        if ($secret === '' || blank($signatureHeader)) {
            return ['valid' => false, 'livemode' => false];
        }

        $parts = [];

        foreach (explode(',', (string) $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if (filled($key) && filled($value)) {
                $parts[trim($key)] = trim($value);
            }
        }

        $timestamp = filter_var($parts['t'] ?? null, FILTER_VALIDATE_INT);
        $tolerance = max(30, (int) config('billing.paymongo.signature_tolerance_seconds', 300));

        if ($timestamp === false || abs(now()->timestamp - $timestamp) > $tolerance) {
            return ['valid' => false, 'livemode' => false];
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        if (isset($parts['te']) && hash_equals($expected, $parts['te'])) {
            return ['valid' => true, 'livemode' => false];
        }

        if (isset($parts['li']) && hash_equals($expected, $parts['li'])) {
            return ['valid' => true, 'livemode' => true];
        }

        return ['valid' => false, 'livemode' => false];
    }
}
