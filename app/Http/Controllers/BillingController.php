<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\ProviderServicePurchase;
use App\Models\User;
use App\Services\PayMongoCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class BillingController extends Controller
{
    public function __construct(private readonly PayMongoCheckoutService $payMongo) {}

    public function providerPage(Request $request): View
    {
        abort_unless($request->user()?->isProvider(), 403);

        return view('provider-billing');
    }

    public function providerData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $owner = $request->user()->providerOrganizationOwner()->loadMissing('providerProfile');
        $purchases = ProviderServicePurchase::query()
            ->with('creator.providerProfile')
            ->where('provider_id', $owner->id)
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'organization' => [
                'id' => $owner->id,
                'name' => $owner->provider_name ?: $owner->name,
            ],
            'gateway' => [
                'name' => 'PayMongo',
                'configured' => $this->payMongo->isConfigured(),
                'payment_methods' => config('billing.paymongo.payment_methods', []),
            ],
            'plans' => collect(config('billing.plans', []))
                ->map(fn (array $plan, string $code) => [
                    'code' => $code,
                    'name' => $plan['name'],
                    'short_name' => $plan['short_name'] ?? $plan['name'],
                    'description' => $plan['description'],
                    'best_for' => $plan['best_for'] ?? null,
                    'amount' => (int) $plan['amount'],
                    'currency' => config('billing.currency', 'PHP'),
                    'features' => array_values($plan['features'] ?? []),
                ])
                ->values(),
            'purchases' => $purchases->map(fn (ProviderServicePurchase $purchase) => $this->purchasePayload($purchase))->values(),
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        if (! $this->payMongo->isConfigured()) {
            return response()->json([
                'message' => 'Online payment is temporarily unavailable. Please try again later or contact platform support.',
            ], 503);
        }

        $plans = config('billing.plans', []);
        $validated = $request->validate([
            'plan_code' => ['required', 'string', Rule::in(array_keys($plans))],
            'accept_terms' => ['accepted'],
        ]);
        $plan = $plans[$validated['plan_code']];
        $actor = $request->user();
        $owner = $actor->providerOrganizationOwner()->loadMissing('providerProfile');

        $purchase = ProviderServicePurchase::create([
            'provider_id' => $owner->id,
            'created_by' => $actor->id,
            'plan_code' => $validated['plan_code'],
            'plan_name' => $plan['name'],
            'amount' => (int) $plan['amount'],
            'currency' => config('billing.currency', 'PHP'),
            'status' => 'pending',
            'fulfillment_status' => 'queued',
            'reference_number' => $this->uniqueReferenceNumber(),
            'service_terms_accepted_at' => now(),
        ]);

        try {
            $checkout = $this->payMongo->createCheckout($purchase);
        } catch (Throwable $error) {
            $purchase->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_message' => 'Checkout could not be started.',
            ]);

            ActivityLog::record(
                $actor,
                'provider_service_checkout_failed',
                "Checkout could not be started for {$purchase->reference_number}.",
                $request,
                [
                    'purchase_id' => $purchase->id,
                    'provider_id' => $owner->id,
                    'plan_code' => $purchase->plan_code,
                    'error' => $error->getMessage(),
                ],
            );

            return response()->json([
                'message' => 'The payment page could not be opened. Please try again later.',
            ], 503);
        }

        $purchase->update([
            'checkout_session_id' => $checkout['id'],
            'checkout_url' => $checkout['checkout_url'],
            'livemode' => $checkout['livemode'],
        ]);

        ActivityLog::record(
            $actor,
            'provider_service_checkout_created',
            "{$actor->name} opened checkout for {$purchase->plan_name}.",
            $request,
            [
                'purchase_id' => $purchase->id,
                'provider_id' => $owner->id,
                'plan_code' => $purchase->plan_code,
                'reference_number' => $purchase->reference_number,
                'livemode' => $purchase->livemode,
            ],
        );

        return response()->json([
            'message' => 'Secure checkout is ready.',
            'checkout_url' => $checkout['checkout_url'],
            'purchase' => $this->purchasePayload($purchase->fresh('creator.providerProfile')),
        ], 201);
    }

    public function syncCheckout(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isProvider(), 403);

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:80'],
        ]);
        $owner = $request->user()->providerOrganizationOwner();
        $purchase = ProviderServicePurchase::query()
            ->with(['provider.providerProfile', 'creator.providerProfile'])
            ->where('provider_id', $owner->id)
            ->where('reference_number', $validated['reference'])
            ->first();

        if (! $purchase) {
            return response()->json(['message' => 'Payment reference was not found.'], 404);
        }

        if ($purchase->status === 'paid') {
            return response()->json([
                'message' => 'Payment is already confirmed.',
                'confirmed' => true,
                'purchase' => $this->purchasePayload($purchase),
            ]);
        }

        if ($purchase->status !== 'pending' || blank($purchase->checkout_session_id)) {
            return response()->json([
                'message' => 'This order does not have a pending checkout to confirm.',
            ], 422);
        }

        try {
            $resource = $this->payMongo->retrieveCheckout($purchase->checkout_session_id);
        } catch (Throwable $error) {
            report($error);

            return response()->json([
                'message' => 'PayMongo could not confirm the payment right now. The order remains pending.',
            ], 503);
        }

        $payments = data_get($resource, 'attributes.payments', []);
        $hasPaidPayment = is_array($payments) && collect($payments)->contains(function ($payment): bool {
            return data_get($payment, 'attributes.status', data_get($payment, 'status')) === 'paid';
        });

        if (! $hasPaidPayment) {
            return response()->json([
                'message' => 'PayMongo has not confirmed a paid payment yet. You can check again shortly.',
                'confirmed' => false,
                'purchase' => $this->purchasePayload($purchase),
            ], 202);
        }

        $livemode = data_get($resource, 'attributes.livemode');

        if (! is_bool($livemode) || $livemode !== (bool) $purchase->livemode) {
            return response()->json([
                'message' => 'Checkout mode does not match the original order.',
            ], 422);
        }

        $recordingResponse = $this->recordPaidCheckout($request, $resource, null, $livemode);

        if (! $recordingResponse->isSuccessful()) {
            return $recordingResponse;
        }

        $purchase = $purchase->fresh(['provider.providerProfile', 'creator.providerProfile']);

        return response()->json([
            'message' => 'Payment confirmed by PayMongo.',
            'confirmed' => $purchase->status === 'paid',
            'purchase' => $this->purchasePayload($purchase),
        ]);
    }

    public function webhook(Request $request): JsonResponse
    {
        $rawPayload = $request->getContent();
        $signature = $this->payMongo->verifyWebhookSignature(
            $rawPayload,
            $request->header('Paymongo-Signature'),
        );

        if (! $signature['valid']) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = json_decode($rawPayload, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        [$eventType, $resource, $eventId, $payloadLivemode] = $this->webhookEvent($payload);

        if ($payloadLivemode !== null && $payloadLivemode !== $signature['livemode']) {
            return response()->json(['message' => 'Webhook mode does not match its signature.'], 401);
        }

        if ($eventType !== 'checkout_session.payment.paid') {
            return response()->json(['received' => true]);
        }

        if (! is_array($resource)) {
            return response()->json(['message' => 'Checkout session data is missing.'], 422);
        }

        return $this->recordPaidCheckout($request, $resource, $eventId, $signature['livemode']);
    }

    public function adminPage(Request $request): View
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return view('admin-billing');
    }

    public function adminData(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'payment_status' => ['nullable', Rule::in(['all', ...ProviderServicePurchase::PAYMENT_STATUSES])],
            'fulfillment_status' => ['nullable', Rule::in(['all', ...ProviderServicePurchase::FULFILLMENT_STATUSES])],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $paymentStatus = $validated['payment_status'] ?? 'paid';
        $fulfillmentStatus = $validated['fulfillment_status'] ?? 'all';
        $search = trim($validated['search'] ?? '');

        $query = ProviderServicePurchase::query()
            ->with(['provider.providerProfile', 'creator.providerProfile', 'fulfiller.adminProfile'])
            ->when($paymentStatus !== 'all', fn ($builder) => $builder->where('status', $paymentStatus))
            ->when($fulfillmentStatus !== 'all', fn ($builder) => $builder->where('fulfillment_status', $fulfillmentStatus))
            ->when($search !== '', function ($builder) use ($search): void {
                $builder->where(function ($nested) use ($search): void {
                    $nested->where('reference_number', 'like', "%{$search}%")
                        ->orWhere('plan_name', 'like', "%{$search}%")
                        ->orWhereHas('provider.providerProfile', fn ($profile) => $profile->where('provider_name', 'like', "%{$search}%"));
                });
            })
            ->latest('paid_at')
            ->latest();

        $purchases = $query->paginate(20);
        $counts = ProviderServicePurchase::query()
            ->where('status', 'paid')
            ->selectRaw('fulfillment_status, COUNT(*) as aggregate')
            ->groupBy('fulfillment_status')
            ->pluck('aggregate', 'fulfillment_status');

        return response()->json([
            'counts' => [
                'all' => (int) $counts->sum(),
                'queued' => (int) ($counts['queued'] ?? 0),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
            ],
            'purchases' => collect($purchases->items())
                ->map(fn (ProviderServicePurchase $purchase) => $this->purchasePayload($purchase, true))
                ->values(),
            'pagination' => [
                'current_page' => $purchases->currentPage(),
                'last_page' => $purchases->lastPage(),
                'per_page' => $purchases->perPage(),
                'total' => $purchases->total(),
            ],
        ]);
    }

    public function updateFulfillment(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'fulfillment_status' => ['required', Rule::in(ProviderServicePurchase::FULFILLMENT_STATUSES)],
            'fulfillment_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($purchase->status !== 'paid') {
            return response()->json([
                'message' => 'Only confirmed payments can enter service fulfillment.',
            ], 422);
        }

        $previousStatus = $purchase->fulfillment_status;
        $purchase->update([
            'fulfillment_status' => $validated['fulfillment_status'],
            'fulfillment_notes' => filled($validated['fulfillment_notes'] ?? null)
                ? trim($validated['fulfillment_notes'])
                : null,
            'fulfilled_by' => $request->user()->id,
            'fulfilled_at' => $validated['fulfillment_status'] === 'completed' ? now() : null,
        ]);

        ActivityLog::record(
            $request->user(),
            'provider_service_fulfillment_updated',
            "{$request->user()->name} changed {$purchase->reference_number} from {$previousStatus} to {$purchase->fulfillment_status}.",
            $request,
            [
                'purchase_id' => $purchase->id,
                'provider_id' => $purchase->provider_id,
                'from_status' => $previousStatus,
                'to_status' => $purchase->fulfillment_status,
            ],
        );

        if ($previousStatus !== $purchase->fulfillment_status) {
            $this->notifyProviderTeam(
                $purchase,
                'provider_service_status',
                'Provider service status updated',
                "{$purchase->plan_name} is now {$this->statusLabel($purchase->fulfillment_status)}.",
                "provider_service_status:{$purchase->id}:{$purchase->fulfillment_status}",
            );
        }

        return response()->json([
            'message' => 'Service status updated.',
            'purchase' => $this->purchasePayload($purchase->fresh(['provider.providerProfile', 'creator.providerProfile', 'fulfiller.adminProfile']), true),
        ]);
    }

    private function recordPaidCheckout(
        Request $request,
        array $resource,
        ?string $eventId,
        bool $livemode,
    ): JsonResponse {
        $attributes = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
        $sessionId = is_string($resource['id'] ?? null) ? $resource['id'] : null;
        $reference = $attributes['reference_number'] ?? null;
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        $purchaseId = filter_var($metadata['purchase_id'] ?? null, FILTER_VALIDATE_INT);
        $payments = is_array($attributes['payments'] ?? null) ? $attributes['payments'] : [];
        $payment = collect($payments)->first(function ($item): bool {
            $status = data_get($item, 'attributes.status', data_get($item, 'status'));

            return $status === 'paid';
        }) ?? ($payments[0] ?? null);
        $paymentAttributes = is_array(data_get($payment, 'attributes'))
            ? data_get($payment, 'attributes')
            : (is_array($payment) ? $payment : []);
        $paymentStatus = $paymentAttributes['status'] ?? null;
        $amount = $paymentAttributes['amount'] ?? $attributes['amount'] ?? $this->lineItemsAmount($attributes['line_items'] ?? []);
        $currency = strtoupper((string) ($paymentAttributes['currency'] ?? $attributes['currency'] ?? ''));

        if ($paymentStatus !== null && $paymentStatus !== 'paid') {
            return response()->json(['message' => 'The checkout payment is not paid.'], 422);
        }

        $purchaseQuery = ProviderServicePurchase::query();

        if (filled($reference)) {
            $purchaseQuery->where('reference_number', $reference);
        } elseif ($purchaseId !== false) {
            $purchaseQuery->whereKey($purchaseId);
        } elseif (filled($sessionId)) {
            $purchaseQuery->where('checkout_session_id', $sessionId);
        } else {
            return response()->json(['message' => 'Payment reference is missing.'], 422);
        }

        $purchase = $purchaseQuery->first();

        if (! $purchase) {
            return response()->json(['message' => 'Payment reference was not found.'], 404);
        }

        if (! is_numeric($amount) || (int) $amount !== $purchase->amount || $currency !== $purchase->currency) {
            return response()->json(['message' => 'Payment amount or currency does not match the order.'], 422);
        }

        if ($purchaseId !== false && (int) $purchaseId !== $purchase->id) {
            return response()->json(['message' => 'Payment metadata does not match the order.'], 422);
        }

        if (isset($metadata['provider_id']) && (int) $metadata['provider_id'] !== $purchase->provider_id) {
            return response()->json(['message' => 'Payment provider metadata does not match the order.'], 422);
        }

        if (isset($metadata['plan_code']) && $metadata['plan_code'] !== $purchase->plan_code) {
            return response()->json(['message' => 'Payment service metadata does not match the order.'], 422);
        }

        if (filled($purchase->checkout_session_id) && filled($sessionId) && $purchase->checkout_session_id !== $sessionId) {
            return response()->json(['message' => 'Checkout session does not match the order.'], 422);
        }

        $wasRecorded = DB::transaction(function () use (
            $purchase,
            $sessionId,
            $payment,
            $paymentAttributes,
            $eventId,
            $livemode,
            $amount,
            $currency,
        ): bool {
            $lockedPurchase = ProviderServicePurchase::query()->lockForUpdate()->findOrFail($purchase->id);

            if ($lockedPurchase->status === 'paid') {
                return false;
            }

            $lockedPurchase->update([
                'status' => 'paid',
                'checkout_session_id' => $sessionId ?: $lockedPurchase->checkout_session_id,
                'checkout_url' => null,
                'payment_id' => data_get($payment, 'id'),
                'payment_intent_id' => data_get($paymentAttributes, 'payment_intent.id')
                    ?? data_get($paymentAttributes, 'payment_intent_id'),
                'payment_method' => data_get($paymentAttributes, 'source.type')
                    ?? data_get($paymentAttributes, 'payment_method_used')
                    ?? data_get($paymentAttributes, 'payment_method.type'),
                'livemode' => $livemode,
                'paid_at' => now(),
                'failed_at' => null,
                'failure_message' => null,
                'gateway_metadata' => [
                    'event_id' => $eventId,
                    'verified_amount' => (int) $amount,
                    'verified_currency' => $currency,
                ],
            ]);

            return true;
        });

        if (! $wasRecorded) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        $purchase = $purchase->fresh(['provider.providerProfile', 'creator.providerProfile']);
        ActivityLog::record(
            null,
            'provider_service_payment_confirmed',
            "PayMongo confirmed payment for {$purchase->reference_number}.",
            $request,
            [
                'purchase_id' => $purchase->id,
                'provider_id' => $purchase->provider_id,
                'plan_code' => $purchase->plan_code,
                'livemode' => $purchase->livemode,
            ],
        );

        $this->notifyProviderTeam(
            $purchase,
            'provider_service_paid',
            'Payment confirmed',
            "Payment for {$purchase->plan_name} was confirmed. The service is now in the admin queue.",
            "provider_service_paid:{$purchase->id}",
        );
        $this->notifyBillingAdmins($purchase);

        return response()->json(['received' => true]);
    }

    /**
     * @return array{0: ?string, 1: mixed, 2: ?string, 3: ?bool}
     */
    private function webhookEvent(array $payload): array
    {
        $eventData = data_get($payload, 'data', []);
        $attributes = is_array(data_get($eventData, 'attributes')) ? data_get($eventData, 'attributes') : [];
        $eventType = $attributes['type'] ?? data_get($eventData, 'type');
        $resource = $attributes['data'] ?? data_get($eventData, 'data');
        $eventId = is_string(data_get($eventData, 'id')) ? data_get($eventData, 'id') : null;
        $modeValue = $attributes['livemode'] ?? data_get($resource, 'attributes.livemode');
        $livemode = is_bool($modeValue) ? $modeValue : null;

        return [is_string($eventType) ? $eventType : null, $resource, $eventId, $livemode];
    }

    private function lineItemsAmount(mixed $lineItems): ?int
    {
        if (! is_array($lineItems) || $lineItems === []) {
            return null;
        }

        return collect($lineItems)->sum(function ($item): int {
            $amount = (int) (data_get($item, 'attributes.amount') ?? data_get($item, 'amount') ?? 0);
            $quantity = max(1, (int) (data_get($item, 'attributes.quantity') ?? data_get($item, 'quantity') ?? 1));

            return $amount * $quantity;
        });
    }

    private function notifyProviderTeam(
        ProviderServicePurchase $purchase,
        string $type,
        string $title,
        string $message,
        string $deduplicationPrefix,
    ): void {
        User::query()
            ->where('role', 'provider')
            ->where(function ($query) use ($purchase): void {
                $query->whereKey($purchase->provider_id)
                    ->orWhere('parent_account_id', $purchase->provider_id);
            })
            ->where(fn ($query) => $query->whereNull('account_status')->orWhere('account_status', 'active'))
            ->get()
            ->filter(fn (User $user) => ! $user->isManagedAccount() || $user->hasPortalPermission('manage_billing'))
            ->each(function (User $user) use ($type, $title, $message, $deduplicationPrefix): void {
                PortalNotification::firstOrCreate([
                    'deduplication_key' => "{$deduplicationPrefix}:{$user->id}",
                ], [
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'action_url' => '/provider/billing',
                ]);
            });
    }

    private function notifyBillingAdmins(ProviderServicePurchase $purchase): void
    {
        $providerName = $purchase->provider?->provider_name ?: $purchase->provider?->name ?: 'A provider';

        User::query()
            ->where('role', 'admin')
            ->where(fn ($query) => $query->whereNull('account_status')->orWhere('account_status', 'active'))
            ->get()
            ->filter(fn (User $user) => $user->hasPortalPermission('manage_billing'))
            ->each(function (User $user) use ($purchase, $providerName): void {
                PortalNotification::firstOrCreate([
                    'deduplication_key' => "provider_service_admin_queue:{$purchase->id}:{$user->id}",
                ], [
                    'user_id' => $user->id,
                    'type' => 'provider_service_queue',
                    'title' => 'Paid provider service ready',
                    'message' => "{$providerName} paid for {$purchase->plan_name}. Open Service Payments to begin fulfillment.",
                    'action_url' => '/admin/billing',
                ]);
            });
    }

    private function purchasePayload(ProviderServicePurchase $purchase, bool $includeProvider = false): array
    {
        $payload = [
            'id' => $purchase->id,
            'reference_number' => $purchase->reference_number,
            'plan_code' => $purchase->plan_code,
            'plan_name' => $purchase->plan_name,
            'amount' => $purchase->amount,
            'currency' => $purchase->currency,
            'status' => $purchase->status,
            'fulfillment_status' => $purchase->fulfillment_status,
            'payment_method' => $purchase->payment_method,
            'livemode' => $purchase->livemode,
            'checkout_url' => $purchase->status === 'pending' ? $purchase->checkout_url : null,
            'created_by' => $purchase->creator?->name,
            'created_at' => $purchase->created_at?->toISOString(),
            'paid_at' => $purchase->paid_at?->toISOString(),
            'fulfilled_at' => $purchase->fulfilled_at?->toISOString(),
            'fulfillment_notes' => $purchase->fulfillment_notes,
            'fulfilled_by' => $purchase->fulfiller?->name,
        ];

        if ($includeProvider) {
            $payload['provider'] = [
                'id' => $purchase->provider_id,
                'name' => $purchase->provider?->provider_name ?: $purchase->provider?->name,
                'email' => $purchase->provider?->email,
            ];
        }

        return $payload;
    }

    private function uniqueReferenceNumber(): string
    {
        do {
            $reference = 'SP-'.now()->format('Ymd').'-'.Str::upper(Str::random(10));
        } while (ProviderServicePurchase::query()->where('reference_number', $reference)->exists());

        return $reference;
    }

    private function statusLabel(string $status): string
    {
        return Str::of($status)->replace('_', ' ')->lower()->toString();
    }
}
