<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class RecipientCaseReview extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['source_revision' => 'integer', 'evidence_ids' => 'array', 'statement_ids' => 'array', 'is_appeal' => 'boolean', 'appeal_due_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Case reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('Case reviews are append-only.'));
    }

    public function agreementCase(): BelongsTo
    {
        return $this->belongsTo(RecipientAgreementCase::class, 'recipient_agreement_case_id');
    }
}
