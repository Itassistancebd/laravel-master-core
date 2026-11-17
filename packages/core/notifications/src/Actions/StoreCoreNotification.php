<?php

namespace MasterCore\Notifications\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MasterCore\Notifications\Catalog\NotificationCatalog;
use MasterCore\Notifications\Contracts\NotificationInbox;
use MasterCore\Notifications\Support\HostRecipientModelResolver;

final class StoreCoreNotification implements NotificationInbox
{
    public function __construct(
        private readonly NotificationCatalog $catalog,
        private readonly HostRecipientModelResolver $recipients,
    ) {}

    public function store(string $eventId, string $eventKey, Model $recipient): bool
    {
        $displayData = $this->catalog->displayData($eventKey);
        if ($displayData === null || ! Str::isUlid($eventId) || ! $this->recipients->supports($recipient)) {
            throw new InvalidArgumentException('Only registered notification events with a valid event ID may be stored.');
        }

        if (! $recipient->exists || ! $this->isActive($recipient) || $this->isTrashed($recipient)) {
            return false;
        }

        $encodedData = json_encode($displayData, JSON_THROW_ON_ERROR);
        if (strlen($encodedData) > (int) config('master-core-notifications.max_payload_bytes', 4096)) {
            throw new InvalidArgumentException('The registered notification content exceeds the configured payload size limit.');
        }

        // The event ULID is also the notification ID, so replaying the same event is idempotent.
        return DB::table('master_core_notifications')->insertOrIgnore([
            'id' => $eventId,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => (string) $recipient->getKey(),
            'event_key' => $eventKey,
            'data' => $encodedData,
            'read_at' => null,
            'created_at' => now()->utc(),
        ]) > 0;
    }

    private function isActive(Model $recipient): bool
    {
        // Hosts without an active flag retain their own account policy; explicit inactive values are rejected.
        return ! in_array($recipient->getAttribute('is_active'), [false, 0, '0'], true);
    }

    private function isTrashed(Model $recipient): bool
    {
        return method_exists($recipient, 'trashed') && $recipient->trashed();
    }
}
