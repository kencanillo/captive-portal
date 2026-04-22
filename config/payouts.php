<?php

return [
    'mode' => env('PAYOUT_MODE', 'manual'),
    'provider' => env('PAYOUT_PROVIDER', 'paymongo'),
    'execution_provider' => env('PAYOUT_EXECUTION_PROVIDER', 'manual'),
    'currency' => env('PAYOUT_CURRENCY', 'PHP'),

    'providers' => [
        'paymongo' => [
            'enabled' => env('PAYMONGO_PAYOUTS_ENABLED', false),
            'wallet_id' => env('PAYMONGO_PAYOUT_WALLET_ID'),
            'callback_url' => env('PAYMONGO_PAYOUT_CALLBACK_URL'),
            'live_execution_enabled' => env('PAYMONGO_LIVE_EXECUTION_ENABLED', false),
        ],
    ],

    'execution' => [
        'pending_stale_minutes' => env('PAYOUT_EXECUTION_PENDING_STALE_MINUTES', 15),
        'dispatched_stale_minutes' => env('PAYOUT_EXECUTION_DISPATCHED_STALE_MINUTES', 60),
        'manual_followup_stale_minutes' => env('PAYOUT_EXECUTION_MANUAL_FOLLOWUP_STALE_MINUTES', 240),
        'background_reconcile_limit' => env('PAYOUT_EXECUTION_BACKGROUND_RECONCILE_LIMIT', 25),
        'max_retry_attempts' => env('PAYOUT_EXECUTION_MAX_RETRY_ATTEMPTS', 2),
        'reconcile_heartbeat_degraded_after_seconds' => env('PAYOUT_EXECUTION_RECONCILE_HEARTBEAT_DEGRADED_AFTER_SECONDS', 300),
    ],
];
