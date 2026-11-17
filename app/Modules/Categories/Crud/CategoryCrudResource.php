<?php

namespace App\Modules\Categories\Crud;

use App\Modules\Categories\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MasterCore\CrudEngine\Contracts\CrudResource;
use MasterCore\CrudEngine\Data\CrudResourceDefinition;

final class CategoryCrudResource implements CrudResource
{
    public function definition(): CrudResourceDefinition
    {
        return new CrudResourceDefinition('categories', 'name', ['name', 'slug'], ['status' => 'status'], ['name', 'slug', 'status', 'created_at', 'id']);
    }

    public function query(): Builder
    {
        return Category::query();
    }

    public function serialize(Model $record): array
    {
        return $record->only(['id', 'name', 'slug', 'description', 'status', 'created_at', 'updated_at']);
    }
}
