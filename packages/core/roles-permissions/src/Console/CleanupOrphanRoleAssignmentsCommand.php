<?php

namespace MasterCore\RolesPermissions\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;

final class CleanupOrphanRoleAssignmentsCommand extends Command
{
    protected $signature = 'master-core:roles:cleanup-orphans {--apply : Remove assignment rows whose host user no longer exists}';

    protected $description = 'Report orphan role assignments for the configured host user model; removal requires an interactive confirmation.';

    public function handle(HostUserModelResolver $models): int
    {
        $userModel = $models->modelClass();
        $morphType = (new $userModel)->getMorphClass();
        $query = DB::table('master_core_model_has_roles')->where('model_type', $morphType);
        $orphans = [];

        $query->orderBy('model_id')->orderBy('role_id')->chunk(500, function ($rows) use ($models, &$orphans): void {
            $ids = $rows->pluck('model_id')->unique()->values()->all();
            $users = $models->query();
            if (method_exists($users->getModel(), 'trashed')) {
                $users->withTrashed();
            }
            $existing = $users->whereIn($users->getModel()->getKeyName(), $ids)->pluck($users->getModel()->getKeyName())
                ->map(static fn ($id): string => (string) $id)->flip();

            foreach ($rows as $row) {
                if (! $existing->has((string) $row->model_id)) {
                    $orphans[] = ['role_id' => $row->role_id, 'model_id' => (string) $row->model_id];
                }
            }
        });

        $this->info(count($orphans).' orphan assignment row(s) found for '.$morphType.'.');
        if ($orphans === [] || ! $this->option('apply')) {
            $this->line('Dry run complete. Soft-deleted users are retained and are not reported as orphans.');

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive() || $this->ask('Type "DELETE '.count($orphans).' ORPHAN ROLE LINKS" to continue') !== 'DELETE '.count($orphans).' ORPHAN ROLE LINKS') {
            $this->error('Interactive confirmation is required. No assignment rows were removed.');

            return self::FAILURE;
        }

        foreach ($orphans as $orphan) {
            DB::table('master_core_model_has_roles')
                ->where('model_type', $morphType)
                ->where('model_id', $orphan['model_id'])
                ->where('role_id', $orphan['role_id'])
                ->delete();
        }

        $this->info('Orphan assignment rows removed.');

        return self::SUCCESS;
    }
}
