<?php

namespace MasterCore\Notifications\Listeners;

use Illuminate\Support\Facades\Log;
use MasterCore\Notifications\Contracts\NotificationInbox;
use MasterCore\Notifications\Support\RoleChangeRecipientResolver;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;
use Throwable;

final class StoreRoleChangeNotification
{
    private const ACTIONS = ['user-roles-synced', 'super-admin-provisioned'];

    public function __construct(
        private readonly NotificationInbox $inbox,
        private readonly RoleChangeRecipientResolver $recipients,
    ) {}

    public function handle(RolesPermissionsChanged $event): void
    {
        if (! in_array($event->action, self::ACTIONS, true)) {
            return;
        }

        try {
            $recipient = $this->recipients->resolve($event);
            if ($recipient === null) {
                return;
            }

            $this->inbox->store($event->eventId, 'account.roles.changed', $recipient);
        } catch (Throwable $exception) {
            // Notification delivery is best effort and must not undo a committed role change.
            Log::warning('Core role-change notification could not be stored.', [
                'event_id' => $event->eventId,
                'event_key' => 'account.roles.changed',
                'exception_class' => $exception::class,
            ]);
        }
    }
}
