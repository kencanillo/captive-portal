<?php

return [
    'scheduler_heartbeat_degraded_after_seconds' => (int) env('OPS_SCHEDULER_HEARTBEAT_DEGRADED_AFTER_SECONDS', 180),
    'queue_worker_heartbeat_degraded_after_seconds' => (int) env('OPS_QUEUE_WORKER_HEARTBEAT_DEGRADED_AFTER_SECONDS', 180),
    'release_runtime_degraded_after_seconds' => (int) env('OPS_RELEASE_RUNTIME_DEGRADED_AFTER_SECONDS', 300),
    'verification_cache_ttl_seconds' => (int) env('OPS_VERIFICATION_CACHE_TTL_SECONDS', 86400),
];
