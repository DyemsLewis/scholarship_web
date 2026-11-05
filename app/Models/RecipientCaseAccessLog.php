<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecipientCaseAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['dedupe_key'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Case access logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Case access logs are append-only.'));
    }
}
