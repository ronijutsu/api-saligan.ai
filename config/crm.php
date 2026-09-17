<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CRM mutation throttle
    |--------------------------------------------------------------------------
    |
    | Authenticated CRM writes are bounded per user and source IP. Keep this
    | value in config so deployment settings survive config:cache.
    |
    */
    'mutations_per_minute' => (int) env('CRM_MUTATIONS_PER_MINUTE', 60),

    /*
    |--------------------------------------------------------------------------
    | Idempotency retention
    |--------------------------------------------------------------------------
    |
    | Completed CRM mutation responses remain replayable for this period. The
    | value is configurable so operations can tune storage without changing
    | command behavior or the public API.
    |
    */
    'idempotency_retention_hours' => (int) env('CRM_IDEMPOTENCY_RETENTION_HOURS', 24),
];
