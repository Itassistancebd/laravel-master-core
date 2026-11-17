<?php

namespace MasterCore\Settings\Http\Controllers;

use Illuminate\Contracts\View\View;

final class SettingsPageController
{
    public function __invoke(): View
    {
        return view('master-core-settings::settings', [
            'settingsApiBaseUrl' => route('master-core.settings.index'),
        ]);
    }
}
