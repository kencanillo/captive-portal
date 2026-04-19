<?php

return [
    'context_token_lifetime_minutes' => env('PORTAL_CONTEXT_TOKEN_LIFETIME_MINUTES', 10),
    'session_token_lifetime_minutes' => env('PORTAL_SESSION_TOKEN_LIFETIME_MINUTES', 120),
    'payment_token_lifetime_minutes' => env('PORTAL_PAYMENT_TOKEN_LIFETIME_MINUTES', 180),
    'allow_query_mac_fallback' => env('PORTAL_ALLOW_QUERY_MAC_FALLBACK', false),
];
