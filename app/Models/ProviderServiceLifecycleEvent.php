<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderServiceLifecycleEvent extends Model
{
    protected $fillable = [
        'provider_service_purchase_id',
        'actor_id',
        'event_type',
        'payment_from',
        'payment_to',
        'delivery_from',
        'delivery_to',
        'action_owner',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(ProviderServicePurchase::class, 'provider_service_purchase_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
