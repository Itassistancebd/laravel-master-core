<?php

namespace MasterCore\CrudEngine\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use MasterCore\CrudEngine\Contracts\CrudResource;

/** Keeps package APIs aligned with the Core success/data/meta response envelope. */
final class CrudResponse
{
    public static function collection(LengthAwarePaginator $page, CrudResource $resource): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => collect($page->items())->map(fn ($record): array => $resource->serialize($record))->values(),
            'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'last_page' => $page->lastPage(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ]);
    }

    public static function item(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    public static function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message], $status);
    }
}
