<?php

namespace MasterCore\UserManagement\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

final class UserModelResolver
{
    /** Resolve the configured host Eloquent model without introducing a Core user model. */
    public function modelClass(): string
    {
        $guard = (string) config('auth.defaults.guard', 'web');
        $provider = (string) (config("auth.guards.{$guard}.provider") ?: 'users');
        $class = config('master-core-user-management.user_model')
            ?: config("auth.providers.{$provider}.model")
            ?: config('auth.providers.users.model');

        if (! is_string($class) || $class === '') {
            throw new InvalidArgumentException("User Management could not resolve the configured user model for guard '{$guard}' and provider '{$provider}'.");
        }
        if (! class_exists($class)) {
            throw new InvalidArgumentException("The configured User Management model '{$class}' could not be loaded.");
        }
        if (! is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException("The configured User Management model '{$class}' must extend Eloquent Model.");
        }

        return $class;
    }

    public function newQuery(): Builder
    {
        $class = $this->modelClass();

        return $class::query();
    }

    public function supportsSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
