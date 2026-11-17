<?php

return [
    'name' => env('MASTER_CORE_NAME', 'Master Core'),
    'version' => env('MASTER_CORE_VERSION', '1.0.0'),
    'api_prefix' => env('MASTER_CORE_API_PREFIX', 'api'),
    'api_version' => env('MASTER_CORE_API_VERSION', 'v1'),
    'enable_health_endpoint' => (bool) env('MASTER_CORE_ENABLE_HEALTH_ENDPOINT', true),
    'enable_debug_information' => (bool) env('MASTER_CORE_ENABLE_DEBUG_INFORMATION', false),
];
