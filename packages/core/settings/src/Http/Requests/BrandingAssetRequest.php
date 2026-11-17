<?php

namespace MasterCore\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BrandingAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware performs authentication and the host-defined manage ability check.
        return true;
    }

    public function rules(): array
    {
        $maxKilobytes = (int) config('master-core-settings.branding.max_upload_kilobytes', 2048);

        return [
            'asset_type' => ['required', 'string', 'in:logo,favicon'],
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKilobytes],
        ];
    }
}
