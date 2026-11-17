<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EligibilityReviewRequest extends Model
{
    protected $fillable = ['scholarship_id', 'applicant_id', 'context_hash', 'criteria_snapshot', 'explanation', 'evidence_path', 'evidence_name', 'evidence_mime', 'evidence_size', 'status', 'decision_note', 'decided_by', 'decided_at', 'scholarship_application_id', 'consumed_at'];
    protected $hidden = ['evidence_path'];

    protected function casts(): array
    {
        return ['criteria_snapshot' => 'array', 'decided_at' => 'datetime', 'consumed_at' => 'datetime'];
    }

    public function scholarship(): BelongsTo { return $this->belongsTo(Scholarship::class); }
    public function applicant(): BelongsTo { return $this->belongsTo(User::class, 'applicant_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }
}
