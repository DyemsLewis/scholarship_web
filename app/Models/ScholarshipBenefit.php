<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipBenefit extends Model
{
    public const TYPE_LABELS = [
        'cash_grant' => 'Cash grant',
        'tuition_coverage' => 'Tuition coverage',
        'allowance' => 'Allowance',
        'school_supplies' => 'School supplies',
        'device_support' => 'Device support',
        'transportation' => 'Transportation support',
        'accommodation' => 'Accommodation support',
        'training' => 'Training or certification',
        'mentorship' => 'Mentorship',
        'fee_waiver' => 'Fee waiver',
        'other' => 'Other benefit',
    ];

    public const COVERAGE_LABELS = [
        'full' => 'Full coverage',
        'partial' => 'Partial coverage',
        'fixed' => 'Fixed-value coverage',
    ];

    public const FREQUENCY_LABELS = [
        'one_time' => 'One-time',
        'monthly' => 'Monthly',
        'per_term' => 'Per term or semester',
        'annual' => 'Annual',
        'entire_program' => 'Entire program',
    ];

    protected $fillable = [
        'type',
        'title',
        'amount',
        'coverage',
        'frequency',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function programPayload(): array
    {
        return self::payloadFromValues(
            $this->type,
            $this->title,
            $this->amount,
            $this->coverage,
            $this->frequency,
            $this->description,
        );
    }

    public static function payloadFromValues(
        string $type,
        ?string $title,
        mixed $amount,
        ?string $coverage,
        ?string $frequency,
        ?string $description,
    ): array {
        $label = filled($title) ? trim((string) $title) : (self::TYPE_LABELS[$type] ?? 'Program benefit');
        $details = [];

        if (filled($coverage) && isset(self::COVERAGE_LABELS[$coverage])) {
            $details[] = self::COVERAGE_LABELS[$coverage];
        }

        if ($amount !== null && $amount !== '') {
            $details[] = 'PHP '.number_format((float) $amount, 2);
        }

        if (filled($frequency) && isset(self::FREQUENCY_LABELS[$frequency])) {
            $details[] = self::FREQUENCY_LABELS[$frequency];
        }

        return [
            'type' => $type,
            'type_label' => self::TYPE_LABELS[$type] ?? 'Program benefit',
            'title' => $label,
            'amount' => $amount === null || $amount === '' ? null : number_format((float) $amount, 2, '.', ''),
            'coverage' => $coverage,
            'coverage_label' => $coverage ? (self::COVERAGE_LABELS[$coverage] ?? $coverage) : null,
            'frequency' => $frequency,
            'frequency_label' => $frequency ? (self::FREQUENCY_LABELS[$frequency] ?? $frequency) : null,
            'description' => filled($description) ? trim((string) $description) : null,
            'display_summary' => $details === [] ? $label : $label.' ('.implode(', ', $details).')',
        ];
    }
}
