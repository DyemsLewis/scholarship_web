<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientBenefitReleaseAllocation extends Model
{
    protected $fillable = [
        'recipient_benefit_release_record_id',
        'recipient_benefit_entitlement_id',
        'status',
        'scheduled_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function releaseRecord(): BelongsTo
    {
        return $this->belongsTo(RecipientBenefitReleaseRecord::class, 'recipient_benefit_release_record_id');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(RecipientBenefitEntitlement::class, 'recipient_benefit_entitlement_id');
    }
}
