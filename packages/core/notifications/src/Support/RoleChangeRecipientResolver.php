<?php

namespace MasterCore\Notifications\Support;

use Illuminate\Database\Eloquent\Model;
use MasterCore\RolesPermissions\Events\RolesPermissionsChanged;

final class RoleChangeRecipientResolver
{
    public function resolve(RolesPermissionsChanged $event): ?Model
    {
        $guard = (string) config('auth.defaults.guard', 'web');
        $provider = (string) (config("auth.guards.{$guard}.provider") ?: 'users');
        $modelClass = config("auth.providers.{$provider}.model");

        // Only the configured default-guard user can receive the approved role-change notice.
        if (! is_string($modelClass) || ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class) || $event->subjectType !== $modelClass) {
            return null;
        }

        $recipient = $modelClass::query()->find($event->subjectId);
        if (! $recipient instanceof Model
            || in_array($recipient->getAttribute('is_active'), [false, 0, '0'], true)
            || (method_exists($recipient, 'trashed') && $recipient->trashed())) {
            return null;
        }

        return $recipient;
    }
}
