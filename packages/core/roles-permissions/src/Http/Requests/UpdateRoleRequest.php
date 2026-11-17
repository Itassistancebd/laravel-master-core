<?php

namespace MasterCore\RolesPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use MasterCore\RolesPermissions\Models\Role;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;

final class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $guard = app(HostUserModelResolver::class)->guardName();
        $role = $this->route('role');
        $id = $role instanceof Role ? $role->getKey() : $role;

        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('master_core_roles', 'name')->where('guard_name', $guard)->ignore($id),
            ],
        ];
    }
}
