<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecipientCasePrivacyReview extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['request_key', 'request_hash'];

    protected function casts(): array
    {
        return ['source_revision' => 'integer', 'case_revision' => 'integer', 'blockers' => 'array', 'audit_retention_hold' => 'boolean', 'recheck_on' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Privacy assessments are append-only.'));
        static::deleting(fn () => throw new LogicException('Privacy assessments are append-only.'));
    }
}
