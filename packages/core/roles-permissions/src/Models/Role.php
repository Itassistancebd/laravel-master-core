<?php

namespace MasterCore\RolesPermissions\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use MasterCore\RolesPermissions\Support\HostUserModelResolver;

final class Role extends Model
{
    protected $table = 'master_core_roles';

    protected $fillable = ['name'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'master_core_role_has_permissions',
            'role_id',
            'permission_id',
        );
    }

    /** Resolve assigned host models through Laravel's configured default guard. */
    public function hostUsers(): MorphToMany
    {
        return $this->morphedByMany(
            app(HostUserModelResolver::class)->modelClass(),
            'model',
            'master_core_model_has_roles',
            'role_id',
            'model_id',
        );
    }
}
