<?php

namespace MasterCore\CrudEngine\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use MasterCore\CrudEngine\Data\CrudResourceDefinition;

/** A resource owner supplies its own scoped query and safe output representation. */
interface CrudResource
{
    public function definition(): CrudResourceDefinition;

    /** Return the resource owner's already scoped Eloquent query. */
    public function query(): Builder;

    /** Serialize only fields intentionally exposed by the owning resource. */
    public function serialize(Model $record): array;
}
