<?php

namespace App\Modules\Categories\Http\Controllers;

use App\Modules\Categories\Crud\CategoryCrudResource;
use App\Modules\Categories\Http\Requests\StoreCategoryRequest;
use App\Modules\Categories\Http\Requests\UpdateCategoryRequest;
use App\Modules\Categories\Models\Category;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use MasterCore\CrudEngine\Http\Responses\CrudResponse;
use MasterCore\CrudEngine\Query\CrudQueryBuilder;

final class CategoryController
{
    public function index(Request $request, CategoryCrudResource $resource, CrudQueryBuilder $queries)
    {
        $page = $queries->paginate($resource, $request->query());
        return CrudResponse::collection($page, $resource);
    }

    public function show(Category $category, CategoryCrudResource $resource)
    {
        return CrudResponse::item($resource->serialize($category));
    }

    public function store(StoreCategoryRequest $request, DatabaseManager $database, CategoryCrudResource $resource)
    {
        $result = $database->transaction(function () use ($request, $resource): array {
            $data = $request->validated();
            $slug = trim((string) ($data['slug'] ?? ''));
            $data['slug'] = $slug !== '' ? Str::slug($slug) : Str::slug($data['name']);
            $data['status'] ??= 'active';
            $category = Category::create($data);
            Event::dispatch('master-core.audit.record.v1', [['event_name' => 'category.created', 'subject' => $category, 'metadata' => ['changed_fields' => array_keys($data)]]]);
            return $resource->serialize($category);
        });
        return CrudResponse::item($result, 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category, DatabaseManager $database, CategoryCrudResource $resource)
    {
        $result = $database->transaction(function () use ($request, $category, $resource): array {
            $data = $request->validated();
            $data['slug'] = Str::slug($data['slug']);
            $category->fill($data)->save();
            Event::dispatch('master-core.audit.record.v1', [['event_name' => 'category.updated', 'subject' => $category, 'metadata' => ['changed_fields' => array_keys($data)]]]);
            return $resource->serialize($category->refresh());
        });
        return CrudResponse::item($result);
    }

    public function destroy(Category $category, DatabaseManager $database)
    {
        $database->transaction(function () use ($category): void {
            Event::dispatch('master-core.audit.record.v1', [['event_name' => 'category.deleted', 'subject' => $category]]);
            $category->delete();
        });
        return CrudResponse::message('Category deleted.');
    }
}
