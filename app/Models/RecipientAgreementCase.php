<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipientAgreementCase extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['request_key', 'request_hash', 'agreement_snapshot', 'benefit_snapshot', 'open_obligation_key', 'open_delivery_key'];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'agreement_snapshot' => 'array',
            'benefit_snapshot' => 'array',
            'agreement_accepted_at' => 'immutable_datetime',
            'notice_sent_at' => 'immutable_datetime',
            'response_due_at' => 'immutable_datetime',
            'response_received_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'disclosure_review_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'follow_up_on' => 'immutable_date',
            'escalated_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(RecipientBenefitEntitlement::class, 'benefit_entitlement_id');
    }

    public function releaseRecord(): BelongsTo
    {
        return $this->belongsTo(RecipientBenefitReleaseRecord::class, 'benefit_release_record_id');
    }

    public function supportDecision(): BelongsTo
    {
        return $this->belongsTo(RecipientSupportDecision::class, 'support_decision_id');
    }

    public function legacyReceiptResponse(): BelongsTo
    {
        return $this->belongsTo(RecipientBenefitReceiptResponse::class, 'legacy_receipt_response_id');
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(RecipientAgreementCaseResponse::class, 'proposal_response_id');
    }

    public function latestReview(): BelongsTo
    {
        return $this->belongsTo(RecipientCaseReview::class, 'latest_review_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RecipientCaseReview::class, 'recipient_agreement_case_id')->orderByDesc('id');
    }

    public function privacyReviews(): HasMany
    {
        return $this->hasMany(RecipientCasePrivacyReview::class, 'recipient_agreement_case_id')->orderByDesc('id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(RecipientAgreementCaseResponse::class)->orderByDesc('case_revision');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(RecipientAgreementCaseEvidence::class)->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecipientAgreementCaseEvent::class)->orderByDesc('revision');
    }

    public function responseDeadlinePassed(?CarbonInterface $at = null): bool
    {
        return $this->status === 'awaiting_response'
            && $this->notice_sent_at !== null
            && $this->response_received_at === null
            && $this->response_due_at?->lessThan($at ?? now()) === true;
    }

    public function canShareWithOtherProviders(): bool
    {
        // Privacy assessments do not grant external access; activation needs a separate reviewed implementation.
        return false;
    }
}
