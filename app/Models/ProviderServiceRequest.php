<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProviderServiceRequest extends Model
{
    public const STATUSES = [
        'ready_for_review',
        'checkout_started',
        'converted',
        'cancelled',
    ];

    protected $fillable = [
        'provider_id',
        'created_by',
        'scholarship_id',
        'plan_code',
        'plan_name',
        'amount',
        'currency',
        'status',
        'answers',
        'request_summary',
        'requested_outcome',
        'submitted_at',
        'reviewed_at',
        'terms_accepted_at',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'answers' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'converted_at' => 'datetime',
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

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function purchase(): HasOne
    {
        return $this->hasOne(ProviderServicePurchase::class, 'service_request_id');
    }
}
