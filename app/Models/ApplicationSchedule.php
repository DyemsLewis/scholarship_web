<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationSchedule extends Model
{
    protected $fillable = [
        'scholarship_application_id',
        'type',
        'title',
        'scheduled_at',
        'mode',
        'venue',
        'location_address',
        'latitude',
        'longitude',
        'online_url',
        'instructions',
        'status',
        'attendance_status',
        'attendance_notes',
        'applicant_acknowledged_at',
        'completed_at',
        'cancelled_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'applicant_acknowledged_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(ScholarshipApplication::class, 'scholarship_application_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
