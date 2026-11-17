<?php

namespace MasterCore\ModuleManager\Http\Resources;

final class ModuleResource
{
    /** Explicit response allowlist keeps internal provider classes and paths out of the API. */
    public static function make(array $module): array
    {
        return [
            'key' => $module['key'],
            'name' => $module['name'],
            'description' => $module['description'],
            'composer_package' => $module['composer_package'],
            'installation' => [
                'installed' => $module['installation']['installed'],
                'version' => $module['installation']['version'],
            ],
            'dependencies' => $module['dependencies'],
            'provider_loaded' => $module['provider_loaded'],
            'readiness_status' => $module['readiness_status'],
            'documentation' => $module['documentation'],
        ];
    }
}
