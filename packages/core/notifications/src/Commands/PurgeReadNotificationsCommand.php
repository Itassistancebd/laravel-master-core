<?php

namespace MasterCore\Notifications\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PurgeReadNotificationsCommand extends Command
{
    protected $signature = 'master-core:notifications:purge-read {--limit=1000 : Maximum number of eligible rows to remove}';

    protected $description = 'Manually purge a bounded batch of old, read Core notifications under an approved retention policy.';

    public function handle(): int
    {
        $days = filter_var(config('master-core-notifications.retention_days'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $max = (int) config('master-core-notifications.purge_max_rows', 1000);
        if (! config('master-core-notifications.retention_approved') || ! config('master-core-notifications.purge_enabled')
            || $days === false || $days < 1 || $limit === false || $limit < 1 || $limit > $max) {
            $this->error('Read-notification purge requires approved, enabled retention and a valid bounded batch limit.');

            return self::FAILURE;
        }

        $cutoff = now()->utc()->subDays($days);
        $ids = DB::table('master_core_notifications')->whereNotNull('read_at')
            ->where('created_at', '<', $cutoff)->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id');
        $deleted = $ids->isEmpty() ? 0 : DB::table('master_core_notifications')->whereIn('id', $ids)->whereNotNull('read_at')->delete();
        $this->info("Purged {$deleted} old read notification(s). Unread and orphan rows were not targeted.");

        return self::SUCCESS;
    }
}
