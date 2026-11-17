<?php

namespace MasterCore\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use MasterCore\Settings\Http\Requests\BrandingAssetRequest;
use MasterCore\Settings\Http\Requests\UpdateSettingsRequest;
use MasterCore\Settings\Services\SettingsManager;
use Throwable;

final class SettingsController
{
    public function index(SettingsManager $settings): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $settings->groups()]);
    }

    public function show(string $group, SettingsManager $settings): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $settings->group($group)]);
    }

    public function update(UpdateSettingsRequest $request, string $group, SettingsManager $settings): JsonResponse
    {
        $updated = $settings->updateGroup($group, (array) $request->validated('settings'));

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function reset(string $group, SettingsManager $settings): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $settings->resetGroup($group)]);
    }

    public function publicSettings(SettingsManager $settings): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $settings->publicSettings()]);
    }

    public function uploadBrandAsset(BrandingAssetRequest $request, SettingsManager $settings): JsonResponse
    {
        $disk = (string) config('master-core-settings.branding.disk', 'public');
        $path = $request->file('file')->store('master-core/branding', $disk);

        if ($path === false) {
            return response()->json([
                'success' => false,
                'message' => 'The branding asset could not be stored.',
            ], 500);
        }

        try {
            $group = $settings->storeBrandAsset($request->string('asset_type')->toString(), $path);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        return response()->json(['success' => true, 'data' => $group]);
    }
}
