<?php

namespace MasterCore\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive'],
            'email_verified' => ['sometimes', 'nullable', 'in:verified,unverified'],
            'sort_by' => ['sometimes', 'nullable', 'in:name,email,email_verified_at,is_active,created_at,deleted_at'],
            'sort_direction' => ['sometimes', 'nullable', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
        ];
    }
}
