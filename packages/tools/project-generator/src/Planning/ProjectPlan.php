<?php

namespace MasterCore\ProjectGenerator\Planning;

final readonly class ProjectPlan
{
    /**
     * @param  list<string>  $requestedFeatures
     * @param  list<string>  $backendFeatures
     * @param  list<string>  $frontendFeatures
     * @param  array<string, string>  $composerRequirements
     * @param  array<string, string>  $npmDependencies
     * @param  array<string, string>  $npmDevDependencies
     * @param  list<string>  $routeExports
     * @param  list<string>  $styleImports
     * @param  list<string>  $migrationFeatures
     * @param  list<string>  $baselinePackages
     * @param  array<string, string>  $frontendPackageNames
     */
    public function __construct(
        public string $profile,
        public string $coreVersion,
        public array $requestedFeatures,
        public array $backendFeatures,
        public array $frontendFeatures,
        public string $authShell,
        public array $composerRequirements,
        public array $npmDependencies,
        public array $npmDevDependencies,
        public array $routeExports,
        public array $styleImports,
        public array $migrationFeatures,
        public array $baselinePackages,
        public array $frontendPackageNames,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'profile' => $this->profile,
            'core_version' => $this->coreVersion,
            'baseline_packages' => $this->baselinePackages,
            'requested_backend_features' => $this->requestedFeatures,
            'backend_features' => $this->backendFeatures,
            'frontend_features' => $this->frontendFeatures,
            'frontend_package_names' => $this->frontendPackageNames,
            'auth_shell' => $this->authShell,
            'composer_require' => $this->composerRequirements,
            'npm_dependencies' => $this->npmDependencies,
            'npm_dev_dependencies' => $this->npmDevDependencies,
            'route_exports' => $this->routeExports,
            'style_imports' => $this->styleImports,
            'migration_owners' => $this->migrationFeatures,
            'automatic_migrations' => false,
            'automatic_permission_grants' => false,
            'automatic_super_admin_provisioning' => false,
        ];
    }
}
