<?php

namespace MasterCore\RolesPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;
use MasterCore\RolesPermissions\Support\PermissionRegistry;

final class StorePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:191',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || app(PermissionRegistry::class)->definition($value) === null) {
                        $fail('The permission must be registered by Core or a host module before it can be materialized.');
                    }
                },
                Rule::unique('master_core_permissions', 'name')->where('guard_name', app(HostUserModelResolver::class)->guardName()),
            ],
        ];
    }
}
