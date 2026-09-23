<?php

return [
    'backup' => [
        'enabled' => env('PLATFORM_BACKUP_ENABLED', false),
        'path' => env('PLATFORM_BACKUP_PATH', storage_path('app/backups')),
        'retention_days' => (int) env('PLATFORM_BACKUP_RETENTION_DAYS', 14),
        'include_private_files' => env('PLATFORM_BACKUP_PRIVATE_FILES', true),
        'include_public_files' => env('PLATFORM_BACKUP_PUBLIC_FILES', true),
        'include_public_uploads' => env('PLATFORM_BACKUP_PUBLIC_UPLOADS', true),
        'public_uploads_path' => public_path('uploads'),
        'mysqldump_binary' => env('MYSQLDUMP_BINARY'),
        'mysql_binary' => env('MYSQL_BINARY'),
        'restore_check_tables' => [
            'users',
            'scholarships',
            'scholarship_applications',
            'application_documents',
            'portal_notifications',
        ],
    ],

    'bootstrap_admin' => [
        'email' => env('ADMIN_EMAIL'),
        'username' => env('ADMIN_USERNAME'),
        'password' => env('ADMIN_PASSWORD'),
    ],
];
