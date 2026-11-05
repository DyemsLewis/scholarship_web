<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RecipientAgreementCaseResponse extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['request_key', 'request_hash'];

    protected function casts(): array
    {
        return ['case_revision' => 'integer', 'notice_revision' => 'integer', 'delivered_on' => 'immutable_date', 'follow_up_on' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Agreement case responses are append-only.'));
        static::deleting(fn () => throw new LogicException('Agreement case responses are append-only.'));
    }

    public function agreementCase(): BelongsTo
    {
        return $this->belongsTo(RecipientAgreementCase::class, 'recipient_agreement_case_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(RecipientAgreementCaseEvidence::class, 'response_id');
    }
}
