<?php

namespace MasterCore\UserManagement\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class BulkUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => [
                'required',
                'distinct',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) && (! is_string($value) || mb_strlen($value) > 255)) {
                        $fail('Each selected user identifier must be a short string or integer.');
                    }
                },
            ],
        ];
    }
}
