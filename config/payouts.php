<?php

return [
    'mode' => env('PAYOUT_MODE', 'manual'),
    'provider' => env('PAYOUT_PROVIDER', 'paymongo'),
    'currency' => env('PAYOUT_CURRENCY', 'PHP'),

    'providers' => [
        'paymongo' => [
            'enabled' => env('PAYMONGO_PAYOUTS_ENABLED', false),
            'wallet_id' => env('PAYMONGO_PAYOUT_WALLET_ID'),
            'callback_url' => env('PAYMONGO_PAYOUT_CALLBACK_URL'),
        ],
    ],
];
