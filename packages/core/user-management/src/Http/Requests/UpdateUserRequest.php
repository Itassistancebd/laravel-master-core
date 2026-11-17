<?php

namespace MasterCore\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use MasterCore\UserManagement\Support\UserModelResolver;

final class UpdateUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(UserModelResolver $models): array
    {
        $model = $models->newQuery()->getModel();
        $target = $models->newQuery()->findOrFail($this->route('user'));

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'string', 'email:rfc', 'max:255',
                Rule::unique($model->getTable(), 'email')->ignore($target->getKey(), $target->getKeyName()),
            ],
        ];
    }
}
