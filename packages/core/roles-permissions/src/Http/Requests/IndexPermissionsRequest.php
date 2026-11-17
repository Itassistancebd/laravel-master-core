<?php

namespace MasterCore\RolesPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Accept legacy clients that submit blank query values, which normalize to null.
            'search' => ['sometimes', 'nullable', 'string', 'max:191'],
            'group' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort_by' => ['sometimes', Rule::in(['name', 'group_name'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
        ];
    }
}
