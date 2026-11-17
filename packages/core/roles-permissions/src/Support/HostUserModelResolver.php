<?php

namespace MasterCore\RolesPermissions\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class HostUserModelResolver
{
    public function __construct(private readonly ?string $configuredDefaultGuard = null) {}

    /** Resolve the Eloquent user model from Laravel's configured default guard. */
    public function modelClass(): string
    {
        $guard = $this->guardName();
        $provider = (string) (config("auth.guards.{$guard}.provider") ?: 'users');
        $class = config("auth.providers.{$provider}.model");

        if (! is_string($class) || $class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException("Roles & Permissions requires an Eloquent model configured for the default '{$guard}' guard.");
        }

        return $class;
    }

    public function guardName(): string
    {
        $defaultGuard = $this->configuredDefaultGuard ?: (string) config('auth.defaults.guard', 'web');
        $guard = (string) (config('master-core-roles-permissions.guard') ?: $defaultGuard);
        if ($guard !== $defaultGuard) {
            throw new InvalidArgumentException('Roles & Permissions v1 supports only Laravel\'s configured default authentication guard.');
        }

        return $guard;
    }

    public function query(): Builder
    {
        $class = $this->modelClass();

        return $class::query();
    }
}
