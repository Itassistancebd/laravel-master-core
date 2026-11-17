<?php

namespace MasterCore\Settings\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MasterCore\Settings\Services\SettingsManager;
use Symfony\Component\HttpFoundation\Response;

final class ApplySettingsForRequest
{
    public function __construct(private readonly SettingsManager $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $previousLocale = app()->getLocale();
        $previousTimezone = date_default_timezone_get();
        $localization = $this->settings->group('localization')['settings'];
        $locale = $localization['locale']['value'] ?? $previousLocale;
        $timezone = $localization['timezone']['value'] ?? $previousTimezone;

        try {
            if (is_string($locale) && $locale !== '') {
                app()->setLocale($locale);
            }

            if (is_string($timezone) && in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                date_default_timezone_set($timezone);
            }

            return $next($request);
        } finally {
            // Restore process-wide values so a long-lived worker cannot leak one request's settings.
            app()->setLocale($previousLocale);
            date_default_timezone_set($previousTimezone);
        }
    }
}
