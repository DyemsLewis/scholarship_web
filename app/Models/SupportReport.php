<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportReport extends Model
{
    public const CATEGORIES = [
        'program' => 'Program concern',
        'account' => 'Account concern',
        'technical' => 'Technical problem',
        'other' => 'Other platform concern',
    ];

    protected $fillable = [
        'applicant_id',
        'scholarship_id',
        'provider_id',
        'assigned_role',
        'category',
        'subject',
        'description',
        'status',
        'provider_status',
        'provider_resolved_by',
        'provider_resolved_at',
        'admin_status',
        'admin_resolved_by',
        'admin_resolved_at',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_resolved_at' => 'datetime',
            'admin_resolved_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function providerResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_resolved_by');
    }

    public function adminResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_resolved_by');
    }
}
