<?php

namespace Tests\Feature;

use Tests\TestCase;

final class CategoriesAbsentTest extends TestCase
{
    public function test_categories_api_routes_and_permissions_are_not_registered(): void
    {
        $this->getJson('/api/v1/core/categories')->assertNotFound();

        $routePaths = collect(app('router')->getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        self::assertSame([], array_values(array_filter(
            $routePaths,
            static fn (string $path): bool => preg_match('~(^|/)categories(?:/|$)~i', $path) === 1,
        )));

        $extensions = config('master-core-roles-permissions.extensions', []);
        self::assertSame([], array_intersect(
            ['categories.view', 'categories.create', 'categories.update', 'categories.delete'],
            array_keys($extensions),
        ));
        self::assertSame([], array_intersect(
            ['categories.view', 'categories.create', 'categories.update', 'categories.delete'],
            array_keys(config('master-core-roles-permissions.abilities', [])),
        ));
        self::assertSame([], config('master-core-crud-engine.resources', []));
        self::assertFileDoesNotExist(resource_path('js/admin/CategoriesPage.vue'));
        self::assertStringNotContainsString('categories', file_get_contents(resource_path('js/admin/routes.ts')));
    }
}
