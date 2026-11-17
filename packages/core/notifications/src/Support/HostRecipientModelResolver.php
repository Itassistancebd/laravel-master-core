<?php

namespace MasterCore\Notifications\Support;

use Illuminate\Database\Eloquent\Model;

final class HostRecipientModelResolver
{
    public function modelClass(): ?string
    {
        $guard = (string) config('auth.defaults.guard', 'web');
        $provider = (string) (config("auth.guards.{$guard}.provider") ?: 'users');
        $model = config("auth.providers.{$provider}.model");

        return is_string($model) && class_exists($model) && is_subclass_of($model, Model::class)
            ? $model
            : null;
    }

    public function supports(Model $recipient): bool
    {
        $model = $this->modelClass();

        return $model !== null && $recipient instanceof $model;
    }
}
