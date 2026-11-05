<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class RecipientAgreementCaseEvidence extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'recipient_agreement_case_evidence';

    protected $guarded = ['*'];

    protected $hidden = ['path', 'sha256'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Agreement case evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Agreement case evidence is append-only.'));
    }

    public function agreementCase(): BelongsTo
    {
        return $this->belongsTo(RecipientAgreementCase::class, 'recipient_agreement_case_id');
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(RecipientAgreementCaseResponse::class, 'response_id');
    }

    public function exclusion(): HasOne
    {
        return $this->hasOne(RecipientAgreementEvidenceExclusion::class, 'evidence_id');
    }
}
