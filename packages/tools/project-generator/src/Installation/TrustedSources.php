<?php

namespace MasterCore\ProjectGenerator\Installation;

use MasterCore\ProjectGenerator\Planning\ProjectPlan;
use MasterCore\ProjectGenerator\Profile\CoreProfile;

final readonly class TrustedSources
{
    /** @param list<string> $composerRepositories @param list<string> $composerVendors @param list<string> $npmScopes */
    public function __construct(
        public array $composerRepositories,
        public array $composerVendors,
        public string $npmRegistry,
        public array $npmScopes,
    ) {}

    public static function fromUserConfig(?string $path = null): self
    {
        $path ??= self::defaultPath();
        if (! is_file($path) || ! is_readable($path)) {
            throw new InstallationException('No trusted registry configuration exists. External installs are disabled; see the CLI guide before configuring a verified source.');
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InstallationException('Trusted registry configuration is invalid JSON.', previous: $exception);
        }

        if (($data['schema_version'] ?? null) !== 1
            || ! is_array($data['composer_repositories'] ?? null)
            || ! is_array($data['composer_vendors'] ?? null)
            || ! is_string($data['npm_registry'] ?? null)
            || ! is_array($data['npm_scopes'] ?? null)) {
            throw new InstallationException('Trusted registry configuration does not match schema 1.');
        }

        return new self($data['composer_repositories'], $data['composer_vendors'], $data['npm_registry'], $data['npm_scopes']);
    }

    public function assertTrusted(CoreProfile $profile, ProjectPlan $plan): void
    {
        if ($this->composerRepositories === [] || $this->composerVendors === [] || $this->npmScopes === [] || ! $this->isHttpsUrl($this->npmRegistry)) {
            throw new InstallationException('Trusted registry configuration must list HTTPS Composer repositories, approved vendors, an HTTPS npm registry, and approved scopes.');
        }

        foreach ($this->composerRepositories as $repository) {
            if (! is_string($repository) || ! $this->isHttpsUrl($repository)) {
                throw new InstallationException('Only explicit HTTPS Composer repositories are accepted.');
            }
        }

        $requiredComposerPackages = array_unique(array_merge(
            ['laravel/laravel', 'laravel/framework', $profile->packageName()],
            $profile->baseline(),
            array_keys($plan->composerRequirements),
        ));
        foreach ($plan->backendFeatures as $feature) {
            $requiredComposerPackages = array_merge($requiredComposerPackages, $profile->features()[$feature]['composer_dependencies']);
        }
        foreach ($requiredComposerPackages as $package) {
            [$vendor] = explode('/', $package, 2);
            if ($vendor === 'example' || ! in_array($vendor, $this->composerVendors, true)) {
                throw new InstallationException("Composer vendor '{$vendor}' is not explicitly approved. No packages were installed.");
            }
        }

        foreach (array_keys($plan->npmDependencies) as $package) {
            if (! str_starts_with($package, '@')) {
                continue;
            }
            [$scope] = explode('/', $package, 2);
            if ($scope === '@master-core' || ! in_array($scope, $this->npmScopes, true)) {
                throw new InstallationException("npm scope '{$scope}' is not explicitly approved. No packages were installed.");
            }
        }

        foreach ($this->npmScopes as $scope) {
            if (! is_string($scope) || ! preg_match('/^@[a-z0-9_.-]+$/', $scope) || $scope === '@master-core') {
                throw new InstallationException('Only explicitly verified, non-placeholder npm scopes may be approved.');
            }
        }
    }

    /** @return list<array{type: string, url: string}> */
    public function composerRepositoryDefinitions(): array
    {
        return array_map(static fn (string $url): array => ['type' => 'composer', 'url' => $url], $this->composerRepositories);
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private static function defaultPath(): string
    {
        $appData = getenv('APPDATA');
        if (is_string($appData) && $appData !== '') {
            return $appData.'/MasterCore/ProjectGenerator/trusted-sources.json';
        }

        $xdgConfig = getenv('XDG_CONFIG_HOME');
        if (is_string($xdgConfig) && $xdgConfig !== '') {
            return $xdgConfig.'/master-core/project-generator/trusted-sources.json';
        }

        $userProfile = getenv('USERPROFILE');
        if (is_string($userProfile) && $userProfile !== '') {
            return $userProfile.'/.config/master-core/project-generator/trusted-sources.json';
        }

        throw new InstallationException('Could not determine a user config directory for trusted registries.');
    }
}
