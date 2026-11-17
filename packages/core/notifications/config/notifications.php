<?php

return [
    'api_prefix' => null,
    'max_payload_bytes' => 4096,
    'retention_days' => env('MASTER_CORE_NOTIFICATIONS_RETENTION_DAYS'),
    // Explicit host opt-in prevents accidental destructive cleanup from configuration alone.
    'retention_approved' => env('MASTER_CORE_NOTIFICATIONS_RETENTION_APPROVED', false),
    'purge_enabled' => env('MASTER_CORE_NOTIFICATIONS_PURGE_ENABLED', false),
    'purge_max_rows' => 1000,
    'mark_all_batch_size' => 500,
];
