<?php

namespace MasterCore\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use MasterCore\Settings\Services\SettingsManager;

final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware performs authentication and the host-defined ability check.
        return true;
    }

    public function rules(): array
    {
        return app(SettingsManager::class)->validationRules((string) $this->route('group'));
    }
}
