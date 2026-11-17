<?php

namespace MasterCore\ModuleManager\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IndexModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'readiness' => ['nullable', 'in:not_installed,dependency_missing,provider_not_loaded,runtime_not_verified'],
            'sort_by' => ['nullable', 'in:key,name,composer_package,readiness_status'],
            'sort_direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
