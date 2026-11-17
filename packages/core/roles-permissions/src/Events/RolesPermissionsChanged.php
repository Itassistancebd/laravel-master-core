<?php

namespace MasterCore\RolesPermissions\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final readonly class RolesPermissionsChanged
{
    public string $eventId;

    public function __construct(
        public string $action,
        public string $subjectType,
        public string $subjectId,
        public string $actorId,
        ?string $eventId = null,
    ) {
        // A stable ID lets after-commit listeners safely ignore a redelivery of this event instance.
        $this->eventId = $eventId ?? (string) Str::ulid();
    }

    public static function record(string $action, ?Authenticatable $actor, Model $subject): self
    {
        return new self(
            $action,
            $subject::class,
            (string) $subject->getKey(),
            $actor === null ? 'console' : (string) $actor->getAuthIdentifier(),
        );
    }
}
