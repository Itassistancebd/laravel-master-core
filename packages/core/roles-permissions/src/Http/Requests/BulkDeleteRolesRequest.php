<?php

namespace MasterCore\RolesPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BulkDeleteRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['required', 'array', 'min:1', 'max:100'],
            'role_ids.*' => ['required', 'integer', 'min:1', 'distinct', Rule::exists('master_core_roles', 'id')],
        ];
    }
}
