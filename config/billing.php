<?php

return [
    'enabled' => (bool) env('PROVIDER_BILLING_ENABLED', false),
    'currency' => 'PHP',

    'paymongo' => [
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'payment_methods' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PAYMONGO_PAYMENT_METHODS', 'card,gcash,qrph')),
        ))),
        'send_email_receipt' => true,
        'pass_on_fees' => false,
        'signature_tolerance_seconds' => (int) env('PAYMONGO_SIGNATURE_TOLERANCE', 300),
    ],

    'plans' => [
        'assisted_setup' => [
            'name' => 'Assisted program setup',
            'short_name' => 'Assisted setup',
            'description' => 'One guided setup session for a scholarship program and its applicant requirements.',
            'best_for' => 'Providers preparing their first program in the portal.',
            'amount' => (int) env('PROVIDER_ASSISTED_SETUP_AMOUNT', 75000),
            'features' => [
                'Program form walkthrough',
                'Requirement and eligibility review',
                'Publishing-readiness check',
            ],
        ],
        'application_cycle_support' => [
            'name' => 'Application cycle support',
            'short_name' => 'Cycle support',
            'description' => 'Operational help organizing one active application cycle in the portal.',
            'best_for' => 'Teams managing a busy or unfamiliar application cycle.',
            'amount' => (int) env('PROVIDER_CYCLE_SUPPORT_AMOUNT', 250000),
            'features' => [
                'Workflow setup review',
                'Applicant queue organization',
                'Schedule and notification check',
            ],
        ],
        'integration_consultation' => [
            'name' => 'Integration consultation',
            'short_name' => 'Integration consultation',
            'description' => 'A technical consultation for connecting an existing provider process to the portal.',
            'best_for' => 'Organizations moving an existing scholarship process into the portal.',
            'amount' => (int) env('PROVIDER_INTEGRATION_CONSULTATION_AMOUNT', 150000),
            'features' => [
                'Current-process review',
                'Data and workflow mapping',
                'Implementation recommendations',
            ],
        ],
    ],
];
