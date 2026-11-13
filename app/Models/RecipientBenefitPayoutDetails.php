<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class RecipientBenefitPayoutDetails extends Model
{
    protected $table = 'recipient_benefit_payout_details';

    public $timestamps = false;

    protected $fillable = [
        'method', 'destination', 'submitted_at', 'review_status', 'revision',
        'reviewed_by', 'reviewed_at', 'correction_reason',
    ];

    protected $hidden = ['destination'];

    protected function casts(): array
    {
        return [
            'destination' => 'encrypted:array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}
