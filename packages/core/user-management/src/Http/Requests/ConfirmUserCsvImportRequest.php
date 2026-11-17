<?php

namespace MasterCore\UserManagement\Http\Requests;

use Illuminate\Validation\Validator;

final class ConfirmUserCsvImportRequest extends ImportUserCsvRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            'preview_token' => ['required', 'string', 'max:8192'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
    }
}
