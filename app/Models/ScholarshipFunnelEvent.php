<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScholarshipFunnelEvent extends Model
{
    protected $fillable = [
        'user_id',
        'scholarship_id',
        'scholarship_application_id',
        'event_type',
        'source',
        'metadata',
        'deduplication_key',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public static function record(
        ?User $user,
        string $eventType,
        ?Scholarship $scholarship = null,
        ?ScholarshipApplication $application = null,
        string $source = 'web',
        array $metadata = [],
        ?string $deduplicationKey = null,
    ): self {
        $attributes = [
            'user_id' => $user?->id,
            'scholarship_id' => $scholarship?->id ?? $application?->scholarship_id,
            'scholarship_application_id' => $application?->id,
            'event_type' => $eventType,
            'source' => $source,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ];

        if ($deduplicationKey !== null) {
            return self::query()->firstOrCreate(
                ['deduplication_key' => $deduplicationKey],
                $attributes,
            );
        }

        return self::query()->create($attributes);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }
}
