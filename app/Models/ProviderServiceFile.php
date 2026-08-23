<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderServiceFile extends Model
{
    protected $fillable = [
        'provider_service_purchase_id',
        'uploaded_by',
        'category',
        'original_name',
        'path',
        'mime_type',
        'size',
        'visible_to_provider',
    ];

    protected function casts(): array
    {
        return [
            'visible_to_provider' => 'boolean',
            'size' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(ProviderServicePurchase::class, 'provider_service_purchase_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
