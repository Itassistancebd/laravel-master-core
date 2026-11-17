<?php

return [
    'api_prefix' => null,
    'table' => 'master_core_audit_events',
    'max_payload_bytes' => 16384,
    'max_user_agent_bytes' => 512,
    'capture_ip_address' => env('MASTER_CORE_AUDIT_CAPTURE_IP', true),
    'capture_user_agent' => env('MASTER_CORE_AUDIT_CAPTURE_USER_AGENT', true),
    'correlation_header' => 'X-Correlation-ID',
    'max_date_range_days' => 90,
    'max_per_page' => 100,
    // Retention is host-configurable. Destructive cleanup is always opt-in and never scheduled by this package.
    'retention' => [
        'period_days' => env('MASTER_CORE_AUDIT_RETENTION_DAYS'),
        'policy_approved' => env('MASTER_CORE_AUDIT_RETENTION_APPROVED', false),
        'purge_enabled' => env('MASTER_CORE_AUDIT_PURGE_ENABLED', false),
    ],
    'abilities' => [
        'view' => 'view-audit-logs',
        'export' => 'export-audit-logs',
    ],
];
