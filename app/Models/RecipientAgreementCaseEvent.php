<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RecipientAgreementCaseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'payload' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Agreement case history is append-only.'));
        static::deleting(fn () => throw new LogicException('Agreement case history is append-only.'));
    }

    public function agreementCase(): BelongsTo
    {
        return $this->belongsTo(RecipientAgreementCase::class, 'recipient_agreement_case_id');
    }
}
