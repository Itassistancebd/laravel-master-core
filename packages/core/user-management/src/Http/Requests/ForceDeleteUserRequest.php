<?php

namespace MasterCore\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use MasterCore\UserManagement\Support\UserModelResolver;

final class ForceDeleteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['confirmation' => ['required', 'string', 'max:255']];
    }

    public function ensureMatches(UserModelResolver $models, string $id): void
    {
        $user = $models->newQuery()->withTrashed()->findOrFail($id);
        if (! hash_equals((string) $user->getAttribute('email'), (string) $this->validated('confirmation'))) {
            throw ValidationException::withMessages(['confirmation' => 'Enter the user email address to confirm permanent deletion.']);
        }
    }
}
