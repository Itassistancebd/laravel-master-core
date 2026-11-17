<?php

namespace MasterCore\RolesPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SyncUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Empty role sets are valid and revoke every assignment from the target account.
            'role_ids' => ['present', 'array', 'max:100'],
            'role_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('master_core_roles', 'id')],
        ];
    }
}
