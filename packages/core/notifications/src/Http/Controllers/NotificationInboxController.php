<?php

namespace MasterCore\Notifications\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use MasterCore\Notifications\Http\Requests\IndexNotificationsRequest;
use MasterCore\Notifications\Http\Requests\NotificationActionRequest;
use MasterCore\Notifications\Http\Resources\CoreNotificationResource;
use MasterCore\Notifications\Models\CoreNotification;
use MasterCore\Notifications\Support\HostRecipientModelResolver;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class NotificationInboxController
{
    public function index(IndexNotificationsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $query = $this->ownedBy($request->user());
        if (($filters['status'] ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        } elseif (($filters['status'] ?? 'all') === 'read') {
            $query->whereNotNull('read_at');
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => CoreNotificationResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
                'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->ownedBy($request->user())->whereNull('read_at')->count();

        return response()->json(['success' => true, 'data' => ['unread_count' => $count]])
            ->header('Cache-Control', 'no-store, private');
    }

    public function markRead(NotificationActionRequest $request, string $notification): JsonResponse
    {
        $record = $this->ownedBy($request->user())->whereKey($notification)->firstOrFail();
        if ($record->read_at === null) {
            DB::table('master_core_notifications')->where('id', $record->getKey())
                ->whereNull('read_at')->update(['read_at' => now()->utc()]);
            $record->refresh();
        }

        return response()->json(['success' => true, 'data' => (new CoreNotificationResource($record))->resolve()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function markUnread(NotificationActionRequest $request, string $notification): JsonResponse
    {
        $record = $this->ownedBy($request->user())->whereKey($notification)->firstOrFail();
        if ($record->read_at !== null) {
            DB::table('master_core_notifications')->where('id', $record->getKey())
                ->whereNotNull('read_at')->update(['read_at' => null]);
            $record->refresh();
        }

        return response()->json(['success' => true, 'data' => (new CoreNotificationResource($record))->resolve()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function markAllRead(NotificationActionRequest $request): JsonResponse
    {
        $owner = $request->user();
        $ownerType = $owner instanceof Model ? $owner->getMorphClass() : '';
        $ownerId = $owner instanceof Model ? (string) $owner->getKey() : '';
        $limit = min(1000, max(1, (int) config('master-core-notifications.mark_all_batch_size', 500)));
        $ids = $this->ownedBy($owner)->whereNull('read_at')->orderBy('id')->limit($limit)->pluck('id');
        $updated = $ids->isEmpty() ? 0 : DB::table('master_core_notifications')
            ->where('notifiable_type', $ownerType)->where('notifiable_id', $ownerId)
            ->whereIn('id', $ids)->whereNull('read_at')->update(['read_at' => now()->utc()]);
        $remaining = $this->ownedBy($owner)->whereNull('read_at')->count();

        return response()->json(['success' => true, 'data' => ['updated' => $updated, 'remaining' => $remaining]])
            ->header('Cache-Control', 'no-store, private');
    }

    private function ownedBy(mixed $user)
    {
        if (! $user instanceof Model || ! $user->exists || ! app(HostRecipientModelResolver::class)->supports($user)
            || in_array($user->getAttribute('is_active'), [false, 0, '0'], true)
            || (method_exists($user, 'trashed') && $user->trashed())) {
            throw new AccessDeniedHttpException('An active authenticated account is required.');
        }

        // Ownership is part of every query to prevent IDOR and ID-existence disclosure.
        return CoreNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', (string) $user->getKey());
    }
}
