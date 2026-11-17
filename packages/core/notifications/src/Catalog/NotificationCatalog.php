<?php

namespace MasterCore\Notifications\Catalog;

/** Closed display catalog: producers cannot supply arbitrary message content. */
final class NotificationCatalog
{
    /** @return array{title: string, message: string}|null */
    public function displayData(string $eventKey): ?array
    {
        return match ($eventKey) {
            'account.roles.changed' => [
                'title' => 'Account access updated',
                'message' => 'Your account access was updated.',
            ],
            default => null,
        };
    }
}
