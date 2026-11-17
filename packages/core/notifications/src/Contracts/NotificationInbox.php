<?php

namespace MasterCore\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

interface NotificationInbox
{
    /** Persist one registered notice for a trusted, existing host recipient. */
    public function store(string $eventId, string $eventKey, Model $recipient): bool;
}
