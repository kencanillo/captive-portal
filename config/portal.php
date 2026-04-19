<?php

return [
    'context_token_lifetime_minutes' => env('PORTAL_CONTEXT_TOKEN_LIFETIME_MINUTES', 10),
    'session_token_lifetime_minutes' => env('PORTAL_SESSION_TOKEN_LIFETIME_MINUTES', 120),
    'payment_token_lifetime_minutes' => env('PORTAL_PAYMENT_TOKEN_LIFETIME_MINUTES', 180),
    'allow_query_mac_fallback' => env('PORTAL_ALLOW_QUERY_MAC_FALLBACK', false),
    'bootstrap_timeout_seconds' => env('PORTAL_BOOTSTRAP_TIMEOUT_SECONDS', 8),
    'ewallet_fee_rate' => env('PORTAL_EWALLET_FEE_RATE', 0.02),
    'omada_connect_timeout_seconds' => env('PORTAL_OMADA_CONNECT_TIMEOUT_SECONDS', 1),
    'omada_timeout_seconds' => env('PORTAL_OMADA_TIMEOUT_SECONDS', 4),
    'omada_mac_lookup_cache_seconds' => env('PORTAL_OMADA_MAC_LOOKUP_CACHE_SECONDS', 45),
];
