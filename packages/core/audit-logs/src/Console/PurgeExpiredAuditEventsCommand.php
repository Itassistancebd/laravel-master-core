<?php

namespace MasterCore\AuditLogs\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PurgeExpiredAuditEventsCommand extends Command
{
    protected $signature = 'master-core:audit-logs:purge-expired';

    protected $description = 'Purge expired audit rows only when an approved retention policy enables it.';

    public function handle(): int
    {
        $enabled = (bool) config('master-core-audit-logs.retention.purge_enabled', false);
        $approved = (bool) config('master-core-audit-logs.retention.policy_approved', false);
        $days = filter_var(config('master-core-audit-logs.retention.period_days'), FILTER_VALIDATE_INT);
        if (! $enabled || ! $approved || $days === false || $days < 1) {
            $this->error('Purge is disabled. Approve a retention policy, configure a positive period, and enable purge explicitly.');

            return self::FAILURE;
        }

        $table = (string) config('master-core-audit-logs.table', 'master_core_audit_events');
        $cutoff = now()->utc()->subDays($days);
        $count = DB::table($table)->where('occurred_at', '<', $cutoff)->count();
        $deleted = DB::table($table)->where('occurred_at', '<', $cutoff)->delete();
        Log::notice('Master Core audit retention purge completed.', [
            'retention_days' => $days,
            'cutoff_utc' => $cutoff->toIso8601String(),
            'count_before' => $count,
            'deleted' => $deleted,
        ]);
        $this->info("Purged {$deleted} expired audit rows.");

        return self::SUCCESS;
    }
}
