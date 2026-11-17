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

        if (($data['schema_version'] ?? null) === 2) {
            if (! is_array($data['composer_repositories'] ?? null)
                || ! is_string($data['composer_vendor'] ?? null)
                || ! is_string($data['npm_registry'] ?? null)
                || ! is_string($data['npm_scope'] ?? null)) {
                throw new InstallationException('Trusted registry configuration does not match schema 2.');
            }

            return new self($data['composer_repositories'], ['laravel', $data['composer_vendor']], $data['npm_registry'], [$data['npm_scope']]);
        }

        // Schema 1 remains readable for existing installations. New setups should declare one explicit vendor and scope in schema 2.
        if (($data['schema_version'] ?? null) !== 1
            || ! is_array($data['composer_repositories'] ?? null)
            || ! is_array($data['composer_vendors'] ?? null)
            || ! is_string($data['npm_registry'] ?? null)
            || ! is_array($data['npm_scopes'] ?? null)) {
            throw new InstallationException('Trusted registry configuration does not match schema 1 or 2.');
        }

        return new self($data['composer_repositories'], $data['composer_vendors'], $data['npm_registry'], $data['npm_scopes']);
    }

    public function composerVendor(): string
    {
        foreach ($this->composerVendors as $vendor) {
            if (is_string($vendor) && ! in_array($vendor, ['laravel', 'example'], true)) {
                return $vendor;
            }
        }

        throw new InstallationException('Configure one verified Master Core Composer vendor before installing packages.');
    }

    public function npmScope(): string
    {
        foreach ($this->npmScopes as $scope) {
            if (is_string($scope)) {
                return $scope;
            }
        }

        throw new InstallationException('Configure one verified Master Core npm scope before installing frontend packages.');
    }

    public function profileWithDistributionIdentity(CoreProfile $profile): CoreProfile
    {
        return $profile->withDistributionIdentity($this->composerVendor(), $this->npmScope());
    }

    public function assertTrusted(CoreProfile $profile, ProjectPlan $plan): void
    {
        if ($this->composerRepositories === [] || ! $this->isHttpsUrl($this->npmRegistry)) {
            throw new InstallationException('Trusted registry configuration must list HTTPS Composer repositories, one verified vendor, an HTTPS npm registry, and one verified scope.');
        }

        $vendor = $this->composerVendor();
        $scope = $this->npmScope();
        if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $vendor) || $vendor === 'example'
            || ! preg_match('/^@[a-z0-9_.-]+$/', $scope) || $scope === '@master-core') {
            throw new InstallationException('Trusted package identity is invalid or still uses a placeholder.');
        }
        if (! str_starts_with($profile->packageName(), $vendor.'/')) {
            throw new InstallationException('The selected Composer vendor does not match the distribution profile.');
        }
        foreach ($profile->data['npm_packages'] as $package) {
            if (! str_starts_with((string) ($package['name'] ?? ''), $scope.'/')) {
                throw new InstallationException('The selected npm scope does not match the distribution profile.');
            }
        }
        if (! str_starts_with((string) ($profile->data['shared_npm']['name'] ?? ''), $scope.'/')) {
            throw new InstallationException('The selected npm scope does not match the shared UI package identity.');
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
            if (! in_array($vendor, ['laravel', $this->composerVendor()], true)) {
                throw new InstallationException("Composer vendor '{$vendor}' is not explicitly approved. No packages were installed.");
            }
        }

        foreach (array_keys($plan->npmDependencies) as $package) {
            if (! str_starts_with($package, '@')) {
                continue;
            }
            [$scope] = explode('/', $package, 2);
            if ($scope !== $this->npmScope()) {
                throw new InstallationException("npm scope '{$scope}' is not explicitly approved. No packages were installed.");
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
