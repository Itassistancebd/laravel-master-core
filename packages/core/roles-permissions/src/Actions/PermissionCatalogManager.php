<?php

namespace MasterCore\RolesPermissions\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use MasterCore\RolesPermissions\Models\Permission;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\PermissionRegistry;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PermissionCatalogManager
{
    public function materialize(string $name, ?Authenticatable $actor = null): Permission
    {
        $registry = app(PermissionRegistry::class);
        $name = $registry->canonicalName($name);
        $definition = $registry->definition($name);
        if ($definition === null) {
            throw new ConflictHttpException('This ability is not registered by Core or a host module.');
        }

        $guard = app(HostUserModelResolver::class)->guardName();
        $permission = DB::transaction(function () use ($name, $guard, $definition, $actor): Permission {
            // firstOrCreate handles a concurrent insert by re-reading the row after a unique-key conflict.
            $permission = Permission::unguarded(fn (): Permission => Permission::query()->firstOrCreate(
                ['name' => $name, 'guard_name' => $guard],
                [
                    'group_name' => $definition['group'],
                    'description' => $definition['description'],
                    'is_system' => $definition['system'],
                ],
            ));
            if ($permission->wasRecentlyCreated) {
                $this->audit('permission.materialized', $actor, $permission, ['permission_name' => $name]);

            }

            return $permission;
        });
        $this->invalidateCatalog();

        return $permission;
    }

    public function updateDisplayMetadata(Permission $permission, string $group, ?string $description, ?Authenticatable $actor = null): Permission
    {
        if (app(PermissionRegistry::class)->definition($permission->name) === null) {
            throw new ConflictHttpException('A permission must remain registered in code while it is managed.');
        }

        DB::transaction(function () use ($permission, $group, $description, $actor): void {
            $permission->forceFill(['group_name' => $group, 'description' => $description])->save();
            $this->audit('permission.metadata_updated', $actor, $permission, [
                'permission_name' => $permission->name,
                'changed_fields' => ['group_name', 'description'],
            ]);
        });
        $this->invalidateCatalog();

        return $permission->refresh();
    }

    public function deleteAfterDeregistration(Permission $permission, ?Authenticatable $actor = null): void
    {
        if ($permission->is_system || app(PermissionRegistry::class)->definition($permission->name) !== null) {
            throw new ConflictHttpException('Registered system permissions cannot be deleted. Deregister a host permission in code first.');
        }

        if (DB::table('master_core_role_has_permissions')->where('permission_id', $permission->getKey())->exists()) {
            throw new ConflictHttpException('Remove this permission from all roles before deleting it.');
        }

        DB::transaction(function () use ($permission, $actor): void {
            $permission->delete();
            $this->audit('permission.deleted', $actor, $permission, ['permission_name' => $permission->name]);
        });
        $this->invalidateCatalog();
    }

    public function invalidateCatalog(): void
    {
        Cache::forget($this->cacheKey());
    }

    /** @return list<array<string, mixed>> */
    public function cachedCatalog(): array
    {
        return Cache::remember($this->cacheKey(), (int) config('master-core-roles-permissions.permission_cache_ttl', 300), function (): array {
            $guard = app(HostUserModelResolver::class)->guardName();

            return Permission::query()->where('guard_name', $guard)
                ->orderBy('group_name')->orderBy('name')->get()
                ->map(static fn (Permission $permission): array => [
                    'id' => $permission->getKey(),
                    'name' => $permission->name,
                    'group_name' => $permission->group_name,
                    'description' => $permission->description,
                    'is_system' => $permission->is_system,
                ])->all();
        });
    }

    private function cacheKey(): string
    {
        return 'master-core:roles-permissions:catalog:v1:'.app(HostUserModelResolver::class)->guardName();
    }

    private function audit(string $name, ?Authenticatable $actor, Permission $subject, array $metadata): void
    {
        event('master-core.audit.record.v1', [[
            'event_name' => $name, 'actor' => $actor, 'subject' => $subject, 'metadata' => $metadata,
        ]]);
    }
}
