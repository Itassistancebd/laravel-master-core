<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel Master Core') }} - Authentication</title>
    @vite(['resources/css/app.css', 'resources/js/auth.ts'])
</head>
<body>
    {{-- Keep the Blade shell neutral; the package SPA owns only the auth page selected by Fortify or /app. --}}
    {{-- Use the same Foundation prefix/version for every package-owned API base. --}}
    <div id="master-core-auth"
         data-master-core-name="{{ config('master-core.name', 'Master Core') }}"
         data-master-core-version="{{ config('master-core.version', '1.0.0') }}"
         data-page="{{ $page ?? 'application' }}"
         data-token="{{ $token ?? '' }}"
         data-email="{{ $email ?? '' }}"
         data-api-base="{{ url('/'.trim(config('master-core-auth.api_prefix', 'api/v1/auth'), '/')) }}"
         data-users-api-base="{{ url('/'.trim(implode('/', array_filter([config('master-core.api_prefix', 'api'), config('master-core.api_version', 'v1'), 'users'])), '/')) }}"
         data-roles-permissions-api-base="{{ url('/'.trim(implode('/', array_filter([config('master-core.api_prefix', 'api'), config('master-core.api_version', 'v1'), 'roles-permissions'])), '/')) }}"
         data-audit-logs-api-base="{{ url('/'.trim(implode('/', array_filter([config('master-core.api_prefix', 'api'), config('master-core.api_version', 'v1'), 'audit-logs'])), '/')) }}"
         data-system-health-api-base="{{ url('/'.trim(implode('/', array_filter([config('master-core.api_prefix', 'api'), config('master-core.api_version', 'v1'), 'system-health'])), '/')) }}"
         data-notifications-api-base="{{ url('/'.trim(implode('/', array_filter([config('master-core.api_prefix', 'api'), config('master-core.api_version', 'v1'), 'notifications'])), '/')) }}"
         data-dashboard-api-base="{{ url('/'.trim(implode('/', array_filter([config('master-core.api_prefix', 'api'), config('master-core.api_version', 'v1'), 'core', 'dashboard'])), '/')) }}"></div>
</body>
</html>
