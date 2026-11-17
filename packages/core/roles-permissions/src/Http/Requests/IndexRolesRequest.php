<?php

namespace MasterCore\RolesPermissions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Laravel's ConvertEmptyStringsToNull middleware normalizes search= to null.
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'sort_by' => ['sometimes', Rule::in(['name', 'permissions_count', 'members_count', 'created_at'])],
            'sort_direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])],
        ];
    }
}
