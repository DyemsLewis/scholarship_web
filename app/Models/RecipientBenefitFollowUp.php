<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipientBenefitFollowUp extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer', 'events' => 'array',
            'notice_sent_at' => 'datetime', 'reminded_at' => 'datetime', 'respond_by' => 'datetime',
            'responded_at' => 'datetime', 'resolved_at' => 'datetime',
        ];
    }

    public function releaseRecord(): BelongsTo
    {
        return $this->belongsTo(RecipientBenefitReleaseRecord::class, 'release_record_id');
    }
}
