<?php

namespace MasterCore\AuditLogs\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MasterCore\AuditLogs\Contracts\AuditLogger;
use MasterCore\AuditLogs\Support\AuditEventCatalog;

final class DatabaseAuditLogger implements AuditLogger
{
    public function __construct(private readonly AuditEventCatalog $catalog) {}

    public function record(array $event): void
    {
        $allowed = ['event_name', 'outcome', 'actor', 'subject', 'metadata', 'before_values', 'after_values', 'occurred_at'];
        if (array_diff(array_keys($event), $allowed)) {
            throw ValidationException::withMessages(['event' => ['The audit event contains unsupported fields.']]);
        }

        $name = $event['event_name'] ?? null;
        $definition = is_string($name) ? $this->catalog->definition($name) : null;
        if ($definition === null) {
            throw ValidationException::withMessages(['event_name' => ['The audit event is not registered.']]);
        }

        $outcome = $event['outcome'] ?? 'succeeded';
        if (! in_array($outcome, ['succeeded', 'failed', 'denied'], true)) {
            throw ValidationException::withMessages(['outcome' => ['The audit outcome is invalid.']]);
        }

        $request = app()->bound('request') ? request() : null;
        $httpRequest = $request?->route() ? $request : null;
        $actor = $event['actor'] ?? ($httpRequest?->user());
        $subject = $event['subject'] ?? null;
        $metadata = $this->safeMap((array) ($event['metadata'] ?? []), $definition['metadata']);
        $before = $this->safeMap((array) ($event['before_values'] ?? []), $definition['snapshots']);
        $after = $this->safeMap((array) ($event['after_values'] ?? []), $definition['snapshots']);
        $payload = compact('metadata', 'before', 'after');

        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > (int) config('master-core-audit-logs.max_payload_bytes', 16384)) {
            throw ValidationException::withMessages(['event' => ['The audit event exceeds the configured payload limit.']]);
        }

        $source = $httpRequest ? 'http' : (app()->runningInConsole() ? 'console' : 'system');
        $correlation = $httpRequest?->header((string) config('master-core-audit-logs.correlation_header', 'X-Correlation-ID'));
        if (! is_string($correlation) || ! preg_match('/^[A-Za-z0-9._-]{1,64}$/', $correlation)) {
            $correlation = (string) Str::ulid();
        }

        $values = [
            'id' => (string) Str::ulid(),
            'event_name' => $name,
            'category' => $definition['category'],
            'outcome' => $outcome,
            'source' => $source,
            'actor_type' => $this->modelType($actor),
            'actor_id' => $this->identifier($actor),
            'subject_type' => $this->modelType($subject),
            'subject_id' => $this->identifier($subject),
            'correlation_id' => $correlation,
            'request_method' => $httpRequest ? Str::limit((string) $httpRequest->method(), 10, '') : null,
            'route_name' => $httpRequest && is_string($httpRequest->route()?->getName()) ? Str::limit($httpRequest->route()->getName(), 191, '') : null,
            'ip_address' => config('master-core-audit-logs.capture_ip_address', true) ? $httpRequest?->ip() : null,
            'user_agent' => config('master-core-audit-logs.capture_user_agent', true)
                ? $this->userAgent($httpRequest?->userAgent()) : null,
            'before_values' => $before ? json_encode($before, JSON_THROW_ON_ERROR) : null,
            'after_values' => $after ? json_encode($after, JSON_THROW_ON_ERROR) : null,
            'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            'occurred_at' => isset($event['occurred_at']) ? Carbon::parse($event['occurred_at'])->utc() : now()->utc(),
            'created_at' => now()->utc(),
        ];

        DB::table((string) config('master-core-audit-logs.table', 'master_core_audit_events'))->insert($values);
    }

    /** @param list<string> $keys @return array<string, mixed> */
    private function safeMap(array $values, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $values) || $this->sensitiveKey($key)) {
                continue;
            }
            $value = $values[$key];
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $result[$key] = $value;
            } elseif (is_string($value)) {
                $result[$key] = Str::limit($value, 256, '');
            } elseif (is_array($value) && count($value) <= 100) {
                $result[$key] = array_values(array_filter($value, fn ($item): bool => is_string($item) || is_int($item)));
            }
        }

        return $result;
    }

    private function sensitiveKey(string $key): bool
    {
        return (bool) preg_match('/password|token|secret|credential|cookie|session|authorization|csrf|private[_-]?key/i', $key);
    }

    private function userAgent(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $limit = min(512, max(1, (int) config('master-core-audit-logs.max_user_agent_bytes', 512)));

        return mb_strcut(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '', 0, $limit, 'UTF-8');
    }

    private function identifier(mixed $model): ?string
    {
        if ($model instanceof Authenticatable) {
            return Str::limit((string) $model->getAuthIdentifier(), 191, '');
        }

        return $model instanceof Model ? Str::limit((string) $model->getKey(), 191, '') : null;
    }

    private function modelType(mixed $model): ?string
    {
        if ($model instanceof Model) {
            return Str::limit($model->getMorphClass(), 191, '');
        }

        return $model instanceof Authenticatable ? Str::limit($model::class, 191, '') : null;
    }
}
