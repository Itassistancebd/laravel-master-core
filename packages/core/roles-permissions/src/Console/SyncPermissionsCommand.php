<?php

namespace MasterCore\RolesPermissions\Console;

use Illuminate\Console\Command;
use MasterCore\RolesPermissions\Actions\PermissionCatalogManager;
use MasterCore\RolesPermissions\Models\Permission;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\PermissionRegistry;

final class SyncPermissionsCommand extends Command
{
    protected $signature = 'master-core:permissions:sync {--apply : Materialize registered permission definitions into the Core catalog}';

    protected $description = 'Review or materialize code-registered Core and host permissions; never grants them to roles.';

    public function handle(PermissionRegistry $registry, PermissionCatalogManager $catalog): int
    {
        $missing = collect($registry->all())->keys()->reject(fn (string $name): bool => Permission::query()
            ->where('guard_name', app(HostUserModelResolver::class)->guardName())
            ->where('name', $name)->exists());

        foreach ($missing as $name) {
            $this->line(($this->option('apply') ? 'Registering: ' : 'Would register: ').$name);
        }

        if (! $this->option('apply')) {
            $this->info('Dry run complete. Use --apply to materialize registered permissions. No role grants will be changed.');

            return self::SUCCESS;
        }

        foreach ($missing as $name) {
            $catalog->materialize($name);
        }

        $this->info('Registered permission catalog updated. Existing rows and all role grants were preserved.');

        return self::SUCCESS;
    }
}
