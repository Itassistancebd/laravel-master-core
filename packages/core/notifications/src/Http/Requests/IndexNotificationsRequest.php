<?php

namespace MasterCore\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class IndexNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:all,unread,read'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff($this->query->keys(), ['status', 'per_page', 'page']) as $unknown) {
                $validator->errors()->add($unknown, 'This query parameter is not supported.');
            }
        });
    }
}
