<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PortalNotification;
use App\Models\ProviderServiceFile;
use App\Models\ProviderServicePurchase;
use App\Models\ProviderServiceUpdate;
use App\Models\User;
use App\Services\PayMongoCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            ->with(['creator.providerProfile', 'assignee.adminProfile'])
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

    public function providerWorkspacePage(Request $request, ProviderServicePurchase $purchase): View
    {
        $this->authorizeProviderPurchase($request, $purchase);

        return view('provider-service-workspace', ['purchase' => $purchase]);
    }

    public function providerWorkspaceData(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        $this->authorizeProviderPurchase($request, $purchase);

        return response()->json([
            'purchase' => $this->workspacePayload($purchase, false),
        ]);
    }

    public function updateProviderRequest(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        $this->authorizeProviderPurchase($request, $purchase);
        $this->requirePaidService($purchase);

        if (in_array($purchase->fulfillment_status, ['provider_review', 'completed'], true)) {
            return response()->json([
                'message' => 'Reopen this service before changing its request brief.',
            ], 422);
        }

        $validated = $request->validate([
            'request_summary' => ['required', 'string', 'min:20', 'max:2000'],
            'requested_outcome' => ['required', 'string', 'min:10', 'max:1200'],
        ]);

        $purchase->update([
            'request_summary' => trim($validated['request_summary']),
            'requested_outcome' => trim($validated['requested_outcome']),
            'fulfillment_status' => $purchase->fulfillment_status === 'needs_information'
                ? 'ready'
                : $purchase->fulfillment_status,
            'fulfillment_notes' => $purchase->fulfillment_status === 'needs_information'
                ? 'The provider supplied additional information. The request is ready for support review.'
                : $purchase->fulfillment_notes,
        ]);

        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => 'provider_response',
            'message' => 'The provider updated the service brief and requested outcome.',
            'visible_to_provider' => true,
        ]);

        ActivityLog::record(
            $request->user(),
            'provider_service_brief_updated',
            "{$request->user()->name} updated the brief for {$purchase->reference_number}.",
            $request,
            ['purchase_id' => $purchase->id],
        );
        $this->notifyBillingAdminsOfUpdate(
            $purchase,
            'Service brief updated',
            "The provider updated the brief for {$purchase->plan_name}.",
            "provider_service_brief:{$update->id}",
        );

        return response()->json([
            'message' => 'Service brief updated.',
            'purchase' => $this->workspacePayload($purchase->fresh(), false),
        ]);
    }

    public function requestProviderMeeting(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        $this->authorizeProviderPurchase($request, $purchase);
        $this->requirePaidService($purchase);

        if ($purchase->fulfillment_status === 'completed') {
            return response()->json([
                'message' => 'A meeting cannot be requested for a completed service.',
            ], 422);
        }

        $validated = $request->validate([
            'meeting_scheduled_for' => ['required', 'date', 'after:now'],
            'meeting_mode' => ['required', Rule::in(['online', 'onsite'])],
            'meeting_purpose' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $purchase->update([
            'meeting_scheduled_for' => $validated['meeting_scheduled_for'],
            'meeting_mode' => $validated['meeting_mode'],
            'meeting_purpose' => trim($validated['meeting_purpose']),
            'meeting_status' => 'requested',
            'meeting_admin_note' => null,
            'meeting_decided_at' => null,
            'meeting_decided_by' => null,
        ]);
        $purchase->refresh();

        $meetingDate = $this->meetingDateLabel($purchase);
        $meetingMode = $purchase->meeting_mode === 'online' ? 'Online' : 'On-site';
        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => 'meeting_request',
            'message' => "Meeting requested for {$meetingDate} ({$meetingMode}). Purpose: {$purchase->meeting_purpose}",
            'visible_to_provider' => true,
        ]);

        ActivityLog::record(
            $request->user(),
            'provider_service_meeting_requested',
            "{$request->user()->name} requested a meeting for {$purchase->reference_number}.",
            $request,
            ['purchase_id' => $purchase->id, 'meeting_scheduled_for' => $purchase->meeting_scheduled_for?->toISOString()],
        );
        $this->notifyBillingAdminsOfUpdate(
            $purchase,
            'Provider requested a meeting',
            "A {$meetingMode} meeting was requested for {$meetingDate} for {$purchase->plan_name}.",
            "provider_service_meeting_request:{$update->id}",
            'provider_service_meeting',
        );

        return response()->json([
            'message' => 'Meeting request sent for admin confirmation.',
            'purchase' => $this->workspacePayload($purchase, false),
        ], 201);
    }

    public function storeProviderUpdate(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        $this->authorizeProviderPurchase($request, $purchase);
        $this->requirePaidService($purchase);

        if (in_array($purchase->fulfillment_status, ['provider_review', 'completed'], true)) {
            return response()->json([
                'message' => 'Reopen this service before adding another response.',
            ], 422);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => 'provider_response',
            'message' => trim($validated['message']),
            'visible_to_provider' => true,
        ]);

        if ($purchase->fulfillment_status === 'needs_information') {
            $purchase->update([
                'fulfillment_status' => 'ready',
                'fulfillment_notes' => 'The provider responded. The request is ready for support review.',
            ]);
        }

        ActivityLog::record(
            $request->user(),
            'provider_service_response_added',
            "{$request->user()->name} responded on {$purchase->reference_number}.",
            $request,
            ['purchase_id' => $purchase->id, 'update_id' => $update->id],
        );
        $this->notifyBillingAdminsOfUpdate(
            $purchase,
            'Provider responded to a service request',
            "A new response was added to {$purchase->plan_name}.",
            "provider_service_response:{$update->id}",
        );

        return response()->json([
            'message' => 'Response added.',
            'purchase' => $this->workspacePayload($purchase->fresh(), false),
        ], 201);
    }

    public function uploadProviderFile(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        $this->authorizeProviderPurchase($request, $purchase);
        $this->requirePaidService($purchase);

        if (in_array($purchase->fulfillment_status, ['provider_review', 'completed'], true)) {
            return response()->json([
                'message' => 'Reopen this service before uploading another file.',
            ], 422);
        }

        $validated = $request->validate([
            'service_file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt'],
        ]);
        $file = $validated['service_file'];
        $path = $file->store("provider-services/{$purchase->id}/supporting", 'local');

        if (! is_string($path)) {
            throw ValidationException::withMessages([
                'service_file' => 'The supporting file could not be stored. Please try again.',
            ]);
        }

        $serviceFile = $purchase->files()->create([
            'uploaded_by' => $request->user()->id,
            'category' => 'supporting',
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'visible_to_provider' => true,
        ]);
        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => 'provider_file',
            'message' => "Supporting file uploaded: {$serviceFile->original_name}",
            'visible_to_provider' => true,
        ]);

        ActivityLog::record(
            $request->user(),
            'provider_service_file_uploaded',
            "{$request->user()->name} uploaded a supporting file to {$purchase->reference_number}.",
            $request,
            ['purchase_id' => $purchase->id, 'file_id' => $serviceFile->id],
        );
        $this->notifyBillingAdminsOfUpdate(
            $purchase,
            'Provider uploaded a service file',
            "A supporting file was added to {$purchase->plan_name}.",
            "provider_service_file:{$update->id}",
        );

        return response()->json([
            'message' => 'Supporting file uploaded.',
            'purchase' => $this->workspacePayload($purchase->fresh(), false),
        ], 201);
    }

    public function confirmProviderCompletion(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        $this->authorizeProviderPurchase($request, $purchase);
        $this->requirePaidService($purchase);

        if ($purchase->fulfillment_status !== 'provider_review') {
            return response()->json([
                'message' => 'This service must be ready for provider review before it can be confirmed complete.',
            ], 422);
        }

        $validated = $request->validate([
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $purchase->update([
            'fulfillment_status' => 'completed',
            'provider_confirmed_at' => now(),
            'provider_feedback' => filled($validated['feedback'] ?? null) ? trim($validated['feedback']) : null,
            'provider_rating' => $validated['rating'] ?? null,
            'fulfilled_at' => now(),
            'reopened_at' => null,
        ]);
        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => 'completion_confirmed',
            'message' => 'The provider confirmed that the service was completed.',
            'visible_to_provider' => true,
        ]);

        ActivityLog::record(
            $request->user(),
            'provider_service_completion_confirmed',
            "{$request->user()->name} confirmed completion of {$purchase->reference_number}.",
            $request,
            ['purchase_id' => $purchase->id, 'rating' => $purchase->provider_rating],
        );
        $this->notifyBillingAdminsOfUpdate(
            $purchase,
            'Provider confirmed service completion',
            "{$purchase->plan_name} was accepted by the provider.",
            "provider_service_confirmed:{$update->id}",
        );

        return response()->json([
            'message' => 'Service completion confirmed.',
            'purchase' => $this->workspacePayload($purchase->fresh(), false),
        ]);
    }

    public function reopenProviderService(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        $this->authorizeProviderPurchase($request, $purchase);
        $this->requirePaidService($purchase);

        if (! in_array($purchase->fulfillment_status, ['provider_review', 'completed'], true)) {
            return response()->json([
                'message' => 'Only a service awaiting confirmation or already completed can be reopened.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => 'reopened',
            'message' => trim($validated['reason']),
            'visible_to_provider' => true,
        ]);
        $purchase->update([
            'fulfillment_status' => 'in_progress',
            'provider_confirmed_at' => null,
            'fulfilled_at' => null,
            'reopened_at' => now(),
        ]);

        ActivityLog::record(
            $request->user(),
            'provider_service_reopened',
            "{$request->user()->name} reopened {$purchase->reference_number}.",
            $request,
            ['purchase_id' => $purchase->id, 'update_id' => $update->id],
        );
        $this->notifyBillingAdminsOfUpdate(
            $purchase,
            'Provider reopened a service request',
            "{$purchase->plan_name} needs additional work.",
            "provider_service_reopened:{$update->id}",
        );

        return response()->json([
            'message' => 'Service reopened for additional work.',
            'purchase' => $this->workspacePayload($purchase->fresh(), false),
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
            'fulfillment_status' => 'ready',
            'reference_number' => $this->uniqueReferenceNumber(),
            'service_terms_accepted_at' => now(),
            'request_summary' => null,
            'requested_outcome' => null,
            'priority' => 'normal',
            'fulfillment_notes' => 'Payment confirmed. Schedule a meeting to discuss the service details with platform support.',
            'milestones' => $this->defaultMilestones($plan),
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

    public function adminWorkspacePage(Request $request, ProviderServicePurchase $purchase): View
    {
        abort_unless($request->user()?->isAdmin(), 403);

        return view('admin-service-workspace', ['purchase' => $purchase]);
    }

    public function adminWorkspaceData(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $assignees = User::query()
            ->where('role', 'admin')
            ->where(fn ($query) => $query->whereNull('account_status')->orWhere('account_status', 'active'))
            ->with('adminProfile')
            ->orderBy('username')
            ->get()
            ->filter(fn (User $admin) => $admin->hasPortalPermission('manage_billing'))
            ->map(fn (User $admin) => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ])
            ->values();

        return response()->json([
            'purchase' => $this->workspacePayload($purchase, true),
            'assignees' => $assignees,
            'status_options' => [
                ['value' => 'needs_information', 'label' => 'Needs information'],
                ['value' => 'ready', 'label' => 'Ready to start'],
                ['value' => 'in_progress', 'label' => 'In progress'],
                ['value' => 'provider_review', 'label' => 'Ready for provider review'],
            ],
        ]);
    }

    public function decideProviderMeeting(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $this->requirePaidService($purchase);

        if ($purchase->meeting_status !== 'requested' || ! $purchase->meeting_scheduled_for) {
            return response()->json([
                'message' => 'There is no pending meeting request to review.',
            ], 422);
        }

        $validated = $request->validate([
            'meeting_status' => ['required', Rule::in(['confirmed', 'declined'])],
            'meeting_admin_note' => ['nullable', 'required_if:meeting_status,declined', 'string', 'min:5', 'max:1000'],
        ]);
        $adminNote = filled($validated['meeting_admin_note'] ?? null)
            ? trim($validated['meeting_admin_note'])
            : null;

        $purchase->update([
            'meeting_status' => $validated['meeting_status'],
            'meeting_admin_note' => $adminNote,
            'meeting_decided_at' => now(),
            'meeting_decided_by' => $request->user()->id,
        ]);
        $purchase->refresh();

        $meetingDate = $this->meetingDateLabel($purchase);
        $isConfirmed = $purchase->meeting_status === 'confirmed';
        $message = $isConfirmed
            ? "Meeting confirmed for {$meetingDate}."
            : "Meeting request for {$meetingDate} was declined.";

        if ($adminNote) {
            $message .= " Admin note: {$adminNote}";
        }

        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => $isConfirmed ? 'meeting_confirmed' : 'meeting_declined',
            'message' => $message,
            'visible_to_provider' => true,
        ]);

        ActivityLog::record(
            $request->user(),
            $isConfirmed ? 'provider_service_meeting_confirmed' : 'provider_service_meeting_declined',
            "{$request->user()->name} {$purchase->meeting_status} the meeting for {$purchase->reference_number}.",
            $request,
            ['purchase_id' => $purchase->id, 'meeting_scheduled_for' => $purchase->meeting_scheduled_for?->toISOString()],
        );
        $this->notifyProviderTeam(
            $purchase,
            'provider_service_meeting',
            $isConfirmed ? 'Service meeting confirmed' : 'Choose another meeting time',
            $message,
            "provider_service_meeting_decision:{$update->id}",
        );

        return response()->json([
            'message' => $isConfirmed ? 'Meeting confirmed.' : 'Meeting request declined.',
            'purchase' => $this->workspacePayload($purchase, true),
        ]);
    }

    public function storeAdminUpdate(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $this->requirePaidService($purchase);

        $validated = $request->validate([
            'kind' => ['required', Rule::in(['progress_update', 'clarification_request', 'internal_note'])],
            'message' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $isInternal = $validated['kind'] === 'internal_note';
        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => $validated['kind'],
            'message' => trim($validated['message']),
            'visible_to_provider' => ! $isInternal,
        ]);

        if ($validated['kind'] === 'clarification_request') {
            $purchase->update([
                'fulfillment_status' => 'needs_information',
                'fulfillment_notes' => trim($validated['message']),
            ]);
        }

        ActivityLog::record(
            $request->user(),
            'provider_service_update_added',
            "{$request->user()->name} added a {$validated['kind']} to {$purchase->reference_number}.",
            $request,
            [
                'purchase_id' => $purchase->id,
                'update_id' => $update->id,
                'visible_to_provider' => ! $isInternal,
            ],
        );

        if (! $isInternal) {
            $this->notifyProviderTeam(
                $purchase,
                'provider_service_update',
                $validated['kind'] === 'clarification_request' ? 'Information needed for your service' : 'Provider service update',
                trim($validated['message']),
                "provider_service_update:{$update->id}",
            );
        }

        return response()->json([
            'message' => $isInternal ? 'Internal note added.' : 'Provider update posted.',
            'purchase' => $this->workspacePayload($purchase->fresh(), true),
        ], 201);
    }

    public function uploadAdminDeliverable(Request $request, ProviderServicePurchase $purchase): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $this->requirePaidService($purchase);

        if ($purchase->fulfillment_status === 'completed') {
            return response()->json([
                'message' => 'The provider must reopen this service before another deliverable is uploaded.',
            ], 422);
        }

        $validated = $request->validate([
            'service_file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt'],
        ]);
        $file = $validated['service_file'];
        $path = $file->store("provider-services/{$purchase->id}/deliverables", 'local');

        if (! is_string($path)) {
            throw ValidationException::withMessages([
                'service_file' => 'The deliverable could not be stored. Please try again.',
            ]);
        }

        $serviceFile = $purchase->files()->create([
            'uploaded_by' => $request->user()->id,
            'category' => 'deliverable',
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'visible_to_provider' => true,
        ]);
        $update = $purchase->updates()->create([
            'actor_id' => $request->user()->id,
            'kind' => 'deliverable',
            'message' => "Deliverable uploaded: {$serviceFile->original_name}",
            'visible_to_provider' => true,
        ]);

        ActivityLog::record(
            $request->user(),
            'provider_service_deliverable_uploaded',
            "{$request->user()->name} uploaded a deliverable to {$purchase->reference_number}.",
            $request,
            ['purchase_id' => $purchase->id, 'file_id' => $serviceFile->id],
        );
        $this->notifyProviderTeam(
            $purchase,
            'provider_service_deliverable',
            'Service deliverable available',
            "A new deliverable is available for {$purchase->plan_name}.",
            "provider_service_deliverable:{$update->id}",
        );

        return response()->json([
            'message' => 'Deliverable uploaded and shared with the provider.',
            'purchase' => $this->workspacePayload($purchase->fresh(), true),
        ], 201);
    }

    public function viewServiceFile(Request $request, ProviderServiceFile $file)
    {
        $this->authorizeServiceFile($request, $file);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, $file->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function downloadServiceFile(Request $request, ProviderServiceFile $file)
    {
        $this->authorizeServiceFile($request, $file);
        abort_unless(Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->download($file->path, $file->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
            ->with(['provider.providerProfile', 'creator.providerProfile', 'fulfiller.adminProfile', 'assignee.adminProfile'])
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
                'needs_information' => (int) ($counts['needs_information'] ?? 0),
                'ready' => (int) (($counts['ready'] ?? 0) + ($counts['queued'] ?? 0)),
                'in_progress' => (int) ($counts['in_progress'] ?? 0),
                'provider_review' => (int) ($counts['provider_review'] ?? 0),
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
            'fulfillment_status' => ['required', Rule::in([
                'needs_information',
                'ready',
                'in_progress',
                'provider_review',
            ])],
            'fulfillment_notes' => ['nullable', 'string', 'max:2000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'target_due_at' => ['nullable', 'date'],
            'milestones' => ['nullable', 'array', 'max:12'],
            'milestones.*.id' => ['required_with:milestones', 'string', 'max:80'],
            'milestones.*.label' => ['required_with:milestones', 'string', 'max:160'],
            'milestones.*.completed' => ['required_with:milestones', 'boolean'],
            'provider_update' => ['nullable', 'string', 'max:2000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($purchase->status !== 'paid') {
            return response()->json([
                'message' => 'Only confirmed payments can enter service fulfillment.',
            ], 422);
        }

        if (filled($validated['assigned_to'] ?? null)) {
            $assignee = User::query()->find($validated['assigned_to']);

            if (! $assignee?->isAdmin()
                || ! in_array($assignee->account_status, [null, 'active'], true)
                || ! $assignee->hasPortalPermission('manage_billing')) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Choose an active administrator with service-management permission.',
                ]);
            }
        }

        $milestones = array_key_exists('milestones', $validated)
            ? collect($validated['milestones'])
                ->map(fn (array $milestone) => [
                    'id' => $milestone['id'],
                    'label' => trim($milestone['label']),
                    'completed' => (bool) $milestone['completed'],
                ])
                ->values()
                ->all()
            : ($purchase->milestones ?? []);
        $providerUpdate = filled($validated['provider_update'] ?? null)
            ? trim($validated['provider_update'])
            : null;

        if ($validated['fulfillment_status'] === 'needs_information' && ! $providerUpdate) {
            throw ValidationException::withMessages([
                'provider_update' => 'Explain what information the provider needs to supply.',
            ]);
        }

        if ($validated['fulfillment_status'] === 'provider_review') {
            if ($milestones === [] || collect($milestones)->contains(fn (array $milestone) => ! $milestone['completed'])) {
                throw ValidationException::withMessages([
                    'milestones' => 'Complete every service milestone before sending the work for provider review.',
                ]);
            }

            if (! $providerUpdate) {
                throw ValidationException::withMessages([
                    'provider_update' => 'Add a completion summary for the provider before requesting confirmation.',
                ]);
            }
        }

        $previousStatus = $purchase->fulfillment_status;
        $purchase->update([
            'fulfillment_status' => $validated['fulfillment_status'],
            'fulfillment_notes' => filled($validated['fulfillment_notes'] ?? null)
                ? trim($validated['fulfillment_notes'])
                : null,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'priority' => $validated['priority'] ?? $purchase->priority,
            'target_due_at' => $validated['target_due_at'] ?? null,
            'milestones' => $milestones,
            'fulfilled_by' => $request->user()->id,
            'fulfilled_at' => null,
            'provider_confirmed_at' => null,
        ]);

        if ($providerUpdate) {
            $purchase->updates()->create([
                'actor_id' => $request->user()->id,
                'kind' => $validated['fulfillment_status'] === 'needs_information'
                    ? 'clarification_request'
                    : 'progress_update',
                'message' => $providerUpdate,
                'visible_to_provider' => true,
            ]);
        }

        if (filled($validated['internal_note'] ?? null)) {
            $purchase->updates()->create([
                'actor_id' => $request->user()->id,
                'kind' => 'internal_note',
                'message' => trim($validated['internal_note']),
                'visible_to_provider' => false,
            ]);
        }

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

        if ($previousStatus !== $purchase->fulfillment_status || $providerUpdate) {
            $this->notifyProviderTeam(
                $purchase,
                'provider_service_status',
                'Provider service status updated',
                $providerUpdate ?: "{$purchase->plan_name} is now {$this->statusLabel($purchase->fulfillment_status)}.",
                'provider_service_status:'.$purchase->id.':'.Str::uuid(),
            );
        }

        return response()->json([
            'message' => 'Service status updated.',
            'purchase' => $this->workspacePayload($purchase->fresh(), true),
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
            ->each(function (User $user) use ($purchase, $type, $title, $message, $deduplicationPrefix): void {
                PortalNotification::firstOrCreate([
                    'deduplication_key' => "{$deduplicationPrefix}:{$user->id}",
                ], [
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'action_url' => "/provider/billing/{$purchase->id}",
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
                    'action_url' => "/admin/billing/{$purchase->id}",
                ]);
            });
    }

    private function notifyBillingAdminsOfUpdate(
        ProviderServicePurchase $purchase,
        string $title,
        string $message,
        string $deduplicationPrefix,
        string $type = 'provider_service_update',
    ): void {
        User::query()
            ->where('role', 'admin')
            ->where(fn ($query) => $query->whereNull('account_status')->orWhere('account_status', 'active'))
            ->get()
            ->filter(fn (User $user) => $user->hasPortalPermission('manage_billing'))
            ->each(function (User $user) use ($purchase, $title, $message, $deduplicationPrefix, $type): void {
                PortalNotification::firstOrCreate([
                    'deduplication_key' => "{$deduplicationPrefix}:{$user->id}",
                ], [
                    'user_id' => $user->id,
                    'type' => $type,
                    'title' => $title,
                    'message' => $message,
                    'action_url' => "/admin/billing/{$purchase->id}",
                ]);
            });
    }

    private function purchasePayload(ProviderServicePurchase $purchase, bool $includeProvider = false): array
    {
        $purchase->loadMissing(['creator.providerProfile', 'fulfiller.adminProfile', 'assignee.adminProfile', 'meetingDecider.adminProfile']);
        $payload = [
            'id' => $purchase->id,
            'reference_number' => $purchase->reference_number,
            'plan_code' => $purchase->plan_code,
            'plan_name' => $purchase->plan_name,
            'amount' => $purchase->amount,
            'currency' => $purchase->currency,
            'status' => $purchase->status,
            'fulfillment_status' => $purchase->fulfillment_status,
            'priority' => $purchase->priority ?? 'normal',
            'request_summary' => $purchase->request_summary,
            'requested_outcome' => $purchase->requested_outcome,
            'assigned_to' => $purchase->assigned_to,
            'assigned_to_name' => $purchase->assignee?->name,
            'target_due_at' => $purchase->target_due_at?->toISOString(),
            'milestones' => $purchase->milestones ?? [],
            'meeting_scheduled_for' => $purchase->meeting_scheduled_for?->toISOString(),
            'meeting_mode' => $purchase->meeting_mode,
            'meeting_purpose' => $purchase->meeting_purpose,
            'meeting_status' => $purchase->meeting_status,
            'meeting_admin_note' => $purchase->meeting_admin_note,
            'meeting_decided_at' => $purchase->meeting_decided_at?->toISOString(),
            'meeting_decided_by' => $purchase->meeting_decided_by,
            'meeting_decided_by_name' => $purchase->meetingDecider?->name,
            'payment_method' => $purchase->payment_method,
            'livemode' => $purchase->livemode,
            'checkout_url' => $purchase->status === 'pending' ? $purchase->checkout_url : null,
            'created_by' => $purchase->creator?->name,
            'created_at' => $purchase->created_at?->toISOString(),
            'paid_at' => $purchase->paid_at?->toISOString(),
            'fulfilled_at' => $purchase->fulfilled_at?->toISOString(),
            'provider_confirmed_at' => $purchase->provider_confirmed_at?->toISOString(),
            'provider_feedback' => $purchase->provider_feedback,
            'provider_rating' => $purchase->provider_rating,
            'reopened_at' => $purchase->reopened_at?->toISOString(),
            'fulfillment_notes' => $purchase->fulfillment_notes,
            'fulfilled_by' => $purchase->fulfiller?->name,
            'workspace_url' => $includeProvider
                ? "/admin/billing/{$purchase->id}"
                : "/provider/billing/{$purchase->id}",
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

    private function workspacePayload(ProviderServicePurchase $purchase, bool $includeInternal): array
    {
        $purchase->loadMissing([
            'provider.providerProfile',
            'creator.providerProfile',
            'fulfiller.adminProfile',
            'assignee.adminProfile',
            'meetingDecider.adminProfile',
        ]);
        $plan = config("billing.plans.{$purchase->plan_code}", []);
        $milestones = $purchase->milestones;

        if (! is_array($milestones) || $milestones === []) {
            $milestones = $this->defaultMilestones(is_array($plan) ? $plan : []);
        }

        $updates = $purchase->updates()
            ->with(['actor.adminProfile', 'actor.providerProfile'])
            ->when(! $includeInternal, fn ($query) => $query->where('visible_to_provider', true))
            ->oldest()
            ->get()
            ->map(fn (ProviderServiceUpdate $update) => [
                'id' => $update->id,
                'kind' => $update->kind,
                'message' => $update->message,
                'visible_to_provider' => $update->visible_to_provider,
                'actor_name' => $update->actor?->name ?: 'Platform support',
                'actor_role' => $update->actor?->role ?: 'system',
                'created_at' => $update->created_at?->toISOString(),
            ])
            ->values();
        $files = $purchase->files()
            ->with(['uploader.adminProfile', 'uploader.providerProfile'])
            ->when(! $includeInternal, fn ($query) => $query->where('visible_to_provider', true))
            ->latest()
            ->get()
            ->map(fn (ProviderServiceFile $file) => [
                'id' => $file->id,
                'category' => $file->category,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'visible_to_provider' => $file->visible_to_provider,
                'uploaded_by' => $file->uploader?->name ?: 'Platform support',
                'created_at' => $file->created_at?->toISOString(),
                'view_url' => route('service-files.view', $file),
                'download_url' => route('service-files.download', $file),
            ])
            ->values();

        return [
            ...$this->purchasePayload($purchase, $includeInternal),
            'milestones' => $milestones,
            'plan_description' => $plan['description'] ?? null,
            'plan_features' => array_values($plan['features'] ?? []),
            'updates' => $updates,
            'files' => $files,
        ];
    }

    private function meetingDateLabel(ProviderServicePurchase $purchase): string
    {
        return $purchase->meeting_scheduled_for
            ? $purchase->meeting_scheduled_for->timezone(config('app.timezone'))->format('M j, Y g:i A')
            : 'the selected date';
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

    private function authorizeProviderPurchase(Request $request, ProviderServicePurchase $purchase): void
    {
        $user = $request->user();

        abort_unless($user?->isProvider(), 403);
        abort_unless($user->providerOrganizationId() === $purchase->provider_id, 403);
    }

    private function authorizeServiceFile(Request $request, ProviderServiceFile $file): void
    {
        $file->loadMissing('purchase');
        $user = $request->user();

        abort_unless($user, 403);

        if ($user->isAdmin()) {
            abort_unless($user->hasPortalPermission('manage_billing'), 403);

            return;
        }

        abort_unless($user->isProvider(), 403);
        abort_unless($user->hasPortalPermission('manage_billing'), 403);
        abort_unless($file->visible_to_provider, 403);
        abort_unless($file->purchase?->provider_id === $user->providerOrganizationId(), 403);
    }

    private function requirePaidService(ProviderServicePurchase $purchase): void
    {
        if ($purchase->status !== 'paid') {
            throw ValidationException::withMessages([
                'service' => 'The payment must be confirmed before the service workspace can be updated.',
            ]);
        }
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
