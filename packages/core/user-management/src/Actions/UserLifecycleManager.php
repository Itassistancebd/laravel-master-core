<?php

namespace MasterCore\UserManagement\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MasterCore\UserManagement\Contracts\UserManagementSafetyPolicy;
use MasterCore\UserManagement\Events\UserManagementAction as UserManagementEvent;
use MasterCore\UserManagement\Support\UserModelResolver;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class UserLifecycleManager
{
    public function __construct(
        private readonly UserModelResolver $models,
        private readonly UserManagementSafetyPolicy $safety,
    ) {}

    public function create(array $attributes): Model
    {
        $class = $this->models->modelClass();
        $user = new $class;
        DB::transaction(function () use ($attributes, $user): void {
            $user->forceFill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make(Str::random(64)),
            ])->save();
            $this->audit('user.created', request()->user(), $user, ['changed_fields' => ['name', 'email']]);
        });

        Password::broker()->sendResetLink(['email' => $user->getAttribute('email')]);
        $this->record('created', request()->user(), $user);

        return $user;
    }

    /** Create an imported user without sending notifications or assigning roles. */
    public function createImported(array $attributes, Authenticatable $actor): Model
    {
        $class = $this->models->modelClass();
        $user = new $class;
        DB::transaction(function () use ($attributes, $user, $actor): void {
            $user->forceFill([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make(Str::random(64)),
                'is_active' => false,
            ])->save();
            $this->audit('user.created', $actor, $user, ['changed_fields' => ['name', 'email', 'is_active']]);
        });
        $this->record('created', $actor, $user);

        return $user;
    }

    public function update(Model $user, array $attributes, Authenticatable $actor): Model
    {
        $fields = array_values(array_intersect(array_keys($attributes), ['name', 'email']));
        DB::transaction(function () use ($user, $attributes, $fields, $actor): void {
            $user->forceFill(array_intersect_key($attributes, array_flip(['name', 'email'])))->save();
            $this->audit('user.updated', $actor, $user, ['changed_fields' => $fields]);
        });
        $this->record('updated', $actor, $user);

        return $user->refresh();
    }

    public function changeStatus(Model $user, bool $active, Authenticatable $actor): Model
    {
        DB::transaction(function () use ($user, $active, $actor): void {
            if (! $active) {
                $this->assertSafe($actor, $user, 'deactivate');
            }
            $user->forceFill(['is_active' => $active])->save();
            if (! $active) {
                $this->invalidateSessionsAndResetTokens($user);
            }
            $this->audit($active ? 'user.activated' : 'user.deactivated', $actor, $user, [], ['is_active' => ! $active], ['is_active' => $active]);
        });

        $this->record($active ? 'activated' : 'deactivated', $actor, $user);

        return $user->refresh();
    }

    public function trash(Model $user, Authenticatable $actor): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $this->assertSafe($actor, $user, 'delete');
            $user->delete();
            $this->invalidateSessionsAndResetTokens($user);
            $this->audit('user.trashed', $actor, $user);
        });
        $this->record('trashed', $actor, $user);
    }

    public function restore(Model $user, Authenticatable $actor): Model
    {
        DB::transaction(function () use ($user, $actor): void {
            $user->restore();
            $this->audit('user.restored', $actor, $user);
        });
        $this->record('restored', $actor, $user);

        return $user->refresh();
    }

    public function forceDelete(Model $user, Authenticatable $actor): void
    {
        try {
            DB::transaction(function () use ($user, $actor): void {
                $this->assertSafe($actor, $user, 'force-delete');
                $this->invalidateSessionsAndResetTokens($user);
                $user->forceDelete();
                $this->audit('user.permanently_deleted', $actor, $user);
            });
        } catch (QueryException $exception) {
            throw new ConflictHttpException('The user has related host records and cannot be permanently deleted.', $exception);
        }
        $this->record('permanently-deleted', $actor, $user);
    }

    public function assertSafe(Authenticatable $actor, Model $target, string $operation): void
    {
        if ((string) $actor->getAuthIdentifier() === (string) $target->getKey()) {
            throw new AccessDeniedHttpException('You cannot perform this operation on your own account.');
        }

        if (! $this->safety->allows($actor, $target, $operation)) {
            throw new AccessDeniedHttpException('The host user-safety policy denied this operation.');
        }
    }

    public function invalidateSessionsAndResetTokens(Model $user): void
    {
        $identifier = (string) $user->getAuthIdentifier();
        $sessionTable = (string) config('session.table', 'sessions');
        if (config('session.driver') === 'database' && Schema::hasTable($sessionTable)) {
            DB::table($sessionTable)->where('user_id', $identifier)->delete();
        }

        $broker = config('auth.defaults.passwords', 'users');
        $tokenTable = (string) config("auth.passwords.{$broker}.table", 'password_reset_tokens');
        if (Schema::hasTable($tokenTable)) {
            DB::table($tokenTable)->where('email', $user->getAttribute('email'))->delete();
        }
    }

    public function recordAdministrativeAction(string $operation, Authenticatable $actor, Model $target): void
    {
        $eventName = match ($operation) {
            'password-reset-requested' => 'user.password_reset_requested',
            'verification-resend-requested' => 'user.verification_resent',
            default => null,
        };
        if ($eventName !== null) {
            DB::transaction(fn () => $this->audit($eventName, $actor, $target));
        }
        $this->record($operation, $actor, $target);
    }

    /** Emit only allowlisted identifiers/field names; secret values never enter the event envelope. */
    private function audit(string $name, mixed $actor, Model $target, array $metadata = [], array $before = [], array $after = []): void
    {
        event('master-core.audit.record.v1', [[
            'event_name' => $name,
            'actor' => $actor,
            'subject' => $target,
            'metadata' => $metadata,
            'before_values' => $before,
            'after_values' => $after,
        ]]);
    }

    private function record(string $operation, Authenticatable $actor, Authenticatable $target): void
    {
        $event = UserManagementEvent::record($operation, $actor, $target);
        DB::afterCommit(static fn () => event($event));
    }
}
