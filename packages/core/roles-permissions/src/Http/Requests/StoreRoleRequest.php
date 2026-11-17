<?php

namespace MasterCore\RolesPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $guard = app(HostUserModelResolver::class)->guardName();

        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('master_core_roles', 'name')->where('guard_name', $guard)],
        ];
    }
}
