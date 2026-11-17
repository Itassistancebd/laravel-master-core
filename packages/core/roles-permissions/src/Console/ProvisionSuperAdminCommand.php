<?php

namespace MasterCore\RolesPermissions\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use MasterCore\RolesPermissions\Actions\SuperAdminProvisioner;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class ProvisionSuperAdminCommand extends Command
{
    protected $signature = 'master-core:roles:provision-super-admin {user : Existing host user ID or email}';

    protected $description = 'Interactively assign the protected Core Super Admin role to one existing active host user.';

    public function handle(HostUserModelResolver $models, SuperAdminProvisioner $provisioner): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Super Admin provisioning requires an interactive console.');

            return self::FAILURE;
        }

        $target = $this->findActiveTarget($models, (string) $this->argument('user'));
        if ($target === null) {
            $this->error('No existing active, non-trashed host user matched the supplied identifier.');

            return self::FAILURE;
        }

        $this->line('Target: '.$target->getAttribute('name').' ('.$target->getAttribute('email').'), ID '.$target->getKey());
        $expected = 'ASSIGN core-super-admin TO '.$target->getKey();
        if ($this->ask('Type "'.$expected.'" to continue') !== $expected) {
            $this->warn('Confirmation did not match. No role was assigned.');

            return self::FAILURE;
        }

        try {
            // Use the ID confirmed in the prompt; the provisioner re-fetches and locks its current row.
            $provisioner->provision((string) $target->getKey());
        } catch (AccessDeniedHttpException|ModelNotFoundException $exception) {
            $this->error($exception instanceof ModelNotFoundException
                ? 'The target user no longer exists.'
                : $exception->getMessage());

            return self::FAILURE;
        }

        $this->info('The protected Super Admin role is assigned. No other user or permission grants were changed.');

        return self::SUCCESS;
    }

    private function findActiveTarget(HostUserModelResolver $models, string $identifier): ?Model
    {
        $query = $models->query();
        if (method_exists($query->getModel(), 'trashed')) {
            $query->withTrashed();
        }

        $target = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? $query->where('email', $identifier)->first()
            : $query->find($identifier);

        if ($target === null || (method_exists($target, 'trashed') && $target->trashed())) {
            return null;
        }

        return in_array($target->getAttribute('is_active'), [true, 1, '1'], true) ? $target : null;
    }
}
