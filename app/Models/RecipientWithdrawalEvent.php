<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecipientWithdrawalEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Withdrawal history is append-only.'));
        static::deleting(fn () => throw new LogicException('Withdrawal history is append-only.'));
    }
}
