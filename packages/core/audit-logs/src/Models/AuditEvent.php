<?php

namespace MasterCore\AuditLogs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use MasterCore\AuditLogs\Casts\UtcTimestamp;

/** Read-only Eloquent representation of an append-only audit row. */
final class AuditEvent extends Model
{
    protected $table = 'master_core_audit_events';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return (string) config('master-core-audit-logs.table', 'master_core_audit_events');
    }

    protected function casts(): array
    {
        return [
            'before_values' => 'array',
            'after_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => UtcTimestamp::class,
            'created_at' => UtcTimestamp::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $event): void {
            $event->id ??= (string) Str::ulid();
        });

        // Reject model-level changes even when a caller accidentally obtains a writable instance.
        self::updating(static fn () => throw new \LogicException('Audit records are append-only.'));
        self::deleting(static fn () => throw new \LogicException('Audit records are append-only.'));
    }
}
