<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderServiceUpdate extends Model
{
    protected $fillable = [
        'provider_service_purchase_id',
        'actor_id',
        'kind',
        'message',
        'visible_to_provider',
    ];

    protected function casts(): array
    {
        return [
            'visible_to_provider' => 'boolean',
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
