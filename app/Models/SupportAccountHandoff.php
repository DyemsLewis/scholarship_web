<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAccountHandoff extends Model
{
    public const TASKS = [
        'sign_in' => 'Check sign-in access',
        'email' => 'Help with email verification',
        'password' => 'Help with password access',
        'restriction' => 'Review an account restriction',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(SupportReport::class, 'support_report_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
