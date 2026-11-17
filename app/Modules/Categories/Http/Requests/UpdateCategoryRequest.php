<?php

namespace App\Modules\Categories\Http\Requests;

use App\Modules\Categories\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('categories.update') ?? false; }

    public function rules(): array
    {
        $category = $this->route('category');
        abort_unless($category instanceof Category, 404);
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category->getKey())],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
