<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }} · Settings</title>
    @vite(['resources/css/app.css', 'resources/js/settings.ts'])
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <!-- The package web route checks host authentication and the view-settings ability before mounting this UI. -->
    <div id="master-core-settings"
         data-master-core-name="{{ config('master-core.name', 'Master Core') }}"
         data-master-core-version="{{ config('master-core.version', '1.0.0') }}"
         data-api-base="{{ $settingsApiBaseUrl }}"
         data-auth-api-base="{{ url('/'.trim(config('master-core-auth.api_prefix', 'api/v1/auth'), '/')) }}"></div>
</body>
</html>
