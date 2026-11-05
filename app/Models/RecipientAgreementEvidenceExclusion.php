<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecipientAgreementEvidenceExclusion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evidence exclusion history is append-only.'));
        static::deleting(fn () => throw new LogicException('Evidence exclusion history is append-only.'));
    }
}
