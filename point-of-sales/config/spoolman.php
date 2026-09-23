<?php

return [
    'enabled' => env('SPOOLMAN_ENABLED', false),

    'base_url' => rtrim((string) env('SPOOLMAN_BASE_URL', 'http://127.0.0.1:7912'), '/'),

    'ws_url' => env('SPOOLMAN_WS_URL', 'ws://127.0.0.1:7912/api/v1/socket'),

    'timeout_ms' => (int) env('SPOOLMAN_TIMEOUT_MS', 5000),

    /**
     * Shared secret for inbound Spoolman → WMS integration webhook.
     * Spoolman itself has no auth; keep it on a private network and gate via this token.
     */
    'integration_token' => env('SPOOLMAN_INTEGRATION_TOKEN'),

    'weight_variance_warn_pct' => (float) env('WEIGHT_VARIANCE_WARN_PCT', 1),

    'weight_variance_review_pct' => (float) env('WEIGHT_VARIANCE_REVIEW_PCT', 3),
];
