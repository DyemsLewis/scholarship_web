<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceRequest as ServiceRequest;
use App\Models\Scholarship;
use App\Services\PayMongoCheckoutService;
use App\Services\ProviderServiceLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class ProviderServiceRequestController extends Controller
{
    public function __construct(
        private readonly PayMongoCheckoutService $payMongo,
        private readonly ProviderServiceLifecycleService $serviceLifecycle,
    ) {}

    public function intakePage(Request $request, string $plan): View
    {
        $this->authorizeProvider($request);
        $this->plan($plan);

        return view('provider-service-intake');
    }

    public function intakeData(Request $request, string $plan): JsonResponse
    {
        $this->authorizeProvider($request);
        $planDefinition = $this->plan($plan);
        $owner = $request->user()->providerOrganizationOwner()->loadMissing('providerProfile');
        $serviceRequest = null;

        if ($request->filled('request')) {
            $serviceRequest = ServiceRequest::query()
                ->with(['scholarship', 'purchase'])
                ->whereKey($request->integer('request'))
                ->where('provider_id', $owner->id)
                ->where('plan_code', $plan)
                ->firstOrFail();
        }

        return response()->json([
            'organization' => [
                'id' => $owner->id,
                'name' => $owner->provider_name ?: $owner->name,
            ],
            'plan' => $this->planPayload($plan, $planDefinition),
            'programs' => Scholarship::query()
                ->where('provider_id', $owner->id)
                ->orderBy('title')
                ->get(['id', 'title', 'status'])
                ->map(fn (Scholarship $scholarship) => [
                    'id' => $scholarship->id,
                    'title' => $scholarship->title,
                    'status' => $scholarship->status,
                ])
                ->values(),
            'defaults' => [
                'contact_name' => $request->user()->name,
                'contact_email' => $request->user()->email,
            ],
            'request' => $serviceRequest ? $this->requestPayload($serviceRequest) : null,
        ]);
    }

    public function store(Request $request, string $plan): JsonResponse
    {
        $this->authorizeProvider($request);
        $planDefinition = $this->plan($plan);
        $owner = $request->user()->providerOrganizationOwner();

        $serviceRequest = $this->persistRequest(
            $request,
            $owner->id,
            $plan,
            $planDefinition,
        );

        ActivityLog::record(
            $request->user(),
            'provider_service_intake_created',
            "{$request->user()->name} prepared a {$serviceRequest->plan_name} request for review.",
            $request,
            ['service_request_id' => $serviceRequest->id, 'plan_code' => $plan],
        );

        return response()->json([
            'message' => 'Service details saved. Review them before payment.',
            'request' => $this->requestPayload($serviceRequest),
            'review_url' => route('provider.workspaces.billing.service-requests.review', $serviceRequest),
        ], 201);
    }

    public function update(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $this->authorizeServiceRequest($request, $serviceRequest);

        abort_unless($serviceRequest->status === 'ready_for_review', 422, 'This request can no longer be edited.');

        $planDefinition = $this->plan($serviceRequest->plan_code);
        $serviceRequest = $this->persistRequest(
            $request,
            $serviceRequest->provider_id,
            $serviceRequest->plan_code,
            $planDefinition,
            $serviceRequest,
        );

        ActivityLog::record(
            $request->user(),
            'provider_service_intake_updated',
            "{$request->user()->name} updated a {$serviceRequest->plan_name} request.",
            $request,
            ['service_request_id' => $serviceRequest->id, 'plan_code' => $serviceRequest->plan_code],
        );

        return response()->json([
            'message' => 'Service details updated. Review them before payment.',
            'request' => $this->requestPayload($serviceRequest),
            'review_url' => route('provider.workspaces.billing.service-requests.review', $serviceRequest),
        ]);
    }

    public function reviewPage(Request $request, ServiceRequest $serviceRequest): View
    {
        $this->authorizeServiceRequest($request, $serviceRequest);

        return view('provider-service-review');
    }

    public function reviewData(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $this->authorizeServiceRequest($request, $serviceRequest);
        $serviceRequest->loadMissing(['scholarship', 'purchase']);
        $planDefinition = $this->plan($serviceRequest->plan_code);

        return response()->json([
            'plan' => $this->planPayload($serviceRequest->plan_code, $planDefinition),
            'request' => $this->requestPayload($serviceRequest, true),
            'gateway' => [
                'name' => 'PayMongo',
                'configured' => $this->payMongo->isConfigured(),
                'payment_methods' => config('billing.paymongo.payment_methods', []),
            ],
        ]);
    }

    public function checkout(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $this->authorizeServiceRequest($request, $serviceRequest);
        abort_if($serviceRequest->status === 'cancelled', 422, 'A cancelled service request cannot be paid.');

        $request->validate([
            'accept_terms' => ['accepted'],
        ]);

        if (! $this->payMongo->isConfigured()) {
            return response()->json([
                'message' => 'Online payment is temporarily unavailable. Please try again later or contact platform support.',
            ], 503);
        }

        $serviceRequest->loadMissing('purchase');
        $purchase = $serviceRequest->purchase;

        if ($purchase?->status === 'paid') {
            return response()->json([
                'message' => 'Payment is already confirmed.',
                'confirmed' => true,
                'purchase' => $this->purchasePayload($purchase),
            ]);
        }

        if ($purchase?->status === 'pending' && filled($purchase->checkout_url)) {
            return response()->json([
                'message' => 'Secure checkout is ready.',
                'checkout_url' => $purchase->checkout_url,
                'purchase' => $this->purchasePayload($purchase),
            ]);
        }

        $actor = $request->user();
        $plan = $this->plan($serviceRequest->plan_code);
        $acceptedAt = now();

        if (! $purchase) {
            $purchase = ProviderServicePurchase::create([
                'provider_id' => $serviceRequest->provider_id,
                'created_by' => $actor->id,
                'service_request_id' => $serviceRequest->id,
                'plan_code' => $serviceRequest->plan_code,
                'plan_name' => $serviceRequest->plan_name,
                'amount' => $serviceRequest->amount,
                'currency' => $serviceRequest->currency,
                'status' => 'pending',
                'fulfillment_status' => 'queued',
                'reference_number' => $this->uniqueReferenceNumber(),
                'service_terms_accepted_at' => $acceptedAt,
                'request_summary' => $serviceRequest->request_summary,
                'requested_outcome' => $serviceRequest->requested_outcome,
                'priority' => 'normal',
                'fulfillment_notes' => 'Work enters the platform queue after payment is confirmed.',
                'milestones' => $this->defaultMilestones($plan),
            ]);
            $purchase = $this->serviceLifecycle->initialize($purchase);
        } else {
            $purchase = $this->serviceLifecycle->transitionPayment(
                $purchase,
                'pending',
                $actor,
                [
                    'checkout_session_id' => null,
                    'checkout_url' => null,
                    'service_terms_accepted_at' => $acceptedAt,
                    'failed_at' => null,
                    'failure_message' => null,
                ],
                'checkout_restarted',
            );
        }

        $serviceRequest->update([
            'reviewed_at' => $acceptedAt,
            'terms_accepted_at' => $acceptedAt,
        ]);

        try {
            $checkout = $this->payMongo->createCheckout($purchase);
        } catch (Throwable $error) {
            $this->serviceLifecycle->transitionPayment(
                $purchase,
                'failed',
                $actor,
                [
                    'failed_at' => now(),
                    'failure_message' => 'Checkout could not be started.',
                ],
                'checkout_start_failed',
            );
            $serviceRequest->update(['status' => 'ready_for_review']);

            ActivityLog::record(
                $actor,
                'provider_service_checkout_failed',
                "Checkout could not be started for {$purchase->reference_number}.",
                $request,
                [
                    'service_request_id' => $serviceRequest->id,
                    'purchase_id' => $purchase->id,
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
        $serviceRequest->update(['status' => 'checkout_started']);

        ActivityLog::record(
            $actor,
            'provider_service_checkout_created',
            "{$actor->name} reviewed the service request and opened checkout for {$purchase->plan_name}.",
            $request,
            [
                'service_request_id' => $serviceRequest->id,
                'purchase_id' => $purchase->id,
                'reference_number' => $purchase->reference_number,
            ],
        );

        return response()->json([
            'message' => 'Secure checkout is ready.',
            'checkout_url' => $checkout['checkout_url'],
            'purchase' => $this->purchasePayload($purchase->fresh()),
        ], 201);
    }

    private function persistRequest(
        Request $request,
        int $providerId,
        string $planCode,
        array $plan,
        ?ServiceRequest $serviceRequest = null,
    ): ServiceRequest {
        $validated = $request->validate($this->intakeRules($planCode, $providerId));
        $scholarship = filled($validated['scholarship_id'] ?? null)
            ? Scholarship::query()->findOrFail($validated['scholarship_id'])
            : null;
        $answers = collect($validated)->except('scholarship_id')->all();
        $attributes = [
            'provider_id' => $providerId,
            'created_by' => $request->user()->id,
            'scholarship_id' => $scholarship?->id,
            'plan_code' => $planCode,
            'plan_name' => $plan['name'],
            'amount' => (int) $plan['amount'],
            'currency' => config('billing.currency', 'PHP'),
            'status' => 'ready_for_review',
            'answers' => $answers,
            'request_summary' => $this->requestSummary($planCode, $answers, $scholarship),
            'requested_outcome' => trim((string) $answers['requested_outcome']),
            'submitted_at' => now(),
            'reviewed_at' => null,
            'terms_accepted_at' => null,
        ];

        if ($serviceRequest) {
            $serviceRequest->update($attributes);

            if ($serviceRequest->purchase && $serviceRequest->purchase->status !== 'paid') {
                $serviceRequest->purchase->update([
                    'amount' => $attributes['amount'],
                    'request_summary' => $attributes['request_summary'],
                    'requested_outcome' => $attributes['requested_outcome'],
                    'milestones' => $this->defaultMilestones($plan),
                ]);
            }

            return $serviceRequest->fresh(['scholarship', 'purchase']);
        }

        return ServiceRequest::create($attributes)->load(['scholarship', 'purchase']);
    }

    private function intakeRules(string $planCode, int $providerId): array
    {
        $programRule = Rule::exists('scholarships', 'id')
            ->where(fn ($query) => $query->where('provider_id', $providerId));
        $common = [
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'contact_email' => ['required', 'email:rfc', 'max:160'],
            'requested_outcome' => ['required', 'string', 'min:10', 'max:1000'],
        ];

        return match ($planCode) {
            'assisted_setup' => [
                ...$common,
                'scholarship_id' => ['required', 'integer', $programRule],
                'program_stage' => ['required', Rule::in(['drafting', 'requirements', 'review', 'publishing'])],
                'main_blocker' => ['required', 'string', 'min:10', 'max:1500'],
            ],
            'application_cycle_support' => [
                ...$common,
                'scholarship_id' => ['required', 'integer', $programRule],
                'cycle_stage' => ['required', Rule::in(['preparation', 'applications_open', 'review', 'selection', 'recipient_onboarding'])],
                'support_areas' => ['required', 'array', 'min:1', 'max:6'],
                'support_areas.*' => ['string', 'distinct', Rule::in(array_keys(config('billing.plans.application_cycle_support.support_areas', [])))],
                'main_blocker' => ['required', 'string', 'min:10', 'max:1500'],
            ],
            'integration_consultation' => [
                ...$common,
                'scholarship_id' => ['nullable', 'integer', $programRule],
                'current_process' => ['required', 'string', 'min:20', 'max:2000'],
                'systems_used' => ['nullable', 'string', 'max:500'],
            ],
            default => abort(404),
        };
    }

    private function requestSummary(string $planCode, array $answers, ?Scholarship $scholarship): string
    {
        $program = $scholarship?->title ?: 'Organization-wide process';

        return match ($planCode) {
            'assisted_setup' => "{$program}: ".Str::limit(trim((string) $answers['main_blocker']), 700),
            'application_cycle_support' => $program.': '.collect($answers['support_areas'] ?? [])
                ->map(fn (string $area) => config("billing.plans.application_cycle_support.support_areas.{$area}", $area))
                ->join(', ').'. '.Str::limit(trim((string) $answers['main_blocker']), 500),
            'integration_consultation' => "{$program}: ".Str::limit(trim((string) $answers['current_process']), 700),
            default => $program,
        };
    }

    private function requestPayload(ServiceRequest $serviceRequest, bool $includeReviewItems = false): array
    {
        $serviceRequest->loadMissing(['scholarship', 'purchase']);
        $plan = $this->plan($serviceRequest->plan_code);
        $answers = $serviceRequest->answers ?? [];
        $payload = [
            'id' => $serviceRequest->id,
            'plan_code' => $serviceRequest->plan_code,
            'status' => $serviceRequest->status,
            'scholarship_id' => $serviceRequest->scholarship_id,
            'scholarship_title' => $serviceRequest->scholarship?->title,
            'answers' => $answers,
            'request_summary' => $serviceRequest->request_summary,
            'requested_outcome' => $serviceRequest->requested_outcome,
            'editable' => $serviceRequest->status === 'ready_for_review',
            'edit_url' => route('provider.workspaces.billing.services.request', [
                'plan' => $serviceRequest->plan_code,
                'request' => $serviceRequest->id,
            ]),
            'review_url' => route('provider.workspaces.billing.service-requests.review', $serviceRequest),
            'purchase' => $serviceRequest->purchase ? $this->purchasePayload($serviceRequest->purchase) : null,
        ];

        if (! $includeReviewItems) {
            return $payload;
        }

        return [
            ...$payload,
            'review_items' => collect($plan['intake']['fields'] ?? [])
                ->reject(fn (array $field) => $field['key'] === 'requested_outcome')
                ->map(function (array $field) use ($answers, $serviceRequest): array {
                    $value = $field['key'] === 'scholarship_id'
                        ? $serviceRequest->scholarship?->title
                        : ($answers[$field['key']] ?? null);

                    if ($field['type'] === 'select') {
                        $value = collect($field['options'] ?? [])->firstWhere('value', $value)['label'] ?? $value;
                    }

                    if ($field['type'] === 'checkboxes') {
                        $labels = config('billing.plans.application_cycle_support.support_areas', []);
                        $value = collect((array) $value)->map(fn (string $item) => $labels[$item] ?? $item)->join(', ');
                    }

                    return [
                        'label' => $field['label'],
                        'value' => filled($value) ? $value : 'Not specified',
                    ];
                })
                ->values(),
            'contact' => [
                ['label' => 'Contact person', 'value' => $answers['contact_name'] ?? 'Not specified'],
                ['label' => 'Contact email', 'value' => $answers['contact_email'] ?? 'Not specified'],
            ],
        ];
    }

    private function planPayload(string $code, array $plan): array
    {
        $fields = collect($plan['intake']['fields'] ?? [])->map(function (array $field): array {
            if ($field['type'] !== 'checkboxes') {
                return $field;
            }

            return [
                ...$field,
                'options' => collect(config('billing.plans.application_cycle_support.support_areas', []))
                    ->map(fn (string $label, string $value) => compact('value', 'label'))
                    ->values(),
            ];
        })->values();

        return [
            'code' => $code,
            'name' => $plan['name'],
            'description' => $plan['description'],
            'outcome' => $plan['outcome'] ?? null,
            'timeline' => $plan['timeline'] ?? null,
            'format' => $plan['format'] ?? null,
            'scope_note' => $plan['scope_note'] ?? null,
            'amount' => (int) $plan['amount'],
            'currency' => config('billing.currency', 'PHP'),
            'intake' => [
                'title' => $plan['intake']['title'] ?? 'Service request details',
                'description' => $plan['intake']['description'] ?? null,
                'fields' => $fields,
            ],
        ];
    }

    private function purchasePayload(ProviderServicePurchase $purchase): array
    {
        return [
            'id' => $purchase->id,
            'status' => $purchase->status,
            'checkout_url' => $purchase->checkout_url,
            'workspace_url' => route('provider.billing.workspace', $purchase),
        ];
    }

    private function plan(string $code): array
    {
        $plan = config("billing.plans.{$code}");
        abort_unless(is_array($plan), 404);

        return $plan;
    }

    private function authorizeProvider(Request $request): void
    {
        abort_unless($request->user()?->isProvider(), 403);
        abort_unless($request->user()->hasPortalPermission('manage_billing'), 403);
    }

    private function authorizeServiceRequest(Request $request, ServiceRequest $serviceRequest): void
    {
        $this->authorizeProvider($request);
        abort_unless($request->user()->providerOrganizationId() === $serviceRequest->provider_id, 403);
    }

    private function defaultMilestones(array $plan): array
    {
        return collect($plan['features'] ?? [])
            ->values()
            ->map(fn (string $feature, int $index) => [
                'id' => 'milestone_'.($index + 1),
                'label' => $feature,
                'completed' => false,
            ])
            ->all();
    }

    private function uniqueReferenceNumber(): string
    {
        do {
            $reference = 'SP-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
        } while (ProviderServicePurchase::query()->where('reference_number', $reference)->exists());

        return $reference;
    }
}
