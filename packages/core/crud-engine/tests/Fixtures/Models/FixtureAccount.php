<?php

namespace MasterCore\CrudEngine\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class FixtureAccount extends Model
{
    use SoftDeletes;

    protected $table = 'phase9_crud_fixture_accounts';

    protected $fillable = ['name', 'email', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
