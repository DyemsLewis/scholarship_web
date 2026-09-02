<?php

return [
    'contact_email' => env('PRIVACY_CONTACT_EMAIL', 'scholarshipfinder@email.com'),

    // Business records and uploaded application files are intentionally excluded.
    // They require an approved retention policy or a resolved privacy request.
    'retention' => [
        'read_notifications_days' => (int) env('RETENTION_READ_NOTIFICATIONS_DAYS', 365),
        'activity_logs_days' => (int) env('RETENTION_ACTIVITY_LOGS_DAYS', 730),
    ],
];
