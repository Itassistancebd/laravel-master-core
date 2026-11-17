<?php

namespace MasterCore\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImportUserCsvRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:2048']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('file');
            if ($file !== null && strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $validator->errors()->add('file', 'The uploaded file must use the .csv extension.');
            }
        });
    }
}
