<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderServicePurchase extends Model
{
    public const PAYMENT_STATUSES = [
        'pending',
        'paid',
        'failed',
    ];

    public const FULFILLMENT_STATUSES = [
        'queued',
        'in_progress',
        'completed',
    ];

    protected $fillable = [
        'provider_id',
        'created_by',
        'plan_code',
        'plan_name',
        'amount',
        'currency',
        'status',
        'fulfillment_status',
        'reference_number',
        'checkout_session_id',
        'checkout_url',
        'payment_intent_id',
        'payment_id',
        'payment_method',
        'livemode',
        'service_terms_accepted_at',
        'paid_at',
        'failed_at',
        'fulfilled_at',
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
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
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

    public function fulfiller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by');
    }
}
