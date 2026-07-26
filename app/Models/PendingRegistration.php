<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingRegistration extends Model
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    protected $fillable = [
        'token',
        'email',
        'username',
        'role',
        'payload',
        'password_hash',
        'code_hash',
        'attempts',
        'expires_at',
        'last_sent_at',
    ];

    protected $hidden = [
        'payload',
        'password_hash',
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }
}
