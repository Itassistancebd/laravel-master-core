<?php

return [
    'cache' => [
        'enabled' => true,
        'ttl_seconds' => 300,
        'prefix' => 'master-core:settings:v1',
    ],

    'authorization' => [
        'view_ability' => 'view-settings',
        'manage_ability' => 'manage-settings',
    ],

    'branding' => [
        'disk' => env('MASTER_CORE_SETTINGS_BRANDING_DISK', 'public'),
        'max_upload_kilobytes' => 2048,
    ],

    'options' => [
        'locales' => [
            'en' => 'English',
            'bn' => 'Bangla',
        ],
        'currencies' => [
            'BDT' => ['label' => 'Bangladeshi Taka (৳)', 'symbol' => '৳'],
            'EUR' => ['label' => 'Euro (€)', 'symbol' => '€'],
            'GBP' => ['label' => 'Pound Sterling (£)', 'symbol' => '£'],
            'USD' => ['label' => 'US Dollar ($)', 'symbol' => '$'],
        ],
        'date_formats' => [
            'Y-m-d' => '2026-11-14',
            'd/m/Y' => '14/11/2026',
            'd M Y' => '14 Nov 2026',
        ],
        'time_formats' => [
            'H:i' => '14:30',
            'H:i:s' => '14:30:00',
            'g:i A' => '2:30 PM',
        ],
        'date_time_formats' => [
            'Y-m-d H:i' => '2026-11-14 14:30',
            'd/m/Y H:i' => '14/11/2026 14:30',
            'd M Y g:i A' => '14 Nov 2026 2:30 PM',
        ],
    ],

    // Definitions are the allowlist and the source of defaults and validation rules.
    'groups' => [
        'general' => [
            'label' => 'General',
            'fields' => [
                'application_name' => [
                    'label' => 'Application name',
                    'type' => 'text',
                    'default' => config('app.name', 'Laravel'),
                    'rules' => ['required', 'string', 'max:120'],
                    'public' => true,
                ],
                'description' => [
                    'label' => 'Description',
                    'type' => 'textarea',
                    'default' => '',
                    'rules' => ['nullable', 'string', 'max:500'],
                    'public' => true,
                ],
                'support_email' => [
                    'label' => 'Support email',
                    'type' => 'email',
                    'default' => null,
                    'rules' => ['nullable', 'email:rfc', 'max:254'],
                    'public' => true,
                ],
            ],
        ],
        'branding' => [
            'label' => 'Application Branding',
            'fields' => [
                'logo_path' => [
                    'label' => 'Logo',
                    'type' => 'image_path',
                    'default' => null,
                    'rules' => ['nullable', 'string', 'max:512'],
                    'public' => true,
                ],
                'favicon_path' => [
                    'label' => 'Favicon',
                    'type' => 'image_path',
                    'default' => null,
                    'rules' => ['nullable', 'string', 'max:512'],
                    'public' => true,
                ],
            ],
        ],
        'localization' => [
            'label' => 'Localization',
            'fields' => [
                'locale' => [
                    'label' => 'Default locale',
                    'type' => 'searchable_select',
                    'default' => config('app.locale', 'en'),
                    'options' => 'locales',
                    'rules' => ['required', 'string'],
                    'public' => true,
                ],
                'fallback_locale' => [
                    'label' => 'Fallback locale',
                    'type' => 'searchable_select',
                    'default' => config('app.fallback_locale', 'en'),
                    'options' => 'locales',
                    'rules' => ['required', 'string'],
                    'public' => true,
                ],
                'supported_locales' => [
                    'label' => 'Supported locales',
                    'type' => 'searchable_multiselect',
                    'default' => ['en'],
                    'options' => 'locales',
                    'rules' => ['required', 'array', 'min:1'],
                    'item_rules' => ['required', 'string'],
                    'public' => true,
                ],
                'timezone' => [
                    'label' => 'Application timezone',
                    'type' => 'searchable_select',
                    'default' => config('app.timezone', 'UTC'),
                    'options' => 'timezones',
                    'rules' => ['required', 'timezone'],
                    'public' => true,
                ],
            ],
        ],
        'date_time' => [
            'label' => 'Date and Time Formats',
            'fields' => [
                'date_format' => [
                    'label' => 'Date format',
                    'type' => 'searchable_select',
                    'default' => 'Y-m-d',
                    'options' => 'date_formats',
                    'rules' => ['required', 'string'],
                    'public' => true,
                ],
                'time_format' => [
                    'label' => 'Time format',
                    'type' => 'searchable_select',
                    'default' => 'H:i',
                    'options' => 'time_formats',
                    'rules' => ['required', 'string'],
                    'public' => true,
                ],
                'date_time_format' => [
                    'label' => 'Date and time format',
                    'type' => 'searchable_select',
                    'default' => 'Y-m-d H:i',
                    'options' => 'date_time_formats',
                    'rules' => ['required', 'string'],
                    'public' => true,
                ],
            ],
        ],
        'currency' => [
            'label' => 'Currency',
            'fields' => [
                'currency_code' => [
                    'label' => 'Currency',
                    'type' => 'searchable_select',
                    'default' => 'USD',
                    'options' => 'currencies',
                    'rules' => ['required', 'string', 'size:3'],
                    'public' => true,
                ],
                'currency_symbol' => [
                    'label' => 'Currency symbol',
                    'type' => 'text',
                    'default' => '$',
                    'rules' => ['required', 'string', 'max:8'],
                    'public' => true,
                ],
                'decimal_places' => [
                    'label' => 'Decimal places',
                    'type' => 'number',
                    'default' => 2,
                    'rules' => ['required', 'integer', 'between:0,4'],
                    'public' => true,
                ],
                'symbol_position' => [
                    'label' => 'Symbol position',
                    'type' => 'searchable_select',
                    'default' => 'before',
                    'options' => ['before' => 'Before amount', 'after' => 'After amount'],
                    'rules' => ['required', 'string', 'in:before,after'],
                    'public' => true,
                ],
                'decimal_separator' => [
                    'label' => 'Decimal separator',
                    'type' => 'text',
                    'default' => '.',
                    'rules' => ['required', 'string', 'size:1'],
                    'public' => true,
                ],
                'thousands_separator' => [
                    'label' => 'Thousands separator',
                    'type' => 'text',
                    'default' => ',',
                    'rules' => ['required', 'string', 'size:1', 'different:decimal_separator'],
                    'public' => true,
                ],
            ],
        ],
        'appearance' => [
            'label' => 'Appearance',
            'fields' => [
                'default_theme' => [
                    'label' => 'Default theme',
                    'type' => 'searchable_select',
                    'default' => 'system',
                    'options' => ['light' => 'Light', 'dark' => 'Dark', 'system' => 'Use system preference'],
                    'rules' => ['required', 'string', 'in:light,dark,system'],
                    'public' => true,
                ],
                'accent_color' => [
                    'label' => 'Accent color',
                    'type' => 'color',
                    'default' => '#2563eb',
                    'rules' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                    'public' => true,
                ],
            ],
        ],
        'system' => [
            'label' => 'System',
            'fields' => [
                'default_page_size' => [
                    'label' => 'Default page size',
                    'type' => 'searchable_select',
                    'default' => 25,
                    'options' => [10 => '10', 25 => '25', 50 => '50', 100 => '100'],
                    'rules' => ['required', 'integer', 'in:10,25,50,100'],
                    'public' => false,
                ],
            ],
        ],
    ],
];
