<?php

namespace MasterCore\RolesPermissions\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class SuperAdminProvisioner
{
    public function provision(string|int $userId): Model
    {
        $models = app(HostUserModelResolver::class);

        return DB::transaction(function () use ($models, $userId): Model {
            $query = $models->query();
            if (method_exists($query->getModel(), 'trashed')) {
                $query->withTrashed();
            }

            // Re-read and lock the host account after interactive confirmation. The same row-first
            // lock order is used by HTTP role assignment and the User Management safety policy.
            $target = $query->lockForUpdate()->findOrFail($userId);
            if ((method_exists($target, 'trashed') && $target->trashed())
                || ! in_array($target->getAttribute('is_active'), [true, 1, '1'], true)) {
                throw new AccessDeniedHttpException('Super Admin can only be provisioned to an active, non-trashed user.');
            }

            $guard = $models->guardName();
            $roleQuery = Role::query()->where('guard_name', $guard)->where('role_key', 'core-super-admin');
            $role = $roleQuery->lockForUpdate()->first();
            if ($role === null) {
                // The unique guard/key index makes concurrent first provisioning safe across supported SQL engines.
                DB::table('master_core_roles')->insertOrIgnore([
                    'name' => 'Core Super Admin',
                    'role_key' => 'core-super-admin',
                    'guard_name' => $guard,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $role = $roleQuery->lockForUpdate()->firstOrFail();
            }

            $modelType = $target->getMorphClass();
            $assignment = DB::table('master_core_model_has_roles')
                ->where('role_id', $role->getKey())
                ->where('model_type', $modelType)
                ->where('model_id', (string) $target->getKey());

            if (! $assignment->exists()) {
                $assignment->insert([
                    'role_id' => $role->getKey(),
                    'model_type' => $modelType,
                    'model_id' => (string) $target->getKey(),
                ]);

                // Keep the mandatory audit row inside the provisioning transaction.
                event('master-core.audit.record.v1', [[
                    'event_name' => 'super_admin.provisioned',
                    'subject' => $target,
                    'metadata' => ['role_key' => 'core-super-admin'],
                ]]);

                $event = RolesPermissionsChanged::record('super-admin-provisioned', null, $target);
                DB::afterCommit(static function () use ($event, $target): void {
                    event($event);
                    Log::notice('Master Core Super Admin provisioned from the console.', [
                        'subject_type' => $target::class,
                        'subject_id' => (string) $target->getKey(),
                        'role_key' => 'core-super-admin',
                    ]);
                });
            }

            return $target;
        });
    }
}
