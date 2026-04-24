<?php

return [
    'context_token_lifetime_minutes' => env('PORTAL_CONTEXT_TOKEN_LIFETIME_MINUTES', 10),
    'session_token_lifetime_minutes' => env('PORTAL_SESSION_TOKEN_LIFETIME_MINUTES', 120),
    'payment_token_lifetime_minutes' => env('PORTAL_PAYMENT_TOKEN_LIFETIME_MINUTES', 180),
    'bootstrap_timeout_seconds' => env('PORTAL_BOOTSTRAP_TIMEOUT_SECONDS', 8),
    'cache_store' => env('PORTAL_CACHE_STORE', env('CACHE_STORE', 'database')),
    'device_context_retry_after_ms' => env('PORTAL_DEVICE_CONTEXT_RETRY_AFTER_MS', 1500),
    'ewallet_fee_rate' => env('PORTAL_EWALLET_FEE_RATE', 0.02),
    'omada_connect_timeout_seconds' => env('PORTAL_OMADA_CONNECT_TIMEOUT_SECONDS', 1),
    'omada_timeout_seconds' => env('PORTAL_OMADA_TIMEOUT_SECONDS', 4),
];
