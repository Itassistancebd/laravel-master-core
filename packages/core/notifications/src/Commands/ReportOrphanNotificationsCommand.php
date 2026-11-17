<?php

namespace MasterCore\Notifications\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use MasterCore\Notifications\Support\HostRecipientModelResolver;

final class ReportOrphanNotificationsCommand extends Command
{
    protected $signature = 'master-core:notifications:report-orphans';

    protected $description = 'Report notification rows whose polymorphic recipient is missing or cannot be resolved; does not modify data.';

    public function handle(): int
    {
        $orphanRows = 0;
        $unresolvedRows = 0;
        $types = DB::table('master_core_notifications')->distinct()->pluck('notifiable_type');
        $hostModel = app(HostRecipientModelResolver::class)->modelClass();
        $hostMorph = $hostModel === null ? null : (new $hostModel)->getMorphClass();

        foreach ($types as $type) {
            // Never autoload an arbitrary class name read from a database row.
            if ($hostModel === null || ! in_array((string) $type, [$hostModel, $hostMorph], true)
                || ! is_subclass_of($hostModel, Model::class)) {
                $count = DB::table('master_core_notifications')->where('notifiable_type', $type)->count();
                $orphanRows += $count;
                $unresolvedRows += $count;
                $this->warn("Unresolved recipient type '{$type}': {$count} row(s); no data changed.");

                continue;
            }

            DB::table('master_core_notifications')->where('notifiable_type', $type)
                ->select('notifiable_id')->distinct()->orderBy('notifiable_id')->chunk(500, function ($rows) use ($hostModel, $type, &$orphanRows): void {
                    $ids = $rows->pluck('notifiable_id')->all();
                    $existing = $hostModel::query()->whereKey($ids)->pluck((new $hostModel)->getKeyName())->map(static fn ($id): string => (string) $id)->all();
                    $missing = array_values(array_diff(array_map('strval', $ids), $existing));
                    if ($missing !== []) {
                        $count = DB::table('master_core_notifications')->where('notifiable_type', $type)->whereIn('notifiable_id', $missing)->count();
                        $orphanRows += $count;
                        $this->warn("Recipient type '{$type}' has {$count} orphan row(s); no data changed.");
                    }
                });
        }

        $this->info("Orphan rows: {$orphanRows}. Unresolved-type rows: {$unresolvedRows}. This command is report-only.");

        return self::SUCCESS;
    }
}
