<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportReport extends Model
{
    public const CATEGORIES = [
        'program' => 'Program concern',
        'account' => 'Account concern',
        'privacy' => 'Privacy and personal data concern',
        'technical' => 'Technical problem',
        'other' => 'Other platform concern',
    ];

    public const PRIVACY_REQUEST_TYPES = [
        'access_copy' => 'Request a copy of my information',
        'correction' => 'Correct personal information',
        'restriction' => 'Restrict how information is used',
        'account_closure' => 'Close my account',
        'deletion_review' => 'Request deletion review',
        'security_incident' => 'Report possible unauthorized access',
    ];

    public const PROVIDER_CATEGORIES = [
        'technical' => 'Technical problem',
        'account' => 'Account or access concern',
        'program' => 'Program or application workflow',
        'service' => 'Service or payment concern',
        'data' => 'Data or export concern',
        'other' => 'Other platform concern',
    ];

    protected $fillable = [
        'applicant_id',
        'scholarship_id',
        'provider_id',
        'assigned_role',
        'category',
        'privacy_request_type',
        'subject',
        'description',
        'context',
        'attachment_path',
        'attachment_original_name',
        'attachment_mime_type',
        'attachment_size',
        'status',
        'provider_status',
        'provider_resolved_by',
        'provider_resolved_at',
        'admin_status',
        'admin_resolved_by',
        'admin_resolved_at',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_resolved_at' => 'datetime',
            'admin_resolved_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applicant_id');
    }

    public function scholarship(): BelongsTo
    {
        return $this->belongsTo(Scholarship::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function providerResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_resolved_by');
    }

    public function adminResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_resolved_by');
    }
}
