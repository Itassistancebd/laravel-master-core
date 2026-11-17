<?php

namespace MasterCore\CrudEngine\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MasterCore\CrudEngine\Contracts\CrudResource;
use MasterCore\CrudEngine\Data\CrudResourceDefinition;
use MasterCore\CrudEngine\Tests\Fixtures\Models\FixtureAccount;

/** Test-only resource proves registration without making a business package depend on the engine. */
final class FixtureAccountResource implements CrudResource
{
    public function definition(): CrudResourceDefinition
    {
        return new CrudResourceDefinition(
            key: 'fixture-accounts',
            defaultSort: 'created_at',
            searchableFields: ['name', 'email'],
            filters: ['active' => 'is_active'],
            sortableFields: ['name', 'email', 'created_at'],
        );
    }

    public function query(): Builder
    {
        return FixtureAccount::query();
    }

    public function serialize(Model $record): array
    {
        return [
            'id' => $record->getKey(),
            'name' => $record->getAttribute('name'),
            'email' => $record->getAttribute('email'),
            'is_active' => (bool) $record->getAttribute('is_active'),
            'created_at' => $record->getAttribute('created_at')?->toISOString(),
        ];
    }
}
