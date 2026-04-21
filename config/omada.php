<?php

return [
    'claim_sync_max_age_seconds' => (int) env('OMADA_CLAIM_SYNC_MAX_AGE_SECONDS', 300),
    'health_signal_max_age_seconds' => (int) env('OMADA_HEALTH_SIGNAL_MAX_AGE_SECONDS', 180),
    'health_runtime_degraded_after_seconds' => (int) env('OMADA_HEALTH_RUNTIME_DEGRADED_AFTER_SECONDS', 300),
    'billing_connection_fee_amount' => (int) env('OMADA_BILLING_CONNECTION_FEE_AMOUNT', 500),
    'billing_connection_fee_currency' => (string) env('OMADA_BILLING_CONNECTION_FEE_CURRENCY', 'PHP'),
    'billing_runtime_degraded_after_seconds' => (int) env('OMADA_BILLING_RUNTIME_DEGRADED_AFTER_SECONDS', 300),
];
