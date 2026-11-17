<?php

namespace MasterCore\AuditLogs\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use MasterCore\AuditLogs\Http\Requests\IndexAuditEventsRequest;
use MasterCore\AuditLogs\Http\Resources\AuditEventResource;
use MasterCore\AuditLogs\Models\AuditEvent;

final class AuditEventController
{
    public function index(IndexAuditEventsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = AuditEvent::query();
        foreach (['event_name', 'category', 'outcome', 'correlation_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['actor_id'])) {
            $query->where('actor_id', $filters['actor_id']);
        }
        if (isset($filters['subject_id'])) {
            $query->where('subject_id', $filters['subject_id']);
        }
        // Every list query is bounded even when the client leaves date inputs empty.
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->utc() : now()->utc();
        $from = isset($filters['from'])
            ? Carbon::parse($filters['from'])->utc()
            : $to->copy()->subDays((int) config('master-core-audit-logs.max_date_range_days', 90));
        $query->whereBetween('occurred_at', [$from, $to]);
        if (isset($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(function ($nested) use ($term): void {
                $nested->where('event_name', 'like', $term)
                    ->orWhere('actor_id', 'like', $term)
                    ->orWhere('subject_id', 'like', $term)
                    ->orWhere('correlation_id', 'like', $term);
            });
        }

        $page = $query->orderBy('occurred_at', $filters['direction'] ?? 'desc')
            ->orderBy('id', $filters['direction'] ?? 'desc')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => AuditEventResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function show(string $event): JsonResponse
    {
        $record = AuditEvent::query()->findOrFail($event);

        return response()->json(['success' => true, 'data' => (new AuditEventResource($record))->resolve()]);
    }
}
