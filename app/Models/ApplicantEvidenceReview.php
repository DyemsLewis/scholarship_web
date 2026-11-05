<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ApplicantEvidenceReview extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changed_fields' => 'array', 'revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Evidence review history is append-only.'));
        static::deleting(fn () => throw new LogicException('Evidence review history is append-only.'));
    }
}
