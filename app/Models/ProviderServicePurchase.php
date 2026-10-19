<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderServicePurchase extends Model
{
    public const PAYMENT_STATUSES = [
        'pending',
        'paid',
        'failed',
    ];

    public const FULFILLMENT_STATUSES = [
        'queued',
        'needs_information',
        'ready',
        'in_progress',
        'provider_review',
        'completed',
        'cancelled',
    ];

    public const CANCELLATION_STATUSES = [
        'requested',
        'approved',
        'declined',
        'reversed',
    ];

    public const REFUND_STATUSES = [
        'not_applicable',
        'manual_refund_pending',
        'refunded',
    ];

    protected $fillable = [
        'provider_id',
        'created_by',
        'service_request_id',
        'plan_code',
        'plan_name',
        'amount',
        'currency',
        'status',
        'fulfillment_status',
        'current_action_owner',
        'current_action',
        'action_due_at',
        'reference_number',
        'checkout_session_id',
        'checkout_url',
        'payment_intent_id',
        'payment_id',
        'payment_method',
        'livemode',
        'service_terms_accepted_at',
        'request_summary',
        'requested_outcome',
        'priority',
        'assigned_to',
        'target_due_at',
        'milestones',
        'meeting_scheduled_for',
        'meeting_mode',
        'meeting_purpose',
        'meeting_status',
        'meeting_admin_note',
        'meeting_join_url',
        'meeting_decided_at',
        'meeting_decided_by',
        'paid_at',
        'started_at',
        'failed_at',
        'fulfilled_at',
        'closed_at',
        'provider_confirmed_at',
        'provider_feedback',
        'provider_rating',
        'reopened_at',
        'revision_count',
        'cancellation_status',
        'cancellation_reason',
        'cancellation_requested_at',
        'cancellation_requested_by',
        'cancellation_decision_note',
        'cancellation_decided_at',
        'cancellation_decided_by',
        'cancelled_at',
        'refund_status',
        'refund_reference',
        'fulfilled_by',
        'fulfillment_notes',
        'failure_message',
        'gateway_metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'livemode' => 'boolean',
            'service_terms_accepted_at' => 'datetime',
            'target_due_at' => 'datetime',
            'action_due_at' => 'datetime',
            'milestones' => 'array',
            'meeting_scheduled_for' => 'datetime',
            'meeting_decided_at' => 'datetime',
            'paid_at' => 'datetime',
            'started_at' => 'datetime',
            'failed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'closed_at' => 'datetime',
            'provider_confirmed_at' => 'datetime',
            'provider_rating' => 'integer',
            'reopened_at' => 'datetime',
            'revision_count' => 'integer',
            'cancellation_requested_at' => 'datetime',
            'cancellation_decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'gateway_metadata' => 'array',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ProviderServiceRequest::class, 'service_request_id');
    }

    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function meetingDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'meeting_decided_by');
    }

    public function cancellationRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by');
    }

    public function cancellationDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancellation_decided_by');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ProviderServiceUpdate::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ProviderServiceFile::class);
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(ProviderServiceLifecycleEvent::class);
    }
}
