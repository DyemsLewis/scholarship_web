<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'academic_ocr' => [
        'enabled' => env('ACADEMIC_OCR_ENABLED', false),
        'endpoint' => env('OCR_SPACE_ENDPOINT', 'https://api.ocr.space/parse/image'),
        'key' => env('OCR_SPACE_API_KEY'),
        'engine' => env('OCR_SPACE_ENGINE', 2),
        'language' => env('OCR_SPACE_LANGUAGE', 'eng'),
        'max_file_size_kb' => env('OCR_SPACE_MAX_FILE_SIZE_KB', 1024),
        'timeout_seconds' => env('ACADEMIC_OCR_TIMEOUT_SECONDS', 30),
    ],

];
