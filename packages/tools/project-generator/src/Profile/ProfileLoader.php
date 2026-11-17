<?php

namespace MasterCore\ProjectGenerator\Profile;

use JsonException;
use RuntimeException;

final class ProfileLoader
{
    public function __construct(private readonly ProfileValidator $validator = new ProfileValidator) {}

    public function load(string $path, ?string $sourceRoot = null): CoreProfile
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new ProfileException("Profile file is missing or unreadable: {$path}");
        }

        try {
            $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProfileException('Profile JSON is invalid: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($raw)) {
            throw new ProfileException('Profile must be a JSON object.');
        }

        if (isset($raw['extra']['master-core'], $raw['name'])) {
            $raw = $this->mergeComposerManifest($raw);
        }

        $profile = new CoreProfile($raw);
        $this->validator->validate($profile);

        if ($sourceRoot !== null) {
            $this->validator->validateAgainstSource($profile, $sourceRoot);
        }

        return $profile;
    }

    /**
     * Accept the metapackage Composer manifest directly while using the bundled
     * npm index for its independently published frontend package details.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function mergeComposerManifest(array $manifest): array
    {
        $bundled = dirname(__DIR__, 2).'/resources/profiles/master-core-v1.json';
        try {
            $profile = json_decode((string) file_get_contents($bundled), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Bundled Master Core profile index is invalid.', previous: $exception);
        }

        $profile['metapackage'] = [
            'name' => $manifest['name'] ?? null,
            'version_constraint' => $manifest['require'][$manifest['name'] ?? ''] ?? '^0.1.0',
            'require' => $manifest['require'] ?? [],
            'extra' => $manifest['extra']['master-core'] ?? [],
        ];

        return $profile;
    }
}
