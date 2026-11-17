<?php

namespace MasterCore\RolesPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SyncRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // An empty selection is valid and means revoke every grant from this ordinary role.
            'permission_ids' => ['present', 'array', 'max:500'],
            'permission_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('master_core_permissions', 'id')],
        ];
    }
}
