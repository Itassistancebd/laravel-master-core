<?php

namespace MasterCore\UserManagement\Events;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class UserManagementAction
{
    public function __construct(
        public string $operation,
        public string|int $actorId,
        public string|int $targetId,
    ) {}

    public static function record(string $operation, Authenticatable $actor, Authenticatable $target): self
    {
        return new self($operation, $actor->getAuthIdentifier(), $target->getAuthIdentifier());
    }
}
