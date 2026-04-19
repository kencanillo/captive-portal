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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paymongo' => [
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
        'connect_timeout_seconds' => env('PAYMONGO_CONNECT_TIMEOUT_SECONDS', 2),
        'timeout_seconds' => env('PAYMONGO_TIMEOUT_SECONDS', 4),
        'qrph_expiry_seconds' => env('PAYMONGO_QRPH_EXPIRY_SECONDS', 1800),
        'webhook_tolerance_seconds' => env('PAYMONGO_WEBHOOK_TOLERANCE_SECONDS', 300),
        'payouts_enabled' => env('PAYMONGO_PAYOUTS_ENABLED', false),
        'payout_wallet_id' => env('PAYMONGO_PAYOUT_WALLET_ID'),
        'payout_callback_url' => env('PAYMONGO_PAYOUT_CALLBACK_URL'),
    ],

    'omada' => [
        'verify_ssl' => env('OMADA_VERIFY_SSL', true),
    ],

];
